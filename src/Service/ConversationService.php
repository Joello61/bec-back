<?php

declare(strict_types=1);

namespace App\Service;

use App\Constant\EventType;
use App\DTO\SendMessageDTO;
use App\Entity\Conversation;
use App\Entity\Message;
use App\Entity\User;
use App\Repository\ConversationRepository;
use App\Repository\MessageRepository;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
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
        private NotificationService $notificationService,
        private RealtimeNotifier $notifier,
        private LoggerInterface $logger,
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

        try {
            // Données communes
            $payload = [
                'title' => 'Nouveau message reçu',
                'message' => sprintf(
                    'Vous avez reçu un nouveau message de %s %s.',
                    $expediteur->getNom(),
                    $expediteur->getPrenom()
                ),
                'messageId' => $message->getId(),
                'contenu' => $message->getContenu(),
                'expediteurId' => $expediteur->getId(),
                'destinataireId' => $destinataire->getId(),
                'conversationId' => $conversation->getId(),
                'createdAt' => $message->getCreatedAt()->format('c'),
            ];

            // Notifier le destinataire (le vrai “récepteur”)
            $this->notifier->publishToUser(
                $destinataire,
                $payload,
                EventType::MESSAGE_SENT
            );

            // Notifier aussi l’expéditeur (pour synchro UI sur ses autres appareils)
            $this->notifier->publishToUser(
                $expediteur,
                $payload,
                EventType::MESSAGE_SENT
            );

        } catch (\JsonException $e) {
            $this->logger->error('Échec de la publication de MESSAGE_SENT', [
                'conversation_id' => $conversation->getId(),
                'error' => $e->getMessage(),
            ]);
        }

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
        /** @var Conversation $conversation */
        $conversation = $this->conversationRepository->find($conversationId);

        if (!$conversation) {
            throw new NotFoundHttpException('Conversation non trouvée');
        }

        if (!$conversation->hasParticipant($user)) {
            throw new AccessDeniedHttpException('Vous n\'avez pas accès à cette conversation');
        }

        $exec = $this->messageRepository->markConversationAsRead($conversation, $user);

        try {
            $payload = [
                'title' => 'Message lu',
                'message' => sprintf(
                    '%s %s a lu les derniers messages de votre conversation.',
                    $user->getNom(),
                    $user->getPrenom()
                ),
                'readerId' => $user->getId(),
                'conversationId' => $conversation->getId(),
            ];

            // On identifie les deux participants
            $participants = [$conversation->getParticipant1(), $conversation->getParticipant2()];

            foreach ($participants as $participant) {
                $this->notifier->publishToUser(
                    $participant,
                    $payload,
                    EventType::MESSAGE_READ
                );
            }

        } catch (\JsonException $e) {
            $this->logger->error('Échec de la publication de MESSAGE_READ', [
                'conversation_id' => $conversation->getId(),
                'error' => $e->getMessage(),
            ]);
        }



        return $exec;
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
        /** @var Conversation $conversation */
        $conversation = $this->conversationRepository->find($conversationId);

        if (!$conversation) {
            throw new NotFoundHttpException('Conversation non trouvée');
        }

        if (!$conversation->hasParticipant($user)) {
            throw new AccessDeniedHttpException('Vous n\'avez pas accès à cette conversation');
        }

        $conversationId = $conversation->getId();

        $this->entityManager->remove($conversation);
        $this->entityManager->flush();

        try {
            $payload = [
                'title' => 'Conversation supprimée',
                'message' => 'Cette conversation a été supprimée par un participant ou un administrateur.',
                'conversationId' => $conversationId,
            ];

            // On récupère les participants concernés (2 ou +)
            $participants = [$conversation->getParticipant1(), $conversation->getParticipant2()];;

            foreach ($participants as $participant) {
                $this->notifier->publishToUser(
                    $participant,
                    $payload,
                    EventType::CONVERSATION_DELETED
                );
            }

        } catch (\JsonException $e) {
            $this->logger->error('Échec de la publication de CONVERSATION_DELETED', [
                'conversation_id' => $conversationId,
                'error' => $e->getMessage(),
            ]);
        }

    }
}
