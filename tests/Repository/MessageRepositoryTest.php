<?php

declare(strict_types=1);

namespace App\Tests\Repository;

use App\Entity\Conversation;
use App\Entity\Message;
use App\Entity\User;
use App\Repository\MessageRepository;
use App\Tests\Support\UserFactoryTrait;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Phase 4b, Lot 11 (bec-docs/docs/plan-correction/plan-correction-cobage.md) : couverture
 * repository dediee de MessageRepository, notamment markConversationAsRead (UPDATE en
 * masse, ne doit affecter que les messages adresses au bon destinataire) et
 * findRecentMessagesInConversation (inversion d'ordre apres tri DESC).
 */
class MessageRepositoryTest extends KernelTestCase
{
    use UserFactoryTrait;

    private EntityManagerInterface $em;
    private MessageRepository $repository;

    protected function setUp(): void
    {
        self::ensureKernelShutdown();
        self::bootKernel();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->repository = static::getContainer()->get(MessageRepository::class);
    }

    private function conversation(User $a, User $b): Conversation
    {
        $conversation = new Conversation();
        $conversation->setParticipant1($a);
        $conversation->setParticipant2($b);
        $this->em->persist($conversation);
        $this->em->flush();

        return $conversation;
    }

    private function message(Conversation $conversation, User $expediteur, User $destinataire, bool $lu = false, string $contenu = 'Bonjour'): Message
    {
        $message = new Message();
        $message->setConversation($conversation);
        $message->setExpediteur($expediteur);
        $message->setDestinataire($destinataire);
        $message->setContenu($contenu);
        $message->setLu($lu);
        $this->em->persist($message);
        $this->em->flush();

        return $message;
    }

    // ==================== countUnreadInConversation ====================

    public function testCountUnreadInConversationOnlyCountsForTheGivenUser(): void
    {
        $a = $this->createUser('msgrepo-unreadconv-a');
        $b = $this->createUser('msgrepo-unreadconv-b');
        $conversation = $this->conversation($a, $b);
        $this->message($conversation, $a, $b, lu: false);
        $this->message($conversation, $b, $a, lu: false);

        self::assertSame(1, $this->repository->countUnreadInConversation($conversation, $b));
    }

    // ==================== countUnread ====================

    public function testCountUnreadCountsAcrossAllConversations(): void
    {
        $a = $this->createUser('msgrepo-countunread-a');
        $b = $this->createUser('msgrepo-countunread-b');
        $c = $this->createUser('msgrepo-countunread-c');
        $conv1 = $this->conversation($a, $b);
        $conv2 = $this->conversation($a, $c);
        $this->message($conv1, $b, $a, lu: false);
        $this->message($conv2, $c, $a, lu: false);
        $this->message($conv1, $b, $a, lu: true);

        self::assertSame(2, $this->repository->countUnread($a->getId()));
    }

    // ==================== markConversationAsRead ====================

    public function testMarkConversationAsReadOnlyAffectsMessagesAddressedToTheUser(): void
    {
        $a = $this->createUser('msgrepo-markread-a');
        $b = $this->createUser('msgrepo-markread-b');
        $conversation = $this->conversation($a, $b);
        $toA = $this->message($conversation, $b, $a, lu: false);
        $toB = $this->message($conversation, $a, $b, lu: false);

        $updated = $this->repository->markConversationAsRead($conversation, $a);
        $this->em->clear();

        self::assertSame(1, $updated);
        $refreshedToA = $this->em->getRepository(Message::class)->find($toA->getId());
        $refreshedToB = $this->em->getRepository(Message::class)->find($toB->getId());
        self::assertTrue($refreshedToA->isLu());
        self::assertFalse($refreshedToB->isLu());
    }

    // ==================== findLastMessageInConversation ====================

    public function testFindLastMessageInConversationReturnsTheMostRecentOne(): void
    {
        // createdAt est stocke avec une precision a la seconde (timestamp DBAL par
        // defaut) : un veritable ecart d'une seconde est necessaire pour un ordre non
        // ambigu entre les deux messages.
        $a = $this->createUser('msgrepo-lastmsg-a');
        $b = $this->createUser('msgrepo-lastmsg-b');
        $conversation = $this->conversation($a, $b);
        $this->message($conversation, $a, $b, contenu: 'Premier');
        sleep(1);
        $second = $this->message($conversation, $a, $b, contenu: 'Second');

        $last = $this->repository->findLastMessageInConversation($conversation);

        self::assertSame($second->getId(), $last->getId());
    }

    // ==================== findRecentMessagesInConversation ====================

    public function testFindRecentMessagesInConversationReturnsThemInChronologicalOrder(): void
    {
        $a = $this->createUser('msgrepo-recent-a');
        $b = $this->createUser('msgrepo-recent-b');
        $conversation = $this->conversation($a, $b);
        $first = $this->message($conversation, $a, $b, contenu: 'Premier');
        sleep(1);
        $second = $this->message($conversation, $a, $b, contenu: 'Second');

        $result = $this->repository->findRecentMessagesInConversation($conversation, 10);

        $ids = array_map(fn (Message $m) => $m->getId(), $result);
        self::assertSame([$first->getId(), $second->getId()], $ids);
    }

    // ==================== searchInConversation ====================

    public function testSearchInConversationFindsMatchingContent(): void
    {
        $a = $this->createUser('msgrepo-search-a');
        $b = $this->createUser('msgrepo-search-b');
        $conversation = $this->conversation($a, $b);
        $this->message($conversation, $a, $b, contenu: 'Le colis est arrive');
        $this->message($conversation, $a, $b, contenu: 'Bonjour tout le monde');

        $result = $this->repository->searchInConversation($conversation, 'colis');

        self::assertCount(1, $result);
        self::assertStringContainsString('colis', $result[0]->getContenu());
    }
}
