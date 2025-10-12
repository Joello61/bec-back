<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\DTO\Admin\BanUserDTO;
use App\DTO\Admin\UpdateUserRolesDTO;
use App\Entity\User;
use App\Repository\UserRepository;
use App\Service\Admin\AuditLogService;
use App\Service\Admin\ModerationService;
use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/api/admin/users', name: 'api_admin_users_')]
#[OA\Tag(name: 'Admin - Utilisateurs')]
#[IsGranted('ROLE_ADMIN')]
class AdminUserController extends AbstractController
{
    public function __construct(
        private readonly UserRepository $userRepository,
        private readonly ModerationService $moderationService,
        private readonly AuditLogService $auditLogService,
    ) {}

    /**
     * Liste TOUS les utilisateurs (admin)
     */
    #[Route('', name: 'list', methods: ['GET'])]
    #[OA\Get(
        path: '/api/admin/users',
        summary: 'Liste de tous les utilisateurs (admin)',
        security: [['cookieAuth' => []]]
    )]
    #[OA\Parameter(
        name: 'page',
        in: 'query',
        schema: new OA\Schema(type: 'integer', default: 1)
    )]
    #[OA\Parameter(
        name: 'limit',
        in: 'query',
        schema: new OA\Schema(type: 'integer', default: 20)
    )]
    #[OA\Parameter(
        name: 'banned',
        description: 'Filtrer par statut banni (true/false)',
        in: 'query',
        schema: new OA\Schema(type: 'boolean')
    )]
    #[OA\Parameter(
        name: 'role',
        description: 'Filtrer par rôle',
        in: 'query',
        schema: new OA\Schema(type: 'string', enum: ['ROLE_USER', 'ROLE_MODERATOR', 'ROLE_ADMIN'])
    )]
    #[OA\Parameter(
        name: 'verified',
        description: 'Filtrer par email vérifié',
        in: 'query',
        schema: new OA\Schema(type: 'boolean')
    )]
    #[OA\Response(
        response: 200,
        description: 'Liste paginée de tous les utilisateurs',
        content: new OA\JsonContent(
            properties: [
                new OA\Property(
                    property: 'data',
                    type: 'array',
                    items: new OA\Items(type: 'object')
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
    public function list(Request $request): JsonResponse
    {
        $page = $request->query->getInt('page', 1);
        $limit = $request->query->getInt('limit', 20);

        $filters = [
            'banned' => $request->query->get('banned') === 'true' ? true : ($request->query->get('banned') === 'false' ? false : null),
            'role' => $request->query->get('role'),
            'verified' => $request->query->get('verified') === 'true' ? true : ($request->query->get('verified') === 'false' ? false : null),
        ];

        $filters = array_filter($filters, fn($value) => $value !== null);

        $result = $this->userRepository->findAllPaginatedAdmin($page, $limit, $filters);

        return $this->json($result, Response::HTTP_OK, [], ['groups' => ['admin:user:list']]);
    }

    /**
     * Détails d'un utilisateur
     */
    #[Route('/{id}', name: 'show', methods: ['GET'])]
    #[OA\Get(
        path: '/api/admin/users/{id}',
        summary: 'Détails complets d\'un utilisateur',
        security: [['cookieAuth' => []]]
    )]
    #[OA\Parameter(
        name: 'id',
        in: 'path',
        required: true,
        schema: new OA\Schema(type: 'integer')
    )]
    #[OA\Response(response: 200, description: 'Détails de l\'utilisateur')]
    #[OA\Response(response: 404, description: 'Utilisateur non trouvé')]
    public function show(int $id): JsonResponse
    {
        $user = $this->userRepository->find($id);

        if (!$user) {
            return $this->json(['message' => 'Utilisateur non trouvé'], Response::HTTP_NOT_FOUND);
        }

        return $this->json($user, Response::HTTP_OK, [], ['groups' => ['admin:user:read']]);
    }

    /**
     * Bannir un utilisateur
     */
    #[Route('/{id}/ban', name: 'ban', methods: ['POST'])]
    #[OA\Post(
        path: '/api/admin/users/{id}/ban',
        summary: 'Bannir un utilisateur',
        security: [['cookieAuth' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(ref: new Model(type: BanUserDTO::class))
        )
    )]
    #[OA\Parameter(
        name: 'id',
        in: 'path',
        required: true,
        schema: new OA\Schema(type: 'integer')
    )]
    #[OA\Response(
        response: 200,
        description: 'Utilisateur banni avec succès',
        content: new OA\JsonContent(
            properties: [
                new OA\Property(property: 'success', type: 'boolean', example: true),
                new OA\Property(property: 'message', type: 'string', example: 'Utilisateur banni avec succès'),
            ]
        )
    )]
    #[OA\Response(response: 400, description: 'Données invalides')]
    #[OA\Response(response: 404, description: 'Utilisateur non trouvé')]
    public function banUser(
        int $id,
        #[MapRequestPayload] BanUserDTO $dto
    ): JsonResponse {
        $user = $this->userRepository->find($id);

        if (!$user) {
            return $this->json(['message' => 'Utilisateur non trouvé'], Response::HTTP_NOT_FOUND);
        }

        /** @var User $admin */
        $admin = $this->getUser();

        try {
            $this->moderationService->banUser($user, $admin, $dto->reason);

            // Supprimer les contenus si demandé
            if ($dto->deleteContent) {
                $stats = $this->moderationService->deleteAllUserContent($user, $admin, $dto->reason);

                return $this->json([
                    'success' => true,
                    'message' => 'Utilisateur banni et contenus supprimés',
                    'deletedContent' => $stats,
                ], Response::HTTP_OK);
            }

            return $this->json([
                'success' => true,
                'message' => 'Utilisateur banni avec succès',
            ], Response::HTTP_OK);

        } catch (\InvalidArgumentException $e) {
            return $this->json([
                'success' => false,
                'message' => $e->getMessage()
            ], Response::HTTP_BAD_REQUEST);
        }
    }

    /**
     * Débannir un utilisateur
     */
    #[Route('/{id}/unban', name: 'unban', methods: ['POST'])]
    #[OA\Post(
        path: '/api/admin/users/{id}/unban',
        summary: 'Débannir un utilisateur',
        security: [['cookieAuth' => []]]
    )]
    #[OA\Parameter(
        name: 'id',
        in: 'path',
        required: true,
        schema: new OA\Schema(type: 'integer')
    )]
    #[OA\Response(
        response: 200,
        description: 'Utilisateur débanni avec succès',
        content: new OA\JsonContent(
            properties: [
                new OA\Property(property: 'success', type: 'boolean', example: true),
                new OA\Property(property: 'message', type: 'string', example: 'Utilisateur débanni avec succès'),
            ]
        )
    )]
    #[OA\Response(response: 404, description: 'Utilisateur non trouvé')]
    public function unbanUser(int $id): JsonResponse
    {
        $user = $this->userRepository->find($id);

        if (!$user) {
            return $this->json(['message' => 'Utilisateur non trouvé'], Response::HTTP_NOT_FOUND);
        }

        /** @var User $admin */
        $admin = $this->getUser();

        try {
            $this->moderationService->unbanUser($user, $admin);

            return $this->json([
                'success' => true,
                'message' => 'Utilisateur débanni avec succès',
            ], Response::HTTP_OK);

        } catch (\InvalidArgumentException $e) {
            return $this->json([
                'success' => false,
                'message' => $e->getMessage()
            ], Response::HTTP_BAD_REQUEST);
        }
    }

    /**
     * Modifier les rôles d'un utilisateur
     */
    #[Route('/{id}/roles', name: 'update_roles', methods: ['PATCH'])]
    #[OA\Patch(
        path: '/api/admin/users/{id}/roles',
        summary: 'Modifier les rôles d\'un utilisateur',
        security: [['cookieAuth' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(ref: new Model(type: UpdateUserRolesDTO::class))
        )
    )]
    #[OA\Parameter(
        name: 'id',
        in: 'path',
        required: true,
        schema: new OA\Schema(type: 'integer')
    )]
    #[OA\Response(
        response: 200,
        description: 'Rôles mis à jour',
        content: new OA\JsonContent(
            properties: [
                new OA\Property(property: 'success', type: 'boolean', example: true),
                new OA\Property(property: 'message', type: 'string'),
                new OA\Property(property: 'newRoles', type: 'array', items: new OA\Items(type: 'string')),
            ]
        )
    )]
    #[OA\Response(response: 404, description: 'Utilisateur non trouvé')]
    public function updateRoles(
        int $id,
        #[MapRequestPayload] UpdateUserRolesDTO $dto
    ): JsonResponse {
        $user = $this->userRepository->find($id);

        if (!$user) {
            return $this->json(['message' => 'Utilisateur non trouvé'], Response::HTTP_NOT_FOUND);
        }

        /** @var User $admin */
        $admin = $this->getUser();

        // S'assurer que ROLE_USER est présent
        $dto->ensureUserRole();
        $dto->normalizeRoles();

        try {
            $this->moderationService->updateUserRoles($user, $dto->roles, $admin);

            return $this->json([
                'success' => true,
                'message' => 'Rôles mis à jour avec succès',
                'newRoles' => $dto->roles,
            ], Response::HTTP_OK);

        } catch (\InvalidArgumentException $e) {
            return $this->json([
                'success' => false,
                'message' => $e->getMessage()
            ], Response::HTTP_BAD_REQUEST);
        }
    }

    /**
     * Supprimer un utilisateur (RGPD)
     */
    #[Route('/{id}', name: 'delete', methods: ['DELETE'])]
    #[OA\Delete(
        path: '/api/admin/users/{id}',
        summary: 'Supprimer définitivement un utilisateur',
        security: [['cookieAuth' => []]]
    )]
    #[OA\Parameter(
        name: 'id',
        in: 'path',
        required: true,
        schema: new OA\Schema(type: 'integer')
    )]
    #[OA\RequestBody(
        required: true,
        content: new OA\JsonContent(
            properties: [
                new OA\Property(property: 'reason', description: 'Raison de la suppression', type: 'string'),
            ]
        )
    )]
    #[OA\Response(
        response: 200,
        description: 'Utilisateur supprimé',
        content: new OA\JsonContent(
            properties: [
                new OA\Property(property: 'success', type: 'boolean', example: true),
                new OA\Property(property: 'message', type: 'string'),
            ]
        )
    )]
    #[OA\Response(response: 404, description: 'Utilisateur non trouvé')]
    public function deleteUser(int $id, Request $request): JsonResponse
    {
        $user = $this->userRepository->find($id);

        if (!$user) {
            return $this->json(['message' => 'Utilisateur non trouvé'], Response::HTTP_NOT_FOUND);
        }

        /** @var User $admin */
        $admin = $this->getUser();

        $data = json_decode($request->getContent(), true);
        $reason = $data['reason'] ?? 'Suppression par un administrateur';

        try {
            $this->moderationService->deleteUser($user, $admin, $reason);

            return $this->json([
                'success' => true,
                'message' => 'Utilisateur supprimé avec succès',
            ], Response::HTTP_OK);

        } catch (\InvalidArgumentException $e) {
            return $this->json([
                'success' => false,
                'message' => $e->getMessage()
            ], Response::HTTP_BAD_REQUEST);
        }
    }

    /**
     * Historique d'activité d'un utilisateur
     */
    #[Route('/{id}/activity', name: 'activity', methods: ['GET'])]
    #[OA\Get(
        path: '/api/admin/users/{id}/activity',
        summary: 'Historique d\'activité d\'un utilisateur',
        security: [['cookieAuth' => []]]
    )]
    #[OA\Parameter(
        name: 'id',
        in: 'path',
        required: true,
        schema: new OA\Schema(type: 'integer')
    )]
    #[OA\Response(
        response: 200,
        description: 'Activité de l\'utilisateur',
        content: new OA\JsonContent(
            properties: [
                new OA\Property(property: 'voyages', type: 'object'),
                new OA\Property(property: 'demandes', type: 'object'),
                new OA\Property(property: 'messages', type: 'object'),
                new OA\Property(property: 'avis', type: 'object'),
                new OA\Property(property: 'signalements', type: 'object'),
            ]
        )
    )]
    #[OA\Response(response: 404, description: 'Utilisateur non trouvé')]
    public function getUserActivity(int $id): JsonResponse
    {
        $user = $this->userRepository->find($id);

        if (!$user) {
            return $this->json(['message' => 'Utilisateur non trouvé'], Response::HTTP_NOT_FOUND);
        }

        $activity = [
            'user' => [
                'id' => $user->getId(),
                'email' => $user->getEmail(),
                'nom' => $user->getNom(),
                'prenom' => $user->getPrenom(),
                'createdAt' => $user->getCreatedAt()->format('Y-m-d H:i:s'),
            ],
            'voyages' => [
                'total' => $user->getVoyages()->count(),
                'actifs' => $user->getVoyages()->filter(fn($v) => $v->getStatut() === 'actif')->count(),
                'termines' => $user->getVoyages()->filter(fn($v) => $v->getStatut() === 'termine')->count(),
            ],
            'demandes' => [
                'total' => $user->getDemandes()->count(),
                'enCours' => $user->getDemandes()->filter(fn($d) => $d->getStatut() === 'en_recherche')->count(),
                'traitees' => $user->getDemandes()->filter(fn($d) => $d->getStatut() === 'voyageur_trouve')->count(),
            ],
            'messages' => [
                'envoyes' => $user->getMessagesEnvoyes()->count(),
                'recus' => $user->getMessagesRecus()->count(),
            ],
            'avis' => [
                'donnes' => $user->getAvisDonnes()->count(),
                'recus' => $user->getAvisRecus()->count(),
            ],
            'signalements' => [
                'effectues' => $user->getSignalements()->count(),
                'recus' => $user->getSignalementsRecus()->count(),
            ],
        ];

        return $this->json($activity, Response::HTTP_OK);
    }

    /**
     * Historique des actions admin sur un utilisateur
     */
    #[Route('/{id}/admin-logs', name: 'admin_logs', methods: ['GET'])]
    #[OA\Get(
        path: '/api/admin/users/{id}/admin-logs',
        summary: 'Historique des actions admin sur cet utilisateur',
        security: [['cookieAuth' => []]]
    )]
    #[OA\Parameter(
        name: 'id',
        in: 'path',
        required: true,
        schema: new OA\Schema(type: 'integer')
    )]
    #[OA\Response(response: 200, description: 'Logs admin concernant cet utilisateur')]
    public function getAdminLogs(int $id): JsonResponse
    {
        $user = $this->userRepository->find($id);

        if (!$user) {
            return $this->json(['message' => 'Utilisateur non trouvé'], Response::HTTP_NOT_FOUND);
        }

        $logs = $this->auditLogService->getTargetHistory('user', $id);

        return $this->json($logs, Response::HTTP_OK, [], ['groups' => ['admin:log:list']]);
    }

    /**
     * Recherche d'utilisateurs
     */
    #[Route('/search', name: 'search', methods: ['GET'])]
    #[OA\Get(
        path: '/api/admin/users/search',
        summary: 'Rechercher des utilisateurs',
        security: [['cookieAuth' => []]]
    )]
    #[OA\Parameter(
        name: 'q',
        description: 'Terme de recherche (nom, prénom, email)',
        in: 'query',
        required: true,
        schema: new OA\Schema(type: 'string')
    )]
    #[OA\Response(response: 200, description: 'Résultats de recherche')]
    public function search(Request $request): JsonResponse
    {
        $query = $request->query->get('q', '');

        if (strlen($query) < 2) {
            return $this->json([
                'message' => 'La recherche doit contenir au moins 2 caractères'
            ], Response::HTTP_BAD_REQUEST);
        }

        // Recherche sans filtre de visibilité pour admin
        $users = $this->userRepository->search($query);

        return $this->json($users, Response::HTTP_OK, [], ['groups' => ['admin:user:list']]);
    }
}
