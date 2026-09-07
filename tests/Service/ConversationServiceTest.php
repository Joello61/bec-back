<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\DTO\SendMessageDTO;
use App\Entity\Conversation;
use App\Entity\User;
use App\Entity\UserSettings;
use App\Repository\ConversationRepository;
use App\Repository\MessageRepository;
use App\Repository\UserRepository;
use App\Service\ConversationService;
use App\Service\NotificationService;
use App\Service\RealtimeNotifier;
use App\Tests\Support\EntityIdTrait;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Phase 4b, Lot 4 (bec-docs/docs/plan-correction/plan-correction-cobage.md) : verifie le
 * respect des preferences de confidentialite (canReceiveMessageFrom), l'interdiction
 * d'auto-message/auto-conversation, et le controle d'appartenance systematique sur toute
 * conversation (pas de Voter dedie ici, controle fait a la main via hasParticipant()).
 */
class ConversationServiceTest extends TestCase
{
    use EntityIdTrait;

    private EntityManagerInterface&\PHPUnit\Framework\MockObject\MockObject $em;
    private ConversationRepository&\PHPUnit\Framework\MockObject\MockObject $conversationRepository;
    private MessageRepository&\PHPUnit\Framework\MockObject\MockObject $messageRepository;
    private UserRepository&\PHPUnit\Framework\MockObject\MockObject $userRepository;
    private NotificationService&\PHPUnit\Framework\MockObject\MockObject $notificationService;
    private RealtimeNotifier&\PHPUnit\Framework\MockObject\MockObject $notifier;
    private ConversationService $service;

