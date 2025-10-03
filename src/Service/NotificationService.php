<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Demande;
use App\Entity\Message;
use App\Entity\Notification;
use App\Entity\User;
use App\Entity\Voyage;
use App\Repository\DemandeRepository;
use App\Repository\NotificationRepository;
use App\Repository\VoyageRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

readonly class NotificationService
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private NotificationRepository $notificationRepository,
        private VoyageRepository $voyageRepository,
        private DemandeRepository $demandeRepository
    ) {}

    public function createNotification(
        User $user,
        string $type,
        string $titre,
        string $message,
        ?array $data = null
    ): Notification {
        $notification = new Notification();
        $notification->setUser($user)
            ->setType($type)
            ->setTitre($titre)
            ->setMessage($message)
            ->setData($data)
            ->setLue(false);

        $this->entityManager->persist($notification);
        $this->entityManager->flush();

        return $notification;
    }

    public function notifyMatchingDemandes(Voyage $voyage): void
    {
        $demandes = $this->demandeRepository->findMatchingVoyage(
            $voyage->getVilleDepart(),
            $voyage->getVilleArrivee(),
            $voyage->getDateDepart()
        );

        foreach ($demandes as $demande) {
            $this->createNotification(
                $demande->getClient(),
                'matching_voyage',
                'Nouveau voyage disponible',
                sprintf(
                    'Un voyageur propose %s → %s le %s',
                    $voyage->getVilleDepart(),
                    $voyage->getVilleArrivee(),
                    $voyage->getDateDepart()->format('d/m/Y')
                ),
                ['voyageId' => $voyage->getId()]
            );
        }
    }

    public function notifyMatchingVoyages(Demande $demande): void
    {
        $voyages = $this->voyageRepository->findMatchingDemande(
            $demande->getVilleDepart(),
            $demande->getVilleArrivee(),
            $demande->getDateLimite()
        );

        foreach ($voyages as $voyage) {
            $this->createNotification(
                $voyage->getVoyageur(),
                'matching_demande',
                'Nouvelle demande correspondante',
                sprintf(
                    'Une demande %s → %s correspond à votre voyage',
                    $demande->getVilleDepart(),
                    $demande->getVilleArrivee()
                ),
                ['demandeId' => $demande->getId()]
            );
        }
    }

    public function notifyNewMessage(Message $message): void
    {
        $this->createNotification(
            $message->getDestinataire(),
            'new_message',
            'Nouveau message',
            sprintf(
                '%s %s vous a envoyé un message',
                $message->getExpediteur()->getPrenom(),
                $message->getExpediteur()->getNom()
            ),
            ['messageId' => $message->getId(), 'expediteurId' => $message->getExpediteur()->getId()]
        );
    }

    public function getUserNotifications(int $userId, int $limit = 20): array
    {
        return $this->notificationRepository->findByUser($userId, $limit);
    }

    public function getUnreadNotifications(int $userId): array
    {
        return $this->notificationRepository->findUnreadByUser($userId);
    }

    public function countUnread(int $userId): int
    {
        return $this->notificationRepository->countUnread($userId);
    }

    public function markAsRead(int $id): void
    {
        $notification = $this->notificationRepository->find($id);

        if (!$notification) {
            throw new NotFoundHttpException('Notification non trouvée');
        }

        $notification->setLue(true);
        $this->entityManager->flush();
    }

    public function markAllAsRead(int $userId): void
    {
        $this->notificationRepository->markAllAsRead($userId);
    }

    public function deleteNotification(int $id): void
    {
        $notification = $this->notificationRepository->find($id);

        if (!$notification) {
            throw new NotFoundHttpException('Notification non trouvée');
        }

        $this->entityManager->remove($notification);
        $this->entityManager->flush();
    }
}
