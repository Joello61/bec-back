<?php

declare(strict_types=1);

namespace App\Service;

use App\DTO\SendMessageDTO;
use App\Entity\Message;
use App\Entity\User;
use App\Repository\MessageRepository;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

readonly class MessageService
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private MessageRepository $messageRepository,
        private UserRepository $userRepository,
        private NotificationService $notificationService
    ) {}

    public function sendMessage(SendMessageDTO $dto, User $expediteur): Message
    {
        $destinataire = $this->userRepository->find($dto->destinataireId);

        if (!$destinataire) {
            throw new NotFoundHttpException('Destinataire non trouvé');
        }

        if ($destinataire === $expediteur) {
            throw new BadRequestHttpException('Vous ne pouvez pas vous envoyer un message à vous-même');
        }

        // ==================== VÉRIFIER LES PARAMÈTRES DE CONFIDENTIALITÉ ====================
        $settings = $destinataire->getSettings();

        if ($settings && !$settings->canReceiveMessageFrom($expediteur)) {
            throw new BadRequestHttpException(
                'Cet utilisateur n\'accepte pas les messages de votre part. ' .
                'Vérifiez que vous êtes un utilisateur vérifié si requis.'
            );
        }

        $message = new Message();
        $message->setExpediteur($expediteur)
            ->setDestinataire($destinataire)
            ->setContenu($dto->contenu)
            ->setLu(false);

        $this->entityManager->persist($message);
        $this->entityManager->flush();

        // Notifier le destinataire (si ses préférences le permettent)
        $this->notificationService->notifyNewMessage($message);

        return $message;
    }

    public function getConversation(int $userId1, int $userId2): array
    {
        return $this->messageRepository->findConversation($userId1, $userId2);
    }

    public function getConversationsList(int $userId): array
    {
        return $this->messageRepository->findConversationsList($userId);
    }

    public function markAsRead(int $userId, int $otherUserId): void
    {
        $this->messageRepository->markAsRead($userId, $otherUserId);
    }

    public function countUnread(int $userId): int
    {
        return $this->messageRepository->countUnread($userId);
    }

    public function deleteMessage(int $id): void
    {
        $message = $this->messageRepository->find($id);

        if (!$message) {
            throw new NotFoundHttpException('Message non trouvé');
        }

        $this->entityManager->remove($message);
        $this->entityManager->flush();
    }
}
