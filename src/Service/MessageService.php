<?php

declare(strict_types=1);

namespace App\Service;

use App\DTO\SendMessageDTO;
use App\Entity\Message;
use App\Entity\User;
use App\Repository\ConversationRepository;
use App\Repository\MessageRepository;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use App\Constant\EventType;
use Psr\Log\LoggerInterface;

readonly class MessageService
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private MessageRepository $messageRepository,
        private UserRepository $userRepository,
        private ConversationRepository $conversationRepository,
        private NotificationService $notificationService,
        private RealtimeNotifier $notifier,
        private LoggerInterface $logger,
    ) {
    }

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

        // Notifier le destinataire (si ses préférences le permettent)
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

    public function countUnread(int $userId): int
    {
        return $this->messageRepository->countUnread($userId);
    }

    public function deleteMessage(int $id, User $user): void
    {
        $message = $this->messageRepository->find($id);

        if (!$message) {
            throw new NotFoundHttpException('Message non trouvé');
        }

        // Vérifier que l'utilisateur est l'expéditeur ou le destinataire
        if ($message->getExpediteur() !== $user && $message->getDestinataire() !== $user) {
            throw new BadRequestHttpException('Vous ne pouvez pas supprimer ce message');
        }

        $conversation = $message->getConversation(); // Récupérer avant la suppression
        $messageId = $message->getId();

        $this->entityManager->remove($message);
        $this->entityManager->flush();

        try {
            $payload = [
                'title' => 'Message supprimé',
                'message' => 'Ce message a été supprimée par un participant ou un administrateur.',
                'messageId' => $messageId,
            ];

            // On récupère les participants concernés (2 ou +)
            $participants = [$conversation->getParticipant1(), $conversation->getParticipant2()];;

            foreach ($participants as $participant) {
                $this->notifier->publishToUser(
                    $participant,
                    $payload,
                    EventType::MESSAGE_DELETED
                );
            }

        } catch (\JsonException $e) {
            $this->logger->error('Échec de la publication de MESSAGE_DELETED', [
                'message_id' => $messageId,
                'error' => $e->getMessage(),
            ]);
        }

    }
}
