<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\User;
use App\Service\Admin\AdminStatsService;
use App\Service\Admin\AuditLogService;
use OpenApi\Attributes as OA;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/api/admin', name: 'api_admin_')]
#[OA\Tag(name: 'Admin - Dashboard')]
#[IsGranted('ROLE_ADMIN')]
class AdminDashboardController extends AbstractController
{
    public function __construct(
        private readonly AdminStatsService $adminStatsService,
        private readonly AuditLogService $auditLogService,
    ) {}

    /**
     * Dashboard principal avec toutes les statistiques
     */
    #[Route('/dashboard', name: 'dashboard', methods: ['GET'])]
    #[OA\Get(
        path: '/api/admin/dashboard',
        summary: 'Dashboard admin avec statistiques globales',
        security: [['cookieAuth' => []]]
    )]
    #[OA\Response(
        response: 200,
        description: 'Statistiques du dashboard',
        content: new OA\JsonContent(
            properties: [
                new OA\Property(
                    property: 'users',
                    properties: [
                        new OA\Property(property: 'total', type: 'integer', example: 1250),
                        new OA\Property(property: 'actifs', type: 'integer', example: 1200),
                        new OA\Property(property: 'bannis', type: 'integer', example: 50),
                        new OA\Property(property: 'nouveauxCeMois', type: 'integer', example: 120),
                        new OA\Property(property: 'tauxVerificationEmail', type: 'number', example: 85.5),
                    ],
                    type: 'object'
                ),
                new OA\Property(
                    property: 'voyages',
                    properties: [
                        new OA\Property(property: 'total', type: 'integer', example: 850),
                        new OA\Property(property: 'actifs', type: 'integer', example: 320),
                        new OA\Property(property: 'termines', type: 'integer', example: 450),
                    ],
                    type: 'object'
                ),
                new OA\Property(
                    property: 'demandes',
                    type: 'object'
                ),
                new OA\Property(
                    property: 'signalements',
                    type: 'object'
                ),
                new OA\Property(
                    property: 'activity',
                    type: 'object'
                ),
            ]
        )
    )]
    #[OA\Response(response: 403, description: 'Accès refusé - Rôle ADMIN requis')]
    public function dashboard(): JsonResponse
    {
        $stats = $this->adminStatsService->getGlobalStats();

        return $this->json($stats, Response::HTTP_OK);
    }

    /**
     * Statistiques détaillées sur les utilisateurs
     */
    #[Route('/stats/users', name: 'stats_users', methods: ['GET'])]
    #[OA\Get(
        path: '/api/admin/stats/users',
        summary: 'Statistiques détaillées des utilisateurs',
        security: [['cookieAuth' => []]]
    )]
    #[OA\Parameter(
        name: 'days',
        in: 'query',
        description: 'Nombre de jours pour les statistiques',
        schema: new OA\Schema(type: 'integer', default: 30)
    )]
    #[OA\Response(response: 200, description: 'Statistiques utilisateurs')]
    public function usersStats(Request $request): JsonResponse
    {
        $days = $request->query->getInt('days', 30);

        $stats = [
            'global' => $this->adminStatsService->getUsersStats(),
            'detailed' => $this->adminStatsService->getUsersDetailedStats($days),
            'authProviders' => $this->adminStatsService->getAuthProvidersStats(),
        ];

        return $this->json($stats, Response::HTTP_OK);
    }

    /**
     * Statistiques détaillées sur les voyages
     */
    #[Route('/stats/voyages', name: 'stats_voyages', methods: ['GET'])]
    #[OA\Get(
        path: '/api/admin/stats/voyages',
        summary: 'Statistiques détaillées des voyages',
        security: [['cookieAuth' => []]]
    )]
    #[OA\Response(response: 200, description: 'Statistiques voyages')]
    public function voyagesStats(): JsonResponse
    {
        $stats = $this->adminStatsService->getVoyagesStats();

        return $this->json($stats, Response::HTTP_OK);
    }

    /**
     * Statistiques détaillées sur les demandes
     */
    #[Route('/stats/demandes', name: 'stats_demandes', methods: ['GET'])]
    #[OA\Get(
        path: '/api/admin/stats/demandes',
        summary: 'Statistiques détaillées des demandes',
        security: [['cookieAuth' => []]]
    )]
    #[OA\Response(response: 200, description: 'Statistiques demandes')]
    public function demandesStats(): JsonResponse
    {
        $stats = $this->adminStatsService->getDemandesStats();

        return $this->json($stats, Response::HTTP_OK);
    }

    /**
     * Statistiques détaillées sur les signalements
     */
    #[Route('/stats/signalements', name: 'stats_signalements', methods: ['GET'])]
    #[OA\Get(
        path: '/api/admin/stats/signalements',
        summary: 'Statistiques des signalements',
        security: [['cookieAuth' => []]]
    )]
    #[OA\Response(response: 200, description: 'Statistiques signalements')]
    public function signalementsStats(): JsonResponse
    {
        $stats = $this->adminStatsService->getSignalementsStats();

        return $this->json($stats, Response::HTTP_OK);
    }

    /**
     * Statistiques d'activité (7 derniers jours)
     */
    #[Route('/stats/activity', name: 'stats_activity', methods: ['GET'])]
    #[OA\Get(
        path: '/api/admin/stats/activity',
        summary: 'Statistiques d\'activité des 7 derniers jours',
        security: [['cookieAuth' => []]]
    )]
    #[OA\Response(response: 200, description: 'Statistiques d\'activité')]
    public function activityStats(): JsonResponse
    {
        $stats = $this->adminStatsService->getActivityStats();

        return $this->json($stats, Response::HTTP_OK);
    }

    /**
     * Statistiques d'engagement
     */
    #[Route('/stats/engagement', name: 'stats_engagement', methods: ['GET'])]
    #[OA\Get(
        path: '/api/admin/stats/engagement',
        summary: 'Statistiques d\'engagement (avis, messages, etc.)',
        security: [['cookieAuth' => []]]
    )]
    #[OA\Response(response: 200, description: 'Statistiques d\'engagement')]
    public function engagementStats(): JsonResponse
    {
        $stats = $this->adminStatsService->getEngagementStats();

        return $this->json($stats, Response::HTTP_OK);
    }

    /**
     * Liste des logs d'actions admin
     */
    #[Route('/logs', name: 'logs', methods: ['GET'])]
    #[OA\Get(
        path: '/api/admin/logs',
        summary: 'Liste des logs d\'actions admin',
        security: [['cookieAuth' => []]]
    )]
    #[OA\Parameter(
        name: 'page',
        description: 'Numéro de page',
        in: 'query',
        schema: new OA\Schema(type: 'integer', default: 1)
    )]
    #[OA\Parameter(
        name: 'limit',
        description: 'Nombre de résultats par page',
        in: 'query',
        schema: new OA\Schema(type: 'integer', default: 50)
    )]
    #[OA\Parameter(
        name: 'action',
        description: 'Filtrer par action',
        in: 'query',
        schema: new OA\Schema(type: 'string')
    )]
    #[OA\Parameter(
        name: 'targetType',
        description: 'Filtrer par type de cible',
        in: 'query',
        schema: new OA\Schema(type: 'string')
    )]
    #[OA\Parameter(
        name: 'adminId',
        description: 'Filtrer par admin',
        in: 'query',
        schema: new OA\Schema(type: 'integer')
    )]
    #[OA\Response(
        response: 200,
        description: 'Liste paginée des logs',
        content: new OA\JsonContent(
            properties: [
                new OA\Property(
                    property: 'data',
                    type: 'array',
                    items: new OA\Items(
                        properties: [
                            new OA\Property(property: 'id', type: 'integer'),
                            new OA\Property(property: 'admin', type: 'object'),
                            new OA\Property(property: 'action', type: 'string'),
                            new OA\Property(property: 'targetType', type: 'string'),
                            new OA\Property(property: 'targetId', type: 'integer'),
                            new OA\Property(property: 'details', type: 'object'),
                            new OA\Property(property: 'createdAt', type: 'string'),
                        ]
                    )
                ),
                new OA\Property(
                    property: 'pagination',
                    properties: [
                        new OA\Property(property: 'page', type: 'integer'),
                        new OA\Property(property: 'limit', type: 'integer'),
                        new OA\Property(property: 'total', type: 'integer'),
                        new OA\Property(property: 'pages', type: 'integer'),
                    ],
                    type: 'object'
                ),
            ]
        )
    )]
    public function logs(Request $request): JsonResponse
    {
        $page = $request->query->getInt('page', 1);
        $limit = $request->query->getInt('limit', 50);

        $filters = [
            'action' => $request->query->get('action'),
            'targetType' => $request->query->get('targetType'),
            'adminId' => $request->query->get('adminId'),
            'dateFrom' => $request->query->get('dateFrom'),
            'dateTo' => $request->query->get('dateTo'),
        ];

        // Supprimer les filtres vides
        $filters = array_filter($filters, fn($value) => $value !== null && $value !== '');

        $logs = $this->auditLogService->searchLogs($filters, $page, $limit);

        return $this->json($logs, Response::HTTP_OK, [], ['groups' => ['admin:log:list']]);
    }

    /**
     * Logs d'un admin spécifique
     */
    #[Route('/logs/admin/{adminId}', name: 'logs_by_admin', methods: ['GET'])]
    #[OA\Get(
        path: '/api/admin/logs/admin/{adminId}',
        summary: 'Logs d\'un admin spécifique',
        security: [['cookieAuth' => []]]
    )]
    #[OA\Parameter(
        name: 'adminId',
        in: 'path',
        required: true,
        schema: new OA\Schema(type: 'integer')
    )]
    #[OA\Parameter(
        name: 'limit',
        in: 'query',
        schema: new OA\Schema(type: 'integer', default: 50)
    )]
    #[OA\Response(response: 200, description: 'Liste des logs de cet admin')]
    public function logsByAdmin(int $adminId, Request $request): JsonResponse
    {
        $limit = $request->query->getInt('limit', 50);

        /* @var User $admin*/
        $admin = $this->getUser();
        // TODO: Vérifier que l'admin existe via UserRepository

        $logs = $this->auditLogService->getLogsByAdmin($admin, $limit);

        return $this->json($logs, Response::HTTP_OK, [], ['groups' => ['admin:log:list']]);
    }

    /**
     * Statistiques des logs
     */
    #[Route('/logs/stats', name: 'logs_stats', methods: ['GET'])]
    #[OA\Get(
        path: '/api/admin/logs/stats',
        summary: 'Statistiques des actions admin',
        security: [['cookieAuth' => []]]
    )]
    #[OA\Parameter(
        name: 'period',
        description: 'Période : today, week, month',
        in: 'query',
        schema: new OA\Schema(type: 'string', default: 'week')
    )]
    #[OA\Response(response: 200, description: 'Statistiques des logs')]
    public function logsStats(Request $request): JsonResponse
    {
        $period = $request->query->get('period', 'week');

        $stats = match($period) {
            'today' => $this->auditLogService->getTodayStats(),
            'month' => $this->auditLogService->getMonthStats(),
            default => $this->auditLogService->getWeekStats(),
        };

        $mostActive = $this->auditLogService->getMostActiveAdmins(5);
        $frequentActions = $this->auditLogService->getMostFrequentActions(10);
        $moderatedTargets = $this->auditLogService->getMostModeratedTargets(10);

        return $this->json([
            'period' => $period,
            'actionStats' => $stats,
            'mostActiveAdmins' => $mostActive,
            'frequentActions' => $frequentActions,
            'moderatedTargets' => $moderatedTargets,
        ], Response::HTTP_OK);
    }

    /**
     * Export des logs en CSV
     */
    #[Route('/logs/export', name: 'logs_export', methods: ['GET'])]
    #[OA\Get(
        path: '/api/admin/logs/export',
        summary: 'Exporter les logs en CSV',
        security: [['cookieAuth' => []]]
    )]
    #[OA\Response(
        response: 200,
        description: 'Fichier CSV des logs',
        content: new OA\MediaType(
            mediaType: 'text/csv',
            schema: new OA\Schema(type: 'string')
        )
    )]
    public function exportLogs(Request $request): Response
    {
        $filters = [
            'action' => $request->query->get('action'),
            'targetType' => $request->query->get('targetType'),
            'adminId' => $request->query->get('adminId'),
            'dateFrom' => $request->query->get('dateFrom'),
            'dateTo' => $request->query->get('dateTo'),
        ];

        $filters = array_filter($filters, fn($value) => $value !== null && $value !== '');

        $csv = $this->auditLogService->exportLogsToCSV($filters);

        $response = new Response($csv);
        $response->headers->set('Content-Type', 'text/csv; charset=utf-8');
        $response->headers->set('Content-Disposition', 'attachment; filename="admin_logs_' . date('Y-m-d_His') . '.csv"');

        return $response;
    }
}
