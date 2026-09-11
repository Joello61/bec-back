<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\DTO\Admin\DeleteContentDTO;
use App\Entity\User;
use App\Repository\AvisRepository;
use App\Repository\DemandeRepository;
use App\Repository\UserRepository;
use App\Repository\VoyageRepository;
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

#[Route('/api/admin/moderation', name: 'api_admin_moderation_')]
#[OA\Tag(name: 'Admin - Modération')]
#[IsGranted('ROLE_ADMIN')]
class AdminModerationController extends AbstractController
{
    public function __construct(
        private readonly ModerationService $moderationService,
        private readonly UserRepository $userRepository,
        private readonly AvisRepository $avisRepository,
        private readonly VoyageRepository $voyageRepository,
        private readonly DemandeRepository $demandeRepository,
    ) {}

    /**
     * Liste paginée des voyages (modération) - endpoint dédié admin, distinct de
     * GET /api/voyages (VoyageController::list()) : expose l'email du voyageur sans
     * condition (groupe admin:voyage:list), jamais les préférences showEmail de
     * VisibilityService, et n'exclut jamais un voyage dont le propriétaire a désactivé
     * showInSearchResults (findAllPaginatedAdmin - la modération doit tout voir).
     * Phase 13/Lot B6 du plan de correction (bec-docs).
     */
    #[Route('/voyages', name: 'list_voyages', methods: ['GET'])]
    #[OA\Get(
        path: '/api/admin/moderation/voyages',
        summary: 'Liste des voyages (modération)',
        security: [['cookieAuth' => []]]
    )]
    #[OA\Parameter(name: 'page', in: 'query', schema: new OA\Schema(type: 'integer', default: 1))]
    #[OA\Parameter(name: 'limit', in: 'query', schema: new OA\Schema(type: 'integer', default: 20))]
    #[OA\Parameter(name: 'statut', in: 'query', schema: new OA\Schema(type: 'string'))]
    #[OA\Parameter(
        name: 'search',
        description: 'Recherche sur nom/prénom/email du voyageur',
        in: 'query',
        schema: new OA\Schema(type: 'string', maxLength: 100)
    )]
    #[OA\Response(response: 200, description: 'Liste paginée')]
    public function listVoyages(Request $request): JsonResponse
    {
        $page = $request->query->getInt('page', 1);
        $limit = min($request->query->getInt('limit', 20), 50);

        $filters = array_filter([
            'statut' => $request->query->get('statut'),
            'search' => substr((string) $request->query->get('search', ''), 0, 100) ?: null,
        ], fn($value) => $value !== null);

        $result = $this->voyageRepository->findAllPaginatedAdmin($page, $limit, $filters);

        return $this->json($result, Response::HTTP_OK, [], ['groups' => ['voyage:list', 'admin:voyage:list']]);
    }

    /**
     * Liste paginée des demandes (modération) - même patron que listVoyages() ci-dessus.
     */
    #[Route('/demandes', name: 'list_demandes', methods: ['GET'])]
    #[OA\Get(
        path: '/api/admin/moderation/demandes',
        summary: 'Liste des demandes (modération)',
        security: [['cookieAuth' => []]]
    )]
    #[OA\Parameter(name: 'page', in: 'query', schema: new OA\Schema(type: 'integer', default: 1))]
    #[OA\Parameter(name: 'limit', in: 'query', schema: new OA\Schema(type: 'integer', default: 20))]
    #[OA\Parameter(name: 'statut', in: 'query', schema: new OA\Schema(type: 'string'))]
    #[OA\Parameter(
        name: 'search',
        description: 'Recherche sur nom/prénom/email du client',
        in: 'query',
        schema: new OA\Schema(type: 'string', maxLength: 100)
    )]
    #[OA\Response(response: 200, description: 'Liste paginée')]
    public function listDemandes(Request $request): JsonResponse
    {
        $page = $request->query->getInt('page', 1);
        $limit = min($request->query->getInt('limit', 20), 50);

        $filters = array_filter([
            'statut' => $request->query->get('statut'),
            'search' => substr((string) $request->query->get('search', ''), 0, 100) ?: null,
        ], fn($value) => $value !== null);

        $result = $this->demandeRepository->findAllPaginatedAdmin($page, $limit, $filters);

        return $this->json($result, Response::HTTP_OK, [], ['groups' => ['demande:list', 'admin:demande:list']]);
    }

    /**
     * Liste paginée des avis (modération)
     */
    #[Route('/avis', name: 'list_avis', methods: ['GET'])]
    #[OA\Get(
        path: '/api/admin/moderation/avis',
        summary: 'Liste des avis (modération)',
        security: [['cookieAuth' => []]]
    )]
    #[OA\Parameter(name: 'page', in: 'query', schema: new OA\Schema(type: 'integer', default: 1))]
    #[OA\Parameter(name: 'limit', in: 'query', schema: new OA\Schema(type: 'integer', default: 20))]
    #[OA\Parameter(
        name: 'maxNote',
        description: 'Filtrer les avis dont la note est inférieure ou égale à cette valeur',
        in: 'query',
        schema: new OA\Schema(type: 'integer')
    )]
    #[OA\Response(response: 200, description: 'Liste paginée')]
    public function listAvis(Request $request): JsonResponse
    {
        $page = $request->query->getInt('page', 1);
        $limit = min($request->query->getInt('limit', 20), 50);

        $filters = array_filter([
            'maxNote' => $request->query->get('maxNote') !== null ? $request->query->getInt('maxNote') : null,
        ], fn($value) => $value !== null);

        $result = $this->avisRepository->findAllPaginated($page, $limit, $filters);

        return $this->json($result, Response::HTTP_OK, [], ['groups' => ['admin:avis:list', 'admin:user:list']]);
    }

    /**
     * Supprimer un voyage
     */
    #[Route('/voyages/{id}', name: 'delete_voyage', methods: ['DELETE'])]
    #[OA\Delete(
        path: '/api/admin/moderation/voyages/{id}',
        summary: 'Supprimer un voyage (modération)',
        security: [['cookieAuth' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(ref: new Model(type: DeleteContentDTO::class))
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
        description: 'Voyage supprimé',
        content: new OA\JsonContent(
            properties: [
                new OA\Property(property: 'success', type: 'boolean', example: true),
                new OA\Property(property: 'message', type: 'string'),
            ]
        )
    )]
    #[OA\Response(response: 404, description: 'Voyage non trouvé')]
    public function deleteVoyage(
        int $id,
        #[MapRequestPayload] DeleteContentDTO $dto
    ): JsonResponse {
        /** @var User $admin */
        $admin = $this->getUser();

        try {
            $this->moderationService->deleteVoyage($id, $admin, $dto->reason, $dto->notifyUser);

            return $this->json([
                'success' => true,
                'message' => 'Voyage supprimé avec succès',
            ], Response::HTTP_OK);

        } catch (\Exception $e) {
            return $this->json([
                'success' => false,
                'message' => $e->getMessage()
            ], Response::HTTP_BAD_REQUEST);
        }
    }

    /**
     * Supprimer une demande
     */
    #[Route('/demandes/{id}', name: 'delete_demande', methods: ['DELETE'])]
    #[OA\Delete(
        path: '/api/admin/moderation/demandes/{id}',
        summary: 'Supprimer une demande (modération)',
        security: [['cookieAuth' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(ref: new Model(type: DeleteContentDTO::class))
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
        description: 'Demande supprimée',
        content: new OA\JsonContent(
            properties: [
                new OA\Property(property: 'success', type: 'boolean', example: true),
                new OA\Property(property: 'message', type: 'string'),
            ]
        )
    )]
    #[OA\Response(response: 404, description: 'Demande non trouvée')]
    public function deleteDemande(
        int $id,
        #[MapRequestPayload] DeleteContentDTO $dto
    ): JsonResponse {
        /** @var User $admin */
        $admin = $this->getUser();

        try {
            $this->moderationService->deleteDemande($id, $admin, $dto->reason, $dto->notifyUser);

            return $this->json([
                'success' => true,
                'message' => 'Demande supprimée avec succès',
            ], Response::HTTP_OK);

        } catch (\Exception $e) {
            return $this->json([
                'success' => false,
                'message' => $e->getMessage()
            ], Response::HTTP_BAD_REQUEST);
        }
    }

    /**
     * Supprimer un avis
     */
    #[Route('/avis/{id}', name: 'delete_avis', methods: ['DELETE'])]
    #[OA\Delete(
        path: '/api/admin/moderation/avis/{id}',
        summary: 'Supprimer un avis (modération)',
        security: [['cookieAuth' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(ref: new Model(type: DeleteContentDTO::class))
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
        description: 'Avis supprimé',
        content: new OA\JsonContent(
            properties: [
                new OA\Property(property: 'success', type: 'boolean', example: true),
                new OA\Property(property: 'message', type: 'string'),
            ]
        )
    )]
    #[OA\Response(response: 404, description: 'Avis non trouvé')]
    public function deleteAvis(
        int $id,
        #[MapRequestPayload] DeleteContentDTO $dto
    ): JsonResponse {
        /** @var User $admin */
        $admin = $this->getUser();

        try {
            $this->moderationService->deleteAvis($id, $admin, $dto->reason, $dto->notifyUser);

            return $this->json([
                'success' => true,
                'message' => 'Avis supprimé avec succès',
            ], Response::HTTP_OK);

        } catch (\Exception $e) {
            return $this->json([
                'success' => false,
                'message' => $e->getMessage()
            ], Response::HTTP_BAD_REQUEST);
        }
    }

    /**
     * Supprimer un message
     */
    #[Route('/messages/{id}', name: 'delete_message', methods: ['DELETE'])]
    #[OA\Delete(
        path: '/api/admin/moderation/messages/{id}',
        summary: 'Supprimer un message (modération)',
        security: [['cookieAuth' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(ref: new Model(type: DeleteContentDTO::class))
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
        description: 'Message supprimé',
        content: new OA\JsonContent(
            properties: [
                new OA\Property(property: 'success', type: 'boolean', example: true),
                new OA\Property(property: 'message', type: 'string'),
            ]
        )
    )]
    #[OA\Response(response: 404, description: 'Message non trouvé')]
    public function deleteMessage(
        int $id,
        #[MapRequestPayload] DeleteContentDTO $dto
    ): JsonResponse {
        /** @var User $admin */
        $admin = $this->getUser();

        try {
            $this->moderationService->deleteMessage($id, $admin, $dto->reason, $dto->notifyUser);

            return $this->json([
                'success' => true,
                'message' => 'Message supprimé avec succès',
            ], Response::HTTP_OK);

        } catch (\Exception $e) {
            return $this->json([
                'success' => false,
                'message' => $e->getMessage()
            ], Response::HTTP_BAD_REQUEST);
        }
    }

    /**
     * Supprimer tous les contenus d'un utilisateur
     */
    #[Route('/users/{userId}/delete-all-content', name: 'delete_all_user_content', methods: ['DELETE'])]
    #[OA\Delete(
        path: '/api/admin/moderation/users/{userId}/delete-all-content',
        summary: 'Supprimer tous les contenus d\'un utilisateur',
        security: [['cookieAuth' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                properties: [
                    new OA\Property(property: 'reason', description: 'Raison de la suppression', type: 'string'),
                ]
            )
        )
    )]
    #[OA\Parameter(
        name: 'userId',
        in: 'path',
        required: true,
        schema: new OA\Schema(type: 'integer')
    )]
    #[OA\Response(
        response: 200,
        description: 'Contenus supprimés',
        content: new OA\JsonContent(
            properties: [
                new OA\Property(property: 'success', type: 'boolean', example: true),
                new OA\Property(property: 'message', type: 'string'),
                new OA\Property(
                    property: 'stats',
                    properties: [
                        new OA\Property(property: 'voyages', type: 'integer'),
                        new OA\Property(property: 'demandes', type: 'integer'),
                        new OA\Property(property: 'avis', type: 'integer'),
                        new OA\Property(property: 'messages', type: 'integer'),
                    ],
                    type: 'object'
                ),
            ]
        )
    )]
    #[OA\Response(response: 404, description: 'Utilisateur non trouvé')]
    public function deleteAllUserContent(int $userId, \Symfony\Component\HttpFoundation\Request $request): JsonResponse
    {
        $user = $this->userRepository->find($userId);

        if (!$user) {
            return $this->json(['message' => 'Utilisateur non trouvé'], Response::HTTP_NOT_FOUND);
        }

        /** @var User $admin */
        $admin = $this->getUser();

        $data = json_decode($request->getContent(), true);
        $reason = $data['reason'] ?? 'Suppression en masse par un administrateur';

        try {
            $stats = $this->moderationService->deleteAllUserContent($user, $admin, $reason);

            return $this->json([
                'success' => true,
                'message' => 'Tous les contenus de l\'utilisateur ont été supprimés',
                'stats' => $stats,
            ], Response::HTTP_OK);

        } catch (\Exception $e) {
            return $this->json([
                'success' => false,
                'message' => $e->getMessage()
            ], Response::HTTP_BAD_REQUEST);
        }
    }

    /**
     * Statistiques de modération
     */
    #[Route('/stats', name: 'stats', methods: ['GET'])]
    #[OA\Get(
        path: '/api/admin/moderation/stats',
        summary: 'Statistiques de modération',
        security: [['cookieAuth' => []]]
    )]
    #[OA\Response(
        response: 200,
        description: 'Statistiques',
        content: new OA\JsonContent(
            properties: [
                new OA\Property(property: 'today', type: 'object'),
                new OA\Property(property: 'week', type: 'object'),
                new OA\Property(property: 'month', type: 'object'),
            ]
        )
    )]
    public function moderationStats(): JsonResponse
    {
        // Cette méthode pourrait être enrichie avec des stats spécifiques à la modération
        // Pour l'instant, on retourne un aperçu simple

        $stats = [
            'message' => 'Statistiques de modération disponibles',
            'info' => 'Utilisez les endpoints /api/admin/dashboard et /api/admin/logs/stats pour plus de détails',
        ];

        return $this->json($stats, Response::HTTP_OK);
    }
}
