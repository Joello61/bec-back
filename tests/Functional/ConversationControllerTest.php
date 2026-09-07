<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\Conversation;
use App\Entity\Message;
use App\Entity\User;
use App\Tests\Support\JwtAuthenticationTrait;
use App\Tests\Support\UserFactoryTrait;
use Doctrine\ORM\EntityManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Phase 4b, Lot 8 (bec-docs/docs/plan-correction/plan-correction-cobage.md) : integration
 * HTTP de ConversationController - le controle d'appartenance est fait a la main dans
 * ConversationService (pas de Voter dedie, coherent avec le style existant), pas encore
 * unitairement teste ailleurs : couvert directement ici au niveau HTTP.
 */
class ConversationControllerTest extends WebTestCase
{
    use UserFactoryTrait;
    use JwtAuthenticationTrait;

    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private JWTTokenManagerInterface $jwtManager;

    protected function setUp(): void
    {
        self::ensureKernelShutdown();
        $this->client = static::createClient();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->jwtManager = static::getContainer()->get('lexik_jwt_authentication.jwt_manager');
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

    private function message(Conversation $conversation, User $expediteur, User $destinataire): Message
    {
        $message = new Message();
        $message->setConversation($conversation);
        $message->setExpediteur($expediteur);
        $message->setDestinataire($destinataire);
        $message->setContenu('Bonjour');
        $this->em->persist($message);
        $this->em->flush();

        return $message;
    }

    // ==================== list ====================

    public function testListRequiresAuthentication(): void
    {
        $this->client->request('GET', '/api/conversations');

        self::assertResponseStatusCodeSame(403);
    }

    public function testListReturnsOnlyTheCallersConversations(): void
    {
        $a = $this->createUser('conv-list-a');
        $b = $this->createUser('conv-list-b');
        $stranger = $this->createUser('conv-list-stranger');
        $this->conversation($a, $b);
        $this->authenticateAs($stranger);

        $this->client->request('GET', '/api/conversations');

        self::assertResponseIsSuccessful();
        $payload = json_decode($this->client->getResponse()->getContent(), true);
        self::assertSame([], $payload);
    }

    // ==================== withUser ====================

    public function testWithUserRejectsConversationWithSelf(): void
    {
        $user = $this->createUser('conv-with-self');
        $this->authenticateAs($user);

        $this->client->request('GET', '/api/conversations/with/' . $user->getId());

        self::assertResponseStatusCodeSame(400);
    }

    public function testWithUserReturns404ForAnUnknownUser(): void
    {
        $this->authenticateAs($this->createUser('conv-with-unknown'));

        $this->client->request('GET', '/api/conversations/with/999999');

        self::assertResponseStatusCodeSame(404);
    }

    public function testWithUserCreatesAConversation(): void
    {
        $user = $this->createUser('conv-with-user');
        $other = $this->createUser('conv-with-other');
        $this->authenticateAs($user);

        $this->client->request('GET', '/api/conversations/with/' . $other->getId());

        self::assertResponseIsSuccessful();
        $payload = json_decode($this->client->getResponse()->getContent(), true);
        self::assertArrayHasKey('id', $payload);
    }

    // ==================== unread-count ====================

    public function testUnreadCountReturnsTheRightCount(): void
    {
        $a = $this->createUser('conv-unread-a');
        $b = $this->createUser('conv-unread-b');
        $conversation = $this->conversation($a, $b);
        $this->message($conversation, $a, $b);
        $this->authenticateAs($b);

        $this->client->request('GET', '/api/conversations/unread-count');

        self::assertResponseIsSuccessful();
        $payload = json_decode($this->client->getResponse()->getContent(), true);
        self::assertSame(1, $payload['count']);
    }

    // ==================== show ====================

    public function testShowReturns404ForAnUnknownConversation(): void
    {
        $this->authenticateAs($this->createUser('conv-show-404'));

        $this->client->request('GET', '/api/conversations/999999');

        self::assertResponseStatusCodeSame(404);
    }

    public function testShowRejectsANonParticipant(): void
    {
        $a = $this->createUser('conv-show-a');
        $b = $this->createUser('conv-show-b');
        $conversation = $this->conversation($a, $b);
        $this->authenticateAs($this->createUser('conv-show-stranger'));

        $this->client->request('GET', '/api/conversations/' . $conversation->getId());

        self::assertResponseStatusCodeSame(403);
    }

    public function testShowSucceedsForAParticipant(): void
    {
        $a = $this->createUser('conv-show-a-ok');
        $b = $this->createUser('conv-show-b-ok');
        $conversation = $this->conversation($a, $b);
        $this->authenticateAs($a);

        $this->client->request('GET', '/api/conversations/' . $conversation->getId());

        self::assertResponseIsSuccessful();
    }

    // ==================== markAsRead ====================

    public function testMarkAsReadRejectsANonParticipant(): void
    {
        $a = $this->createUser('conv-markread-a');
        $b = $this->createUser('conv-markread-b');
        $conversation = $this->conversation($a, $b);
        $this->authenticateAs($this->createUser('conv-markread-stranger'));

        $this->client->request('POST', '/api/conversations/' . $conversation->getId() . '/mark-read');

        self::assertResponseStatusCodeSame(403);
    }

    public function testMarkAsReadMarksTheParticipantsMessagesAsRead(): void
    {
        $a = $this->createUser('conv-markread-a-ok');
        $b = $this->createUser('conv-markread-b-ok');
        $conversation = $this->conversation($a, $b);
        $this->message($conversation, $a, $b);
        $this->authenticateAs($b);

        $this->client->request('POST', '/api/conversations/' . $conversation->getId() . '/mark-read');

        self::assertResponseIsSuccessful();
        $payload = json_decode($this->client->getResponse()->getContent(), true);
        self::assertSame(1, $payload['count']);
    }

    // ==================== delete ====================

    public function testDeleteRejectsANonParticipant(): void
    {
        $a = $this->createUser('conv-delete-a');
        $b = $this->createUser('conv-delete-b');
        $conversation = $this->conversation($a, $b);
        $this->authenticateAs($this->createUser('conv-delete-stranger'));

        $this->client->request('DELETE', '/api/conversations/' . $conversation->getId());

        self::assertResponseStatusCodeSame(403);
    }

    public function testDeleteSucceedsForAParticipant(): void
    {
        $a = $this->createUser('conv-delete-a-ok');
        $b = $this->createUser('conv-delete-b-ok');
        $conversation = $this->conversation($a, $b);
        $this->authenticateAs($a);

        $this->client->request('DELETE', '/api/conversations/' . $conversation->getId());

        self::assertResponseStatusCodeSame(204);
    }
}
