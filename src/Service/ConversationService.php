<?php

declare(strict_types=1);

namespace App\Service;

use App\DTO\SendMessageDTO;
use App\Entity\Conversation;
use App\Entity\Message;
use App\Entity\User;
use App\Repository\ConversationRepository;
use App\Repository\MessageRepository;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

readonly class ConversationService
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private ConversationRepository $conversationRepository,
        private MessageRepository $messageRepository,
        private UserRepository $userRepository,
        private NotificationService $notificationService
    ) {
    }

    /**
     * Envoie un message dans une conversation (créée si nécessaire)
     */
    public function sendMessage(SendMessageDTO $dto, User $expediteur): Message
    {
        $destinataire = $this->userRepository->find($dto->destinataireId);

        if (!$destinataire) {
            throw new NotFoundHttpException('Destinataire non trouvé');
        }

        if ($destinataire === $expediteur) {
            throw new BadRequestHttpException('Vous ne pouvez pas vous envoyer un message à vous-même');
        }

        // Vérifier les paramètres de confidentialité
        $settings = $destinataire->getSettings();
        if ($settings && !$settings->canReceiveMessageFrom($expediteur)) {
            throw new BadRequestHttpException(
                'Cet utilisateur n\'accepte pas les messages de votre part. ' .
                'Vérifiez que vous êtes un utilisateur vérifié si requis.'
            );
        }

        // Récupérer ou créer la conversation
        $conversation = $this->conversationRepository->findOrCreateBetweenUsers($expediteur, $destinataire);

        $message = new Message();
        $message->setConversation($conversation)
            ->setExpediteur($expediteur)
            ->setDestinataire($destinataire)
            ->setContenu($dto->contenu)
            ->setLu(false);

        $this->entityManager->persist($message);
        $this->entityManager->flush();

        // Notifier le destinataire
        $this->notificationService->notifyNewMessage($message);

        return $message;
    }

    /**
     * Récupère la liste des conversations de l'utilisateur
     */
    public function getConversationsList(User $user): array
    {
        return $this->conversationRepository->findByUserWithDetails($user);
    }

    /**
     * Récupère une conversation spécifique avec ses messages
     */
    public function getConversation(int $conversationId, User $user): Conversation
    {
        $conversation = $this->conversationRepository->findOneWithMessages($conversationId);

        if (!$conversation) {
            throw new NotFoundHttpException('Conversation non trouvée');
        }

        if (!$conversation->hasParticipant($user)) {
            throw new AccessDeniedHttpException('Vous n\'avez pas accès à cette conversation');
        }

        return $conversation;
    }

    /**
     * Récupère ou crée une conversation avec un utilisateur
     */
    public function getOrCreateConversationWithUser(int $userId, User $currentUser): Conversation
    {
        if ($userId === $currentUser->getId()) {
            throw new BadRequestHttpException('Vous ne pouvez pas avoir de conversation avec vous-même');
        }

        $otherUser = $this->userRepository->find($userId);

        if (!$otherUser) {
            throw new NotFoundHttpException('Utilisateur non trouvé');
        }

        return $this->conversationRepository->findOrCreateBetweenUsers($currentUser, $otherUser);
    }

    /**
     * Marque tous les messages d'une conversation comme lus
     */
    public function markConversationAsRead(int $conversationId, User $user): int
    {
        $conversation = $this->conversationRepository->find($conversationId);

        if (!$conversation) {
            throw new NotFoundHttpException('Conversation non trouvée');
        }

        if (!$conversation->hasParticipant($user)) {
            throw new AccessDeniedHttpException('Vous n\'avez pas accès à cette conversation');
        }

        return $this->messageRepository->markConversationAsRead($conversation, $user);
    }

    /**
     * Compte le nombre total de messages non lus
     */
    public function countUnreadMessages(User $user): int
    {
        return $this->conversationRepository->countTotalUnreadMessagesForUser($user);
    }

    /**
     * Supprime une conversation
     */
    public function deleteConversation(int $conversationId, User $user): void
    {
        $conversation = $this->conversationRepository->find($conversationId);

        if (!$conversation) {
            throw new NotFoundHttpException('Conversation non trouvée');
        }

        if (!$conversation->hasParticipant($user)) {
            throw new AccessDeniedHttpException('Vous n\'avez pas accès à cette conversation');
        }

        $this->entityManager->remove($conversation);
        $this->entityManager->flush();
    }
}
