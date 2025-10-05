<?php

declare(strict_types=1);

namespace App\Service;

use App\DTO\DashboardDTO;
use App\Entity\User;
use App\Repository\AvisRepository;
use App\Repository\DemandeRepository;
use App\Repository\MessageRepository;
use App\Repository\NotificationRepository;
use App\Repository\VoyageRepository;

readonly class UserStatsService
{
    public function __construct(
        private VoyageRepository $voyageRepository,
        private DemandeRepository $demandeRepository,
        private AvisRepository $avisRepository,
        private NotificationRepository $notificationRepository,
        private MessageRepository $messageRepository,
        private VisibilityService $visibilityService
    ) {}

    public function getUserDashboard(User $user): DashboardDTO
    {
        $userId = $user->getId();

        return DashboardDTO::create(
            summary: $this->getSummary($userId),
            voyages: $this->getVoyagesData($userId),
            demandes: $this->getDemandesData($userId),
            notifications: $this->getNotificationsData($userId),
            messages: $this->getMessagesData($userId),
            stats: $this->getUserStats($userId, $user) // Passer l'utilisateur pour vérifier visibilité
        );
    }

    private function getSummary(int $userId): array
    {
        $voyagesActifs = $this->voyageRepository->count([
            'voyageur' => $userId,
            'statut' => 'actif'
        ]);

        $demandesEnCours = $this->demandeRepository->count([
            'client' => $userId,
            'statut' => 'en_recherche'
        ]);

        $notificationsNonLues = $this->notificationRepository->count([
            'user' => $userId,
            'lue' => false
        ]);

        $messagesNonLus = $this->messageRepository->count([
            'destinataire' => $userId,
            'lu' => false
        ]);

        return [
            'voyagesActifs' => $voyagesActifs,
            'demandesEnCours' => $demandesEnCours,
            'notificationsNonLues' => $notificationsNonLues,
            'messagesNonLus' => $messagesNonLus,
        ];
    }

    private function getVoyagesData(int $userId): array
    {
        $voyages = $this->voyageRepository->findBy(
            ['voyageur' => $userId],
            ['createdAt' => 'DESC'],
            5
        );

        $actifs = array_filter($voyages, fn($v) => $v->getStatut() === 'actif');
        $prochains = array_filter($actifs, function ($v) {
            return $v->getDateDepart() >= new \DateTime();
        });

        return [
            'total' => $this->voyageRepository->count(['voyageur' => $userId]),
            'actifs' => count($actifs),
            'recents' => array_map(function ($voyage) {
                return [
                    'id' => $voyage->getId(),
                    'villeDepart' => $voyage->getVilleDepart(),
                    'villeArrivee' => $voyage->getVilleArrivee(),
                    'dateDepart' => $voyage->getDateDepart()->format('Y-m-d'),
                    'dateArrivee' => $voyage->getDateArrivee()->format('Y-m-d'),
                    'statut' => $voyage->getStatut(),
                    'poidsDisponible' => $voyage->getPoidsDisponible(),
                ];
            }, array_slice($voyages, 0, 5)),
        ];
    }

    private function getDemandesData(int $userId): array
    {
        $demandes = $this->demandeRepository->findBy(
            ['client' => $userId],
            ['createdAt' => 'DESC'],
            5
        );

        $enCours = array_filter($demandes, fn($d) => $d->getStatut() === 'en_recherche');

        return [
            'total' => $this->demandeRepository->count(['client' => $userId]),
            'enCours' => count($enCours),
            'recentes' => array_map(function ($demande) {
                return [
                    'id' => $demande->getId(),
                    'villeDepart' => $demande->getVilleDepart(),
                    'villeArrivee' => $demande->getVilleArrivee(),
                    'dateLimite' => $demande->getDateLimite()?->format('Y-m-d'),
                    'statut' => $demande->getStatut(),
                    'poidsEstime' => $demande->getPoidsEstime(),
                ];
            }, array_slice($demandes, 0, 5)),
        ];
    }

    private function getNotificationsData(int $userId): array
    {
        $notifications = $this->notificationRepository->findBy(
            ['user' => $userId],
            ['createdAt' => 'DESC'],
            5
        );

        return [
            'nonLues' => $this->notificationRepository->count([
                'user' => $userId,
                'lue' => false
            ]),
            'recentes' => array_map(function ($notification) {
                return [
                    'id' => $notification->getId(),
                    'type' => $notification->getType(),
                    'titre' => $notification->getTitre(),
                    'message' => $notification->getMessage(),
                    'lue' => $notification->isLue(),
                    'createdAt' => $notification->getCreatedAt()->format('Y-m-d H:i:s'),
                    'data' => $notification->getData(),
                ];
            }, $notifications),
        ];
    }

    private function getMessagesData(int $userId): array
    {
        $messagesRecus = $this->messageRepository->findBy(
            ['destinataire' => $userId],
            ['createdAt' => 'DESC'],
            5
        );

        return [
            'nonLus' => $this->messageRepository->count([
                'destinataire' => $userId,
                'lu' => false
            ]),
            'recents' => array_map(function ($message) {
                return [
                    'id' => $message->getId(),
                    'expediteur' => [
                        'id' => $message->getExpediteur()->getId(),
                        'nom' => $message->getExpediteur()->getNom(),
                        'prenom' => $message->getExpediteur()->getPrenom(),
                    ],
                    'contenu' => mb_substr($message->getContenu(), 0, 100) . (mb_strlen($message->getContenu()) > 100 ? '...' : ''),
                    'lu' => $message->isLu(),
                    'createdAt' => $message->getCreatedAt()->format('Y-m-d H:i:s'),
                ];
            }, $messagesRecus),
        ];
    }

    private function getUserStats(int $userId, User $viewer): array
    {
        $voyagesTermines = $this->voyageRepository->count([
            'voyageur' => $userId,
            'statut' => 'termine'
        ]);

        $demandesReussies = $this->demandeRepository->count([
            'client' => $userId,
            'statut' => 'voyageur_trouve'
        ]);

        $avisStats = $this->avisRepository->getStatsByUser($userId);

        return [
            'voyagesEffectues' => $voyagesTermines,
            'bagagesTransportes' => $demandesReussies,
            'noteMoyenne' => $avisStats['average'] ?? 0,
            'nombreAvis' => $avisStats['total'] ?? 0,
            'repartitionNotes' => $avisStats['distribution'] ?? [],
        ];
    }

    /**
     * Récupère les statistiques publiques d'un utilisateur
     * Respecte les préférences de visibilité
     */
    public function getUserPublicStats(User $profileOwner, ?User $viewer): array
    {
        // ==================== UTILISATION DU VISIBILITYSERVICE ====================
        if (!$this->visibilityService->areStatsVisibleFor($profileOwner, $viewer)) {
            return [
                'visible' => false,
                'message' => 'Les statistiques de cet utilisateur sont privées'
            ];
        }

        $userId = $profileOwner->getId();

        $voyagesTermines = $this->voyageRepository->count([
            'voyageur' => $userId,
            'statut' => 'termine'
        ]);

        $demandesReussies = $this->demandeRepository->count([
            'client' => $userId,
            'statut' => 'voyageur_trouve'
        ]);

        $avisStats = $this->avisRepository->getStatsByUser($userId);

        return [
            'visible' => true,
            'voyagesEffectues' => $voyagesTermines,
            'bagagesTransportes' => $demandesReussies,
            'noteMoyenne' => $avisStats['average'] ?? 0,
            'nombreAvis' => $avisStats['total'] ?? 0,
            'repartitionNotes' => $avisStats['distribution'] ?? [],
        ];
    }

    /**
     * Récupère les informations de profil visibles
     * Respecte les préférences de visibilité
     */
    public function getVisibleProfileData(User $profileOwner, ?User $viewer): array
    {
        $data = [
            'id' => $profileOwner->getId(),
            'nom' => $profileOwner->getNom(),
            'prenom' => $profileOwner->getPrenom(),
            'photo' => $profileOwner->getPhoto(),
            'bio' => $profileOwner->getBio(),
            'createdAt' => $profileOwner->getCreatedAt()->format('Y-m-d H:i:s'),
            'emailVerifie' => $profileOwner->isEmailVerifie(),
            'telephoneVerifie' => $profileOwner->isTelephoneVerifie(),
        ];

        // ==================== UTILISATION DU VISIBILITYSERVICE ====================

        // Email visible ?
        if ($this->visibilityService->isEmailVisibleFor($profileOwner, $viewer)) {
            $data['email'] = $profileOwner->getEmail();
        }

        // Téléphone visible ?
        if ($this->visibilityService->isPhoneVisibleFor($profileOwner, $viewer)) {
            $data['telephone'] = $profileOwner->getTelephone();
        }

        // Stats visibles ?
        $data['stats'] = $this->getUserPublicStats($profileOwner, $viewer);

        return $data;
    }
}
