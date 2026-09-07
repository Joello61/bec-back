<?php

declare(strict_types=1);

namespace App\Tests\Repository;

use App\Entity\Conversation;
use App\Entity\Message;
use App\Entity\User;
use App\Repository\ConversationRepository;
use App\Tests\Support\UserFactoryTrait;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Phase 4b, Lot 11 (bec-docs/docs/plan-correction/plan-correction-cobage.md) : couverture
 * repository dediee de ConversationRepository, notamment findOrCreateBetweenUsers
 * (logique find-or-create, symetrie participant1/participant2) et deleteEmptyConversations.
 */
class ConversationRepositoryTest extends KernelTestCase
{
    use UserFactoryTrait;

    private EntityManagerInterface $em;
    private ConversationRepository $repository;

    protected function setUp(): void
    {
        self::ensureKernelShutdown();
        self::bootKernel();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->repository = static::getContainer()->get(ConversationRepository::class);
    }

    private function message(Conversation $conversation, User $expediteur, User $destinataire, bool $lu = false): Message
    {
        $message = new Message();
        $message->setConversation($conversation);
        $message->setExpediteur($expediteur);
        $message->setDestinataire($destinataire);
        $message->setContenu('Bonjour');
        $message->setLu($lu);
        $this->em->persist($message);
        $this->em->flush();

        return $message;
    }

    // ==================== findBetweenUsers ====================

    public function testFindBetweenUsersIsSymmetric(): void
    {
        $a = $this->createUser('convrepo-symmetric-a');
        $b = $this->createUser('convrepo-symmetric-b');
        $conversation = new Conversation();
        $conversation->setParticipant1($a);
        $conversation->setParticipant2($b);
        $this->em->persist($conversation);
        $this->em->flush();

        $found1 = $this->repository->findBetweenUsers($a, $b);
        $found2 = $this->repository->findBetweenUsers($b, $a);

        self::assertNotNull($found1);
        self::assertSame($found1->getId(), $found2->getId());
    }

    // ==================== findOrCreateBetweenUsers ====================

    public function testFindOrCreateBetweenUsersReturnsTheExistingConversation(): void
    {
        $a = $this->createUser('convrepo-findorcreate-existing-a');
        $b = $this->createUser('convrepo-findorcreate-existing-b');
        $existing = new Conversation();
        $existing->setParticipant1($a);
        $existing->setParticipant2($b);
        $this->em->persist($existing);
        $this->em->flush();

        $result = $this->repository->findOrCreateBetweenUsers($a, $b);

        self::assertSame($existing->getId(), $result->getId());
    }

    public function testFindOrCreateBetweenUsersCreatesANewConversationWhenNoneExists(): void
    {
        $a = $this->createUser('convrepo-findorcreate-new-a');
        $b = $this->createUser('convrepo-findorcreate-new-b');

        $result = $this->repository->findOrCreateBetweenUsers($a, $b);

        self::assertNotNull($result->getId());
        self::assertNotNull($this->repository->findBetweenUsers($a, $b));
    }

    // ==================== countTotalUnreadMessagesForUser ====================

    public function testCountTotalUnreadMessagesForUserOnlyCountsMessagesAddressedToHim(): void
    {
        $a = $this->createUser('convrepo-countunread-a');
        $b = $this->createUser('convrepo-countunread-b');
        $conversation = new Conversation();
        $conversation->setParticipant1($a);
        $conversation->setParticipant2($b);
        $this->em->persist($conversation);
        $this->em->flush();
        $this->message($conversation, $a, $b, lu: false);
        $this->message($conversation, $b, $a, lu: false);
        $this->message($conversation, $b, $a, lu: true);

        $count = $this->repository->countTotalUnreadMessagesForUser($b);

        self::assertSame(1, $count);
    }

    // ==================== findOneWithMessages ====================

    public function testFindOneWithMessagesReturnsNullForAnUnknownId(): void
    {
        self::assertNull($this->repository->findOneWithMessages(999999));
    }

    public function testFindOneWithMessagesLoadsTheMessages(): void
    {
        $a = $this->createUser('convrepo-withmessages-a');
        $b = $this->createUser('convrepo-withmessages-b');
        $conversation = new Conversation();
        $conversation->setParticipant1($a);
        $conversation->setParticipant2($b);
        $this->em->persist($conversation);
        $this->em->flush();
        $this->message($conversation, $a, $b);
        $conversationId = $conversation->getId();

        // $conversation reste dans l'identity map avec sa collection messages telle que
        // construite (ArrayCollection vide) : elle n'est jamais mise a jour en memoire par
        // le persist() du message cote proprietaire (Message::conversation). Sans ce
        // clear(), le fetch-join de findOneWithMessages() renverrait ce meme objet en
        // memoire au lieu de le rehydrater depuis la base.
        $this->em->clear();

        $found = $this->repository->findOneWithMessages($conversationId);

        self::assertNotNull($found);
        self::assertCount(1, $found->getMessages());
    }

    // ==================== deleteEmptyConversations ====================

    public function testDeleteEmptyConversationsOnlyRemovesConversationsWithoutMessages(): void
    {
        $a = $this->createUser('convrepo-deleteempty-a');
        $b = $this->createUser('convrepo-deleteempty-b');
        $empty = new Conversation();
        $empty->setParticipant1($a);
        $empty->setParticipant2($b);
        $this->em->persist($empty);

        $c = $this->createUser('convrepo-deleteempty-c');
        $d = $this->createUser('convrepo-deleteempty-d');
        $nonEmpty = new Conversation();
        $nonEmpty->setParticipant1($c);
        $nonEmpty->setParticipant2($d);
        $this->em->persist($nonEmpty);
        $this->em->flush();
        $this->message($nonEmpty, $c, $d);

        $emptyId = $empty->getId();
        $nonEmptyId = $nonEmpty->getId();

        $this->repository->deleteEmptyConversations();
        $this->em->clear();

        self::assertNull($this->em->getRepository(Conversation::class)->find($emptyId));
        self::assertNotNull($this->em->getRepository(Conversation::class)->find($nonEmptyId));
    }
}
