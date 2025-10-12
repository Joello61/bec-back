<?php

declare(strict_types=1);

namespace App\Service\Admin;

use App\Entity\AdminLog;
use App\Entity\User;
use App\Repository\AdminLogRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\RequestStack;

readonly class AuditLogService
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private AdminLogRepository $adminLogRepository,
        private RequestStack $requestStack,
    ) {}

    /**
     * Enregistre une action admin dans les logs
     */
    public function logAdminAction(
        User $admin,
        string $action,
        string $targetType,
        int $targetId,
        ?array $details = null
    ): AdminLog {
        $request = $this->requestStack->getCurrentRequest();

        $log = new AdminLog();
        $log->setAdmin($admin)
            ->setAction($action)
            ->setTargetType($targetType)
            ->setTargetId($targetId)
            ->setDetails($details);

        // Récupérer l'IP et le User-Agent si disponibles
        if ($request) {
            $log->setIpAddress($request->getClientIp())
                ->setUserAgent($request->headers->get('User-Agent'));
        }

        $this->entityManager->persist($log);
        $this->entityManager->flush();

        return $log;
    }

    /**
     * Récupère les logs récents avec pagination
     */
    public function getRecentLogs(int $page = 1, int $limit = 50): array
    {
        return $this->adminLogRepository->findRecentPaginated($page, $limit);
    }

    /**
     * Récupère les logs d'un admin spécifique
     */
    public function getLogsByAdmin(User $admin, int $limit = 50): array
    {
        return $this->adminLogRepository->findByAdmin($admin, $limit);
    }

    /**
     * Récupère les logs par type de cible
     */
    public function getLogsByTargetType(string $targetType, int $limit = 50): array
    {
        return $this->adminLogRepository->findByTargetType($targetType, $limit);
    }

    /**
     * Récupère les logs par action
     */
    public function getLogsByAction(string $action, int $limit = 50): array
    {
        return $this->adminLogRepository->findByAction($action, $limit);
    }

    /**
     * Récupère l'historique complet d'une cible (user, voyage, demande, etc.)
     */
    public function getTargetHistory(string $targetType, int $targetId): array
    {
        return $this->adminLogRepository->findByTarget($targetType, $targetId);
    }

    /**
     * Recherche dans les logs avec filtres avancés
     */
    public function searchLogs(array $filters = [], int $page = 1, int $limit = 50): array
    {
        return $this->adminLogRepository->search($filters, $page, $limit);
    }

    /**
     * Compte le nombre d'actions effectuées par un admin
     */
    public function countAdminActions(User $admin): int
    {
        return $this->adminLogRepository->countByAdmin($admin);
    }

    /**
     * Récupère les statistiques d'actions sur une période
     */
    public function getActionStats(\DateTime $startDate, \DateTime $endDate): array
    {
        return $this->adminLogRepository->getActionStats($startDate, $endDate);
    }

    /**
     * Récupère les statistiques d'actions du jour
     */
    public function getTodayStats(): array
    {
        $today = new \DateTime('today 00:00:00');
        $tomorrow = new \DateTime('tomorrow 00:00:00');

        return $this->getActionStats($today, $tomorrow);
    }

    /**
     * Récupère les statistiques d'actions de la semaine
     */
    public function getWeekStats(): array
    {
        $startOfWeek = new \DateTime('monday this week 00:00:00');
        $now = new \DateTime();

        return $this->getActionStats($startOfWeek, $now);
    }

    /**
     * Récupère les statistiques d'actions du mois
     */
    public function getMonthStats(): array
    {
        $startOfMonth = new \DateTime('first day of this month 00:00:00');
        $now = new \DateTime();

        return $this->getActionStats($startOfMonth, $now);
    }

    /**
     * Récupère les admins les plus actifs
     */
    public function getMostActiveAdmins(int $limit = 10): array
    {
        $qb = $this->entityManager->createQueryBuilder();

        $results = $qb->select('IDENTITY(l.admin) as adminId, COUNT(l.id) as actionCount')
            ->from(AdminLog::class, 'l')
            ->groupBy('l.admin')
            ->orderBy('actionCount', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();

        $adminsData = [];
        foreach ($results as $result) {
            $admin = $this->entityManager->getRepository(User::class)->find($result['adminId']);
            if ($admin) {
                $adminsData[] = [
                    'admin' => [
                        'id' => $admin->getId(),
                        'email' => $admin->getEmail(),
                        'nom' => $admin->getNom(),
                        'prenom' => $admin->getPrenom(),
                    ],
                    'actionCount' => (int) $result['actionCount'],
                ];
            }
        }

        return $adminsData;
    }

    /**
     * Récupère les actions les plus fréquentes
     */
    public function getMostFrequentActions(int $limit = 10): array
    {
        $qb = $this->entityManager->createQueryBuilder();

        return $qb->select('l.action, COUNT(l.id) as count')
            ->from(AdminLog::class, 'l')
            ->groupBy('l.action')
            ->orderBy('count', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * Récupère les types de cibles les plus modérés
     */
    public function getMostModeratedTargets(int $limit = 10): array
    {
        $qb = $this->entityManager->createQueryBuilder();

        return $qb->select('l.targetType, COUNT(l.id) as count')
            ->from(AdminLog::class, 'l')
            ->groupBy('l.targetType')
            ->orderBy('count', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * Récupère les logs d'une journée spécifique
     */
    public function getLogsByDate(\DateTime $date): array
    {
        $startOfDay = (clone $date)->setTime(0, 0, 0);
        $endOfDay = (clone $date)->setTime(23, 59, 59);

        return $this->adminLogRepository->search([
            'dateFrom' => $startOfDay->format('Y-m-d H:i:s'),
            'dateTo' => $endOfDay->format('Y-m-d H:i:s'),
        ], 1, 1000);
    }

    /**
     * Exporte les logs en CSV
     */
    public function exportLogsToCSV(array $filters = []): string
    {
        $logs = $this->adminLogRepository->search($filters, 1, 10000);

        $csv = "ID;Admin;Action;Type Cible;ID Cible;Date;IP;Details\n";

        foreach ($logs['data'] as $log) {
            $csv .= sprintf(
                "%d;%s;%s;%s;%d;%s;%s;%s\n",
                $log->getId(),
                $log->getAdmin()->getEmail(),
                $log->getAction(),
                $log->getTargetType(),
                $log->getTargetId(),
                $log->getCreatedAt()->format('Y-m-d H:i:s'),
                $log->getIpAddress() ?? 'N/A',
                json_encode($log->getDetails())
            );
        }

        return $csv;
    }

    /**
     * Nettoie les anciens logs (conservation limitée)
     * Par défaut, supprime les logs de plus de 1 an
     */
    public function cleanOldLogs(int $daysToKeep = 365): int
    {
        $date = new \DateTime("-{$daysToKeep} days");
        return $this->adminLogRepository->deleteOlderThan($date);
    }

    /**
     * Vérifie si un admin a effectué une action spécifique récemment
     */
    public function hasRecentAction(
        User $admin,
        string $action,
        int $minutesAgo = 5
    ): bool {
        $since = new \DateTime("-{$minutesAgo} minutes");

        $qb = $this->entityManager->createQueryBuilder();

        $count = $qb->select('COUNT(l.id)')
            ->from(AdminLog::class, 'l')
            ->where('l.admin = :admin')
            ->andWhere('l.action = :action')
            ->andWhere('l.createdAt >= :since')
            ->setParameter('admin', $admin)
            ->setParameter('action', $action)
            ->setParameter('since', $since)
            ->getQuery()
            ->getSingleScalarResult();

        return $count > 0;
    }

    /**
     * Récupère un résumé quotidien des actions admin
     */
    public function getDailySummary(\DateTime $date): array
    {
        $logs = $this->getLogsByDate($date);

        $summary = [
            'date' => $date->format('Y-m-d'),
            'totalActions' => count($logs['data']),
            'actionsByType' => [],
            'actionsByAdmin' => [],
            'targetsByType' => [],
        ];

        foreach ($logs['data'] as $log) {
            // Actions par type
            $action = $log->getAction();
            if (!isset($summary['actionsByType'][$action])) {
                $summary['actionsByType'][$action] = 0;
            }
            $summary['actionsByType'][$action]++;

            // Actions par admin
            $adminEmail = $log->getAdmin()->getEmail();
            if (!isset($summary['actionsByAdmin'][$adminEmail])) {
                $summary['actionsByAdmin'][$adminEmail] = 0;
            }
            $summary['actionsByAdmin'][$adminEmail]++;

            // Cibles par type
            $targetType = $log->getTargetType();
            if (!isset($summary['targetsByType'][$targetType])) {
                $summary['targetsByType'][$targetType] = 0;
            }
            $summary['targetsByType'][$targetType]++;
        }

        return $summary;
    }

    /**
     * Récupère l'activité d'un admin sur une période
     */
    public function getAdminActivity(User $admin, \DateTime $startDate, \DateTime $endDate): array
    {
        $filters = [
            'adminId' => $admin->getId(),
            'dateFrom' => $startDate->format('Y-m-d H:i:s'),
            'dateTo' => $endDate->format('Y-m-d H:i:s'),
        ];

        $logs = $this->adminLogRepository->search($filters, 1, 10000);

        $activity = [
            'admin' => [
                'id' => $admin->getId(),
                'email' => $admin->getEmail(),
                'nom' => $admin->getNom() . ' ' . $admin->getPrenom(),
            ],
            'period' => [
                'from' => $startDate->format('Y-m-d'),
                'to' => $endDate->format('Y-m-d'),
            ],
            'totalActions' => count($logs['data']),
            'actionBreakdown' => [],
            'targetBreakdown' => [],
            'mostActiveDay' => null,
            'activityByDay' => [],
        ];

        $dayActivity = [];

        foreach ($logs['data'] as $log) {
            // Actions par type
            $action = $log->getAction();
            if (!isset($activity['actionBreakdown'][$action])) {
                $activity['actionBreakdown'][$action] = 0;
            }
            $activity['actionBreakdown'][$action]++;

            // Cibles par type
            $targetType = $log->getTargetType();
            if (!isset($activity['targetBreakdown'][$targetType])) {
                $activity['targetBreakdown'][$targetType] = 0;
            }
            $activity['targetBreakdown'][$targetType]++;

            // Activité par jour
            $day = $log->getCreatedAt()->format('Y-m-d');
            if (!isset($dayActivity[$day])) {
                $dayActivity[$day] = 0;
            }
            $dayActivity[$day]++;
        }

        // Jour le plus actif
        if (!empty($dayActivity)) {
            arsort($dayActivity);
            $mostActiveDay = array_key_first($dayActivity);
            $activity['mostActiveDay'] = [
                'date' => $mostActiveDay,
                'count' => $dayActivity[$mostActiveDay],
            ];
        }

        $activity['activityByDay'] = $dayActivity;

        return $activity;
    }
}