    protected function setUp(): void
    {
        $this->em = $this->createMock(EntityManagerInterface::class);
        $this->em->method('persist')->willReturnCallback(function ($entity): void {
            if (method_exists($entity, 'setCreatedAtValue')) {
                $entity->setCreatedAtValue();
            }
        });
        $this->conversationRepository = $this->createMock(ConversationRepository::class);
        $this->messageRepository = $this->createMock(MessageRepository::class);
        $this->userRepository = $this->createMock(UserRepository::class);
        $this->notificationService = $this->createMock(NotificationService::class);
        $this->notifier = $this->createMock(RealtimeNotifier::class);

        $this->service = new ConversationService(
            $this->em,
            $this->conversationRepository,
            $this->messageRepository,
            $this->userRepository,
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

    private function conversation(User $p1, User $p2): Conversation
    {
        $conversation = new Conversation();
        $conversation->setParticipant1($p1);
        $conversation->setParticipant2($p2);
        $this->setEntityId($conversation, 1);

        return $conversation;
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
        $conversation = $this->conversation($expediteur, $destinataire);
        $this->conversationRepository->method('findOrCreateBetweenUsers')->willReturn($conversation);
        $this->notificationService->expects(self::once())->method('notifyNewMessage');
        $this->notifier->expects(self::exactly(2))->method('publishToUser');

        $message = $this->service->sendMessage($this->sendDto(2, 'salut toi'), $expediteur);

        self::assertSame('salut toi', $message->getContenu());
        self::assertFalse($message->isLu());
    }

    // ==================== getConversation ====================

    public function testGetConversationThrowsWhenNotFound(): void
    {
        $this->conversationRepository->method('findOneWithMessages')->willReturn(null);

        $this->expectException(NotFoundHttpException::class);
        $this->service->getConversation(999, $this->user(1));
    }

    public function testGetConversationRejectsANonParticipant(): void
    {
        $p1 = $this->user(1);
        $p2 = $this->user(2);
        $stranger = $this->user(3);
        $this->conversationRepository->method('findOneWithMessages')->willReturn($this->conversation($p1, $p2));

        $this->expectException(AccessDeniedHttpException::class);
        $this->service->getConversation(1, $stranger);
    }

    public function testGetConversationReturnsItForAParticipant(): void
    {
        $p1 = $this->user(1);
        $p2 = $this->user(2);
        $conversation = $this->conversation($p1, $p2);
        $this->conversationRepository->method('findOneWithMessages')->willReturn($conversation);

        self::assertSame($conversation, $this->service->getConversation(1, $p1));
    }

    // ==================== getOrCreateConversationWithUser ====================

    public function testGetOrCreateConversationRejectsSelfConversation(): void
    {
        $user = $this->user(1);

        $this->expectException(BadRequestHttpException::class);
        $this->service->getOrCreateConversationWithUser(1, $user);
    }

    public function testGetOrCreateConversationThrowsWhenOtherUserNotFound(): void
    {
        $this->userRepository->method('find')->willReturn(null);

        $this->expectException(NotFoundHttpException::class);
        $this->service->getOrCreateConversationWithUser(999, $this->user(1));
    }

    public function testGetOrCreateConversationDelegatesToTheRepository(): void
    {
        $user = $this->user(1);
        $other = $this->user(2);
        $this->userRepository->method('find')->willReturn($other);
        $conversation = $this->conversation($user, $other);
        $this->conversationRepository->method('findOrCreateBetweenUsers')->with($user, $other)->willReturn($conversation);

        self::assertSame($conversation, $this->service->getOrCreateConversationWithUser(2, $user));
    }

    // ==================== markConversationAsRead ====================

    public function testMarkConversationAsReadThrowsWhenNotFound(): void
    {
        $this->conversationRepository->method('find')->willReturn(null);

        $this->expectException(NotFoundHttpException::class);
        $this->service->markConversationAsRead(999, $this->user(1));
    }

    public function testMarkConversationAsReadRejectsANonParticipant(): void
    {
        $p1 = $this->user(1);
        $p2 = $this->user(2);
        $stranger = $this->user(3);
        $this->conversationRepository->method('find')->willReturn($this->conversation($p1, $p2));

        $this->expectException(AccessDeniedHttpException::class);
        $this->service->markConversationAsRead(1, $stranger);
    }

    public function testMarkConversationAsReadNotifiesBothParticipants(): void
    {
        $p1 = $this->user(1);
        $p2 = $this->user(2);
        $conversation = $this->conversation($p1, $p2);
        $this->conversationRepository->method('find')->willReturn($conversation);
        $this->messageRepository->method('markConversationAsRead')->willReturn(3);
        $this->notifier->expects(self::exactly(2))->method('publishToUser');

        $result = $this->service->markConversationAsRead(1, $p1);

        self::assertSame(3, $result);
    }

    // ==================== deleteConversation ====================

    public function testDeleteConversationThrowsWhenNotFound(): void
    {
        $this->conversationRepository->method('find')->willReturn(null);

        $this->expectException(NotFoundHttpException::class);
        $this->service->deleteConversation(999, $this->user(1));
    }

    public function testDeleteConversationRejectsANonParticipant(): void
    {
        $p1 = $this->user(1);
        $p2 = $this->user(2);
        $stranger = $this->user(3);
        $this->conversationRepository->method('find')->willReturn($this->conversation($p1, $p2));
        $this->em->expects(self::never())->method('remove');

        $this->expectException(AccessDeniedHttpException::class);
        $this->service->deleteConversation(1, $stranger);
    }

    public function testDeleteConversationRemovesAndNotifiesBothParticipants(): void
    {
        $p1 = $this->user(1);
        $p2 = $this->user(2);
        $conversation = $this->conversation($p1, $p2);
        $this->conversationRepository->method('find')->willReturn($conversation);
        $this->em->expects(self::once())->method('remove')->with($conversation);
        $this->em->expects(self::once())->method('flush');
        $this->notifier->expects(self::exactly(2))->method('publishToUser');

        $this->service->deleteConversation(1, $p1);
    }

    // ==================== countUnreadMessages ====================

    public function testCountUnreadMessagesDelegatesToTheRepository(): void
    {
        $user = $this->user(1);
        $this->conversationRepository->method('countTotalUnreadMessagesForUser')->with($user)->willReturn(5);

        self::assertSame(5, $this->service->countUnreadMessages($user));
    }
}
