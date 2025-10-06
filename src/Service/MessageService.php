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

readonly class MessageService
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private MessageRepository $messageRepository,
        private UserRepository $userRepository,
        private ConversationRepository $conversationRepository,
        private NotificationService $notificationService
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

        $this->entityManager->remove($message);
        $this->entityManager->flush();
    }
}
