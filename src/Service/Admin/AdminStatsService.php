<?php

declare(strict_types=1);

namespace App\Service\Admin;

use App\Repository\AvisRepository;
use App\Repository\ConversationRepository;
use App\Repository\DemandeRepository;
use App\Repository\MessageRepository;
use App\Repository\SignalementRepository;
use App\Repository\UserRepository;
use App\Repository\VoyageRepository;

readonly class AdminStatsService
{
    public function __construct(
        private UserRepository $userRepository,
        private VoyageRepository $voyageRepository,
        private DemandeRepository $demandeRepository,
        private SignalementRepository $signalementRepository,
        private AvisRepository $avisRepository,
        private MessageRepository $messageRepository,
        private ConversationRepository $conversationRepository,
    ) {}

    /**
     * Récupère toutes les statistiques globales pour le dashboard admin
     */
    public function getGlobalStats(): array
    {
        return [
            'users' => $this->getUsersStats(),
            'voyages' => $this->getVoyagesStats(),
            'demandes' => $this->getDemandesStats(),
            'signalements' => $this->getSignalementsStats(),
            'activity' => $this->getActivityStats(),
            'engagement' => $this->getEngagementStats(),
        ];
    }

    /**
     * Statistiques sur les utilisateurs
     */
    public function getUsersStats(): array
    {
        $total = $this->userRepository->count([]);
        $bannis = $this->userRepository->count(['isBanned' => true]);
        $actifs = $total - $bannis;
        $emailVerifies = $this->userRepository->count(['emailVerifie' => true]);
        $telephoneVerifies = $this->userRepository->count(['telephoneVerifie' => true]);

        // Nombre d'admins et modérateurs
        $admins = $this->userRepository->countByRole('ROLE_ADMIN');
        $moderators = $this->userRepository->countByRole('ROLE_MODERATOR');

        // Nouveaux utilisateurs ce mois
        $startOfMonth = new \DateTime('first day of this month 00:00:00');
        $nouveauxCeMois = $this->userRepository->createQueryBuilder('u')
            ->select('COUNT(u.id)')
            ->where('u.createdAt >= :startDate')
            ->setParameter('startDate', $startOfMonth)
            ->getQuery()
            ->getSingleScalarResult();

        // Nouveaux utilisateurs aujourd'hui
        $startOfDay = new \DateTime('today 00:00:00');
        $nouveauxAujourdhui = $this->userRepository->createQueryBuilder('u')
            ->select('COUNT(u.id)')
            ->where('u.createdAt >= :startDate')
            ->setParameter('startDate', $startOfDay)
            ->getQuery()
            ->getSingleScalarResult();

        return [
            'total' => $total,
            'actifs' => $actifs,
            'bannis' => $bannis,
            'emailVerifies' => $emailVerifies,
            'telephoneVerifies' => $telephoneVerifies,
            'admins' => $admins,
            'moderators' => $moderators,
            'nouveauxCeMois' => $nouveauxCeMois,
            'nouveauxAujourdhui' => $nouveauxAujourdhui,
            'tauxVerificationEmail' => $total > 0 ? round(($emailVerifies / $total) * 100, 1) : 0,
            'tauxVerificationTelephone' => $total > 0 ? round(($telephoneVerifies / $total) * 100, 1) : 0,
        ];
    }

    /**
     * Statistiques sur les voyages
     */
    public function getVoyagesStats(): array
    {
        $total = $this->voyageRepository->count([]);
        $actifs = $this->voyageRepository->count(['statut' => 'actif']);
        $complets = $this->voyageRepository->count(['statut' => 'complet']);
        $termines = $this->voyageRepository->count(['statut' => 'termine']);
        $annules = $this->voyageRepository->count(['statut' => 'annule']);

        // Voyages créés ce mois
        $startOfMonth = new \DateTime('first day of this month 00:00:00');
        $nouveauxCeMois = $this->voyageRepository->countCreatedBetween(
            $startOfMonth,
            new \DateTime()
        );

        // Voyages créés aujourd'hui
        $startOfDay = new \DateTime('today 00:00:00');
        $nouveauxAujourdhui = $this->voyageRepository->countCreatedBetween(
            $startOfDay,
            new \DateTime()
        );

        return [
            'total' => $total,
            'actifs' => $actifs,
            'complets' => $complets,
            'termines' => $termines,
            'annules' => $annules,
            'nouveauxCeMois' => $nouveauxCeMois,
            'nouveauxAujourdhui' => $nouveauxAujourdhui,
            'tauxReussite' => ($termines + $complets) > 0 && $total > 0
                ? round((($termines + $complets) / $total) * 100, 1)
                : 0,
        ];
    }

    /**
     * Statistiques sur les demandes
     */
    public function getDemandesStats(): array
    {
        $total = $this->demandeRepository->count([]);
        $enRecherche = $this->demandeRepository->count(['statut' => 'en_recherche']);
        $voyageurTrouve = $this->demandeRepository->count(['statut' => 'voyageur_trouve']);
        $annulees = $this->demandeRepository->count(['statut' => 'annulee']);

        // Demandes créées ce mois
        $startOfMonth = new \DateTime('first day of this month 00:00:00');
        $nouvellesCeMois = $this->demandeRepository->countCreatedBetween(
            $startOfMonth,
            new \DateTime()
        );

        // Demandes créées aujourd'hui
        $startOfDay = new \DateTime('today 00:00:00');
        $nouvellesAujourdhui = $this->demandeRepository->countCreatedBetween(
            $startOfDay,
            new \DateTime()
        );

        return [
            'total' => $total,
            'enRecherche' => $enRecherche,
            'voyageurTrouve' => $voyageurTrouve,
            'annulees' => $annulees,
            'nouvellesCeMois' => $nouvellesCeMois,
            'nouvellesAujourdhui' => $nouvellesAujourdhui,
            'tauxReussite' => $voyageurTrouve > 0 && $total > 0
                ? round(($voyageurTrouve / $total) * 100, 1)
                : 0,
        ];
    }

    /**
     * Statistiques sur les signalements
     */
    public function getSignalementsStats(): array
    {
        $total = $this->signalementRepository->count([]);
        $enAttente = $this->signalementRepository->count(['statut' => 'en_attente']);
        $traites = $this->signalementRepository->count(['statut' => 'traite']);
        $rejetes = $this->signalementRepository->count(['statut' => 'rejete']);

        // ✅ CORRECTION : Signalements ce mois
        $startOfMonth = new \DateTime('first day of this month 00:00:00');
        $nouveauxCeMois = $this->signalementRepository->createQueryBuilder('s')
            ->select('COUNT(s.id)')
            ->where('s.createdAt >= :startDate')
            ->setParameter('startDate', $startOfMonth)
            ->getQuery()
            ->getSingleScalarResult();

        return [
            'total' => $total,
            'enAttente' => $enAttente,
            'traites' => $traites,
            'rejetes' => $rejetes,
            'nouveauxCeMois' => $nouveauxCeMois,
            'tauxTraitement' => ($traites + $rejetes) > 0 && $total > 0
                ? round((($traites + $rejetes) / $total) * 100, 1)
                : 0,
        ];
    }

    /**
     * Statistiques d'activité (7 derniers jours)
     */
    public function getActivityStats(): array
    {
        $last7Days = [];

        for ($i = 6; $i >= 0; $i--) {
            $date = new \DateTime("-{$i} days");
            $dateStr = $date->format('Y-m-d');
            $dateStart = new \DateTime($dateStr . ' 00:00:00');
            $dateEnd = new \DateTime($dateStr . ' 23:59:59');

            // ✅ CORRECTION : Utiliser createQueryBuilder pour les inscriptions
            $inscriptions = $this->userRepository->createQueryBuilder('u')
                ->select('COUNT(u.id)')
                ->where('u.createdAt >= :start')
                ->andWhere('u.createdAt <= :end')
                ->setParameter('start', $dateStart)
                ->setParameter('end', $dateEnd)
                ->getQuery()
                ->getSingleScalarResult();

            // ✅ CORRECTION : Utiliser createQueryBuilder pour les signalements
            $signalements = $this->signalementRepository->createQueryBuilder('s')
                ->select('COUNT(s.id)')
                ->where('s.createdAt >= :start')
                ->andWhere('s.createdAt <= :end')
                ->setParameter('start', $dateStart)
                ->setParameter('end', $dateEnd)
                ->getQuery()
                ->getSingleScalarResult();

            $last7Days[] = [
                'date' => $dateStr,
                'dayName' => $this->getFrenchDayName($date->format('w')),
                'inscriptions' => $inscriptions,
                'voyages' => $this->voyageRepository->countCreatedBetween($dateStart, $dateEnd),
                'demandes' => $this->demandeRepository->countCreatedBetween($dateStart, $dateEnd),
                'signalements' => $signalements,
            ];
        }

        return [
            'derniers7Jours' => $last7Days,
            'tendance' => $this->calculateTrend($last7Days),
        ];
    }

    /**
     * Statistiques d'engagement
     */
    public function getEngagementStats(): array
    {
        $totalAvis = $this->avisRepository->count([]);
        $totalMessages = $this->messageRepository->count([]);
        $totalConversations = $this->conversationRepository->count([]);

        // Moyenne d'avis par utilisateur ayant donné au moins un avis
        $usersWithAvis = $this->userRepository->createQueryBuilder('u')
            ->select('COUNT(DISTINCT u.id)')
            ->leftJoin('u.avisDonnes', 'a')
            ->where('SIZE(u.avisDonnes) > 0')
            ->getQuery()
            ->getSingleScalarResult();

        $avgAvisPerUser = $usersWithAvis > 0
            ? round($totalAvis / $usersWithAvis, 1)
            : 0;

        return [
            'totalAvis' => $totalAvis,
            'totalMessages' => $totalMessages,
            'totalConversations' => $totalConversations,
            'moyenneAvisParUtilisateur' => $avgAvisPerUser,
            'utilisateursAvecAvis' => (int) $usersWithAvis,
        ];
    }

    /**
     * Statistiques détaillées pour les utilisateurs (pour graphiques)
     */
    public function getUsersDetailedStats(int $days = 30): array
    {
        $stats = [];

        for ($i = $days - 1; $i >= 0; $i--) {
            $date = new \DateTime("-{$i} days");
            $dateStr = $date->format('Y-m-d');
            $dateStart = new \DateTime($dateStr . ' 00:00:00');
            $dateEnd = new \DateTime($dateStr . ' 23:59:59');

            // ✅ CORRECTION
            $inscriptions = $this->userRepository->createQueryBuilder('u')
                ->select('COUNT(u.id)')
                ->where('u.createdAt >= :start')
                ->andWhere('u.createdAt <= :end')
                ->setParameter('start', $dateStart)
                ->setParameter('end', $dateEnd)
                ->getQuery()
                ->getSingleScalarResult();

            $stats[] = [
                'date' => $dateStr,
                'inscriptions' => $inscriptions,
            ];
        }

        return $stats;
    }

    /**
     * Statistiques par méthode d'authentification
     */
    public function getAuthProvidersStats(): array
    {
        $local = $this->userRepository->count(['authProvider' => 'local']);
        $google = $this->userRepository->count(['authProvider' => 'google']);
        $facebook = $this->userRepository->count(['authProvider' => 'facebook']);

        $total = $local + $google + $facebook;

        return [
            'local' => [
                'count' => $local,
                'percentage' => $total > 0 ? round(($local / $total) * 100, 1) : 0,
            ],
            'google' => [
                'count' => $google,
                'percentage' => $total > 0 ? round(($google / $total) * 100, 1) : 0,
            ],
            'facebook' => [
                'count' => $facebook,
                'percentage' => $total > 0 ? round(($facebook / $total) * 100, 1) : 0,
            ],
        ];
    }

    /**
     * Calcule la tendance (hausse/baisse) sur les 7 derniers jours
     */
    private function calculateTrend(array $data): array
    {
        $firstHalf = array_slice($data, 0, 3);
        $secondHalf = array_slice($data, 4, 3);

        $firstHalfTotal = array_sum(array_column($firstHalf, 'inscriptions'));
        $secondHalfTotal = array_sum(array_column($secondHalf, 'inscriptions'));

        $trend = 'stable';
        $percentage = 0;

        if ($firstHalfTotal > 0) {
            $percentage = round((($secondHalfTotal - $firstHalfTotal) / $firstHalfTotal) * 100, 1);

            if ($percentage > 10) {
                $trend = 'hausse';
            } elseif ($percentage < -10) {
                $trend = 'baisse';
            }
        } elseif ($secondHalfTotal > 0) {
            $trend = 'hausse';
            $percentage = 100;
        }

        return [
            'direction' => $trend,
            'percentage' => abs($percentage),
        ];
    }

    /**
     * Convertit le numéro du jour en nom français
     */
    private function getFrenchDayName(string $dayNumber): string
    {
        return match($dayNumber) {
            '0' => 'Dimanche',
            '1' => 'Lundi',
            '2' => 'Mardi',
            '3' => 'Mercredi',
            '4' => 'Jeudi',
            '5' => 'Vendredi',
            '6' => 'Samedi',
            default => 'Inconnu',
        };
    }
}
