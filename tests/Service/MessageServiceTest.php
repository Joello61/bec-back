<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\DTO\SendMessageDTO;
use App\Entity\Conversation;
use App\Entity\Message;
use App\Entity\User;
use App\Entity\UserSettings;
use App\Repository\ConversationRepository;
use App\Repository\MessageRepository;
use App\Repository\UserRepository;
use App\Service\MessageService;
use App\Service\NotificationService;
use App\Service\RealtimeNotifier;
use App\Tests\Support\EntityIdTrait;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Phase 4b, Lot 4 (bec-docs/docs/plan-correction/plan-correction-cobage.md) : sendMessage()
 * est quasi identique a ConversationService::sendMessage() (les deux services sont
 * reellement utilises par des routes distinctes, verifie avant d'ecrire ce fichier -
 * MessageController vs ConversationController). deleteMessage() est propre a ce service
 * (aucun equivalent direct dans ConversationService).
 */
class MessageServiceTest extends TestCase
{
    use EntityIdTrait;

    private EntityManagerInterface&\PHPUnit\Framework\MockObject\MockObject $em;
    private MessageRepository&\PHPUnit\Framework\MockObject\MockObject $messageRepository;
    private UserRepository&\PHPUnit\Framework\MockObject\MockObject $userRepository;
    private ConversationRepository&\PHPUnit\Framework\MockObject\MockObject $conversationRepository;
    private NotificationService&\PHPUnit\Framework\MockObject\MockObject $notificationService;
    private RealtimeNotifier&\PHPUnit\Framework\MockObject\MockObject $notifier;
    private MessageService $service;

    protected function setUp(): void
    {
        $this->em = $this->createMock(EntityManagerInterface::class);
        $this->em->method('persist')->willReturnCallback(function ($entity): void {
            if (method_exists($entity, 'setCreatedAtValue')) {
                $entity->setCreatedAtValue();
            }
        });
        $this->messageRepository = $this->createMock(MessageRepository::class);
        $this->userRepository = $this->createMock(UserRepository::class);
        $this->conversationRepository = $this->createMock(ConversationRepository::class);
        $this->notificationService = $this->createMock(NotificationService::class);
        $this->notifier = $this->createMock(RealtimeNotifier::class);

        $this->service = new MessageService(
            $this->em,
            $this->messageRepository,
            $this->userRepository,
            $this->conversationRepository,
            $this->notificationService,
            $this->notifier,
            new NullLogger(),
        );
    }

    private function user(int $id): User
    {
        $user = new User();
        $user->setEmail('user-' . $id . '-' . uniqid() . '@example.test');
        $user->setNom('Nom');
        $user->setPrenom('Prenom');
        $user->setPassword('irrelevant');
        $this->setEntityId($user, $id);

        return $user;
    }

    private function sendDto(int $destinataireId, string $contenu = 'salut'): SendMessageDTO
    {
        $dto = new SendMessageDTO();
        $dto->destinataireId = $destinataireId;
        $dto->contenu = $contenu;

        return $dto;
    }

    // ==================== sendMessage ====================

    public function testSendMessageThrowsWhenRecipientNotFound(): void
    {
        $this->userRepository->method('find')->willReturn(null);

        $this->expectException(NotFoundHttpException::class);
        $this->service->sendMessage($this->sendDto(999), $this->user(1));
    }

    public function testSendMessageRejectsSendingToOneself(): void
    {
        $user = $this->user(1);
        $this->userRepository->method('find')->willReturn($user);

        $this->expectException(BadRequestHttpException::class);
        $this->service->sendMessage($this->sendDto(1), $user);
    }

    public function testSendMessageRejectsWhenRecipientPrivacySettingsRefuse(): void
    {
        $expediteur = $this->user(1);
        $destinataire = $this->user(2);
        $settings = $this->createMock(UserSettings::class);
        $settings->method('canReceiveMessageFrom')->willReturn(false);
        $destinataire->setSettings($settings);
        $this->userRepository->method('find')->willReturn($destinataire);

        $this->expectException(BadRequestHttpException::class);
        $this->service->sendMessage($this->sendDto(2), $expediteur);
    }

    public function testSendMessageSucceedsAndNotifiesBothParties(): void
    {
        $expediteur = $this->user(1);
        $destinataire = $this->user(2);
        $this->userRepository->method('find')->willReturn($destinataire);
        $conversation = new Conversation();
        $conversation->setParticipant1($expediteur);
        $conversation->setParticipant2($destinataire);
        $this->setEntityId($conversation, 1);
        $this->conversationRepository->method('findOrCreateBetweenUsers')->willReturn($conversation);
        $this->notificationService->expects(self::once())->method('notifyNewMessage');
        $this->notifier->expects(self::exactly(2))->method('publishToUser');

        $message = $this->service->sendMessage($this->sendDto(2, 'salut toi'), $expediteur);

        self::assertSame('salut toi', $message->getContenu());
    }

    // ==================== countUnread ====================

    public function testCountUnreadDelegatesToTheRepository(): void
    {
        $this->messageRepository->method('countUnread')->with(1)->willReturn(4);

        self::assertSame(4, $this->service->countUnread(1));
    }

    // ==================== deleteMessage ====================

    public function testDeleteMessageThrowsWhenNotFound(): void
    {
        $this->messageRepository->method('find')->willReturn(null);

        $this->expectException(NotFoundHttpException::class);
        $this->service->deleteMessage(999, $this->user(1));
    }

    public function testDeleteMessageRejectsAThirdParty(): void
    {
        $sender = $this->user(1);
        $recipient = $this->user(2);
        $stranger = $this->user(3);
        $conversation = new Conversation();
        $conversation->setParticipant1($sender);
        $conversation->setParticipant2($recipient);
        $message = new Message();
        $message->setConversation($conversation);
        $message->setExpediteur($sender);
        $message->setDestinataire($recipient);
        $message->setContenu('salut');
        $this->messageRepository->method('find')->willReturn($message);
        $this->em->expects(self::never())->method('remove');

        $this->expectException(BadRequestHttpException::class);
        $this->service->deleteMessage(1, $stranger);
    }

    public function testDeleteMessageSucceedsForTheSender(): void
    {
        $sender = $this->user(1);
        $recipient = $this->user(2);
        $conversation = new Conversation();
        $conversation->setParticipant1($sender);
        $conversation->setParticipant2($recipient);
        $message = new Message();
        $message->setConversation($conversation);
        $message->setExpediteur($sender);
        $message->setDestinataire($recipient);
        $message->setContenu('salut');
        $this->messageRepository->method('find')->willReturn($message);
        $this->em->expects(self::once())->method('remove')->with($message);
        $this->notifier->expects(self::exactly(2))->method('publishToUser');

        $this->service->deleteMessage(1, $sender);
    }

    public function testDeleteMessageSucceedsForTheRecipient(): void
    {
        $sender = $this->user(1);
        $recipient = $this->user(2);
        $conversation = new Conversation();
        $conversation->setParticipant1($sender);
        $conversation->setParticipant2($recipient);
        $message = new Message();
        $message->setConversation($conversation);
        $message->setExpediteur($sender);
        $message->setDestinataire($recipient);
        $message->setContenu('salut');
        $this->messageRepository->method('find')->willReturn($message);
        $this->em->expects(self::once())->method('remove')->with($message);

        $this->service->deleteMessage(1, $recipient);
    }
}
