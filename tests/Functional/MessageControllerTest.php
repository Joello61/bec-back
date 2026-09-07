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
 * HTTP de MessageController - le detail de MessageVoter (profil complet pour envoyer, seul
 * l'expediteur/destinataire peut supprimer) est deja verifie unitairement (Phase 4).
 */
class MessageControllerTest extends WebTestCase
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

    // ==================== send ====================

    public function testSendRequiresAuthentication(): void
    {
        $this->client->request(
            'POST',
            '/api/messages',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode(['destinataireId' => 1, 'contenu' => 'Bonjour'])
        );

        self::assertResponseStatusCodeSame(403);
    }

    public function testSendRejectsAnIncompleteProfile(): void
    {
        $destinataire = $this->createUser('message-send-dest-incomplete');
        $this->authenticateAs($this->createUser('message-send-incomplete'));

        $this->client->request(
            'POST',
            '/api/messages',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode(['destinataireId' => $destinataire->getId(), 'contenu' => 'Bonjour'])
        );

        self::assertResponseStatusCodeSame(403);
    }

    public function testSendRejectsAnInvalidPayload(): void
    {
        $this->authenticateAs($this->createCompleteProfileUser('message-send-invalid'));

        $this->client->request(
            'POST',
            '/api/messages',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode(['destinataireId' => -1, 'contenu' => ''])
        );

        self::assertResponseStatusCodeSame(422);
    }

    public function testSendReturns404ForAnUnknownRecipient(): void
    {
        $this->authenticateAs($this->createCompleteProfileUser('message-send-unknown-dest'));

        $this->client->request(
            'POST',
            '/api/messages',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode(['destinataireId' => 999999, 'contenu' => 'Bonjour'])
        );

        self::assertResponseStatusCodeSame(404);
    }

    public function testSendSucceedsWithACompleteProfile(): void
    {
        $destinataire = $this->createUser('message-send-dest-ok');
        $this->authenticateAs($this->createCompleteProfileUser('message-send-ok'));

        $this->client->request(
            'POST',
            '/api/messages',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode(['destinataireId' => $destinataire->getId(), 'contenu' => 'Bonjour, comment allez-vous ?'])
        );

        self::assertResponseStatusCodeSame(201);
        $payload = json_decode($this->client->getResponse()->getContent(), true);
        self::assertSame('Bonjour, comment allez-vous ?', $payload['contenu']);
    }

    // ==================== unread-count ====================

    public function testUnreadCountReturnsTheRightCount(): void
    {
        $expediteur = $this->createUser('message-unreadcount-exp');
        $destinataire = $this->createUser('message-unreadcount-dest');
        $conversation = $this->conversation($expediteur, $destinataire);
        $this->message($conversation, $expediteur, $destinataire);
        $this->message($conversation, $expediteur, $destinataire);
        $this->authenticateAs($destinataire);

        $this->client->request('GET', '/api/messages/unread-count');

        self::assertResponseIsSuccessful();
        $payload = json_decode($this->client->getResponse()->getContent(), true);
        self::assertSame(2, $payload['count']);
    }

    // ==================== delete ====================

    public function testDeleteReturns404ForAnUnknownMessage(): void
    {
        $this->authenticateAs($this->createUser('message-delete-404'));

        $this->client->request('DELETE', '/api/messages/999999');

        self::assertResponseStatusCodeSame(404);
    }

    public function testDeleteRejectsAThirdPartyUser(): void
    {
        $expediteur = $this->createUser('message-delete-exp');
        $destinataire = $this->createUser('message-delete-dest');
        $conversation = $this->conversation($expediteur, $destinataire);
        $message = $this->message($conversation, $expediteur, $destinataire);
        $this->authenticateAs($this->createUser('message-delete-stranger'));

        $this->client->request('DELETE', '/api/messages/' . $message->getId());

        self::assertResponseStatusCodeSame(403);
    }

    public function testDeleteSucceedsForTheSender(): void
    {
        $expediteur = $this->createUser('message-delete-sender-ok');
        $destinataire = $this->createUser('message-delete-dest-ok');
        $conversation = $this->conversation($expediteur, $destinataire);
        $message = $this->message($conversation, $expediteur, $destinataire);
        $this->authenticateAs($expediteur);

        $this->client->request('DELETE', '/api/messages/' . $message->getId());

        self::assertResponseStatusCodeSame(204);
    }
}
