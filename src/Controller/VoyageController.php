<?php

declare(strict_types=1);

namespace App\Controller;

use App\DTO\CreateVoyageDTO;
use App\DTO\UpdateVoyageDTO;
use App\Service\VoyageService;
use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/api/voyages', name: 'api_voyage_')]
#[OA\Tag(name: 'Voyages')]
class VoyageController extends AbstractController
{
    public function __construct(
        private readonly VoyageService $voyageService
    ) {}

    #[Route('', name: 'list', methods: ['GET'])]
    #[IsGranted('ROLE_USER')]
    #[OA\Get(
        path: '/api/voyages',
        summary: 'Liste des voyages',
        security: [['cookieAuth' => []]]
    )]
    #[OA\Parameter(name: 'page', in: 'query', schema: new OA\Schema(type: 'integer', default: 1))]
    #[OA\Parameter(name: 'limit', in: 'query', schema: new OA\Schema(type: 'integer', default: 10))]
    #[OA\Parameter(name: 'villeDepart', in: 'query', schema: new OA\Schema(type: 'string'))]
    #[OA\Parameter(name: 'villeArrivee', in: 'query', schema: new OA\Schema(type: 'string'))]
    #[OA\Parameter(name: 'dateDepart', in: 'query', schema: new OA\Schema(type: 'string', format: 'date'))]
    #[OA\Parameter(name: 'statut', in: 'query', schema: new OA\Schema(type: 'string'))]
    #[OA\Response(response: 200, description: 'Liste paginée des voyages')]
    public function list(Request $request): JsonResponse
    {
        $page = $request->query->getInt('page', 1);
        $limit = $request->query->getInt('limit', 10);
        $filters = [
            'villeDepart' => $request->query->get('villeDepart'),
            'villeArrivee' => $request->query->get('villeArrivee'),
            'dateDepart' => $request->query->get('dateDepart'),
            'statut' => $request->query->get('statut'),
        ];

        $result = $this->voyageService->getPaginatedVoyages($page, $limit, $filters);

        return $this->json($result, Response::HTTP_OK, [], ['groups' => ['voyage:list']]);
    }

    #[Route('/{id}', name: 'show', methods: ['GET'])]
    #[IsGranted('ROLE_USER')]
    #[OA\Get(
        path: '/api/voyages/{id}',
        summary: 'Détails d\'un voyage',
        security: [['cookieAuth' => []]]
    )]
    #[OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))]
    #[OA\Response(response: 200, description: 'Détails du voyage')]
    #[OA\Response(response: 404, description: 'Voyage non trouvé')]
    public function show(int $id): JsonResponse
    {
        $voyage = $this->voyageService->getVoyage($id);

        return $this->json($voyage, Response::HTTP_OK, [], ['groups' => ['voyage:read']]);
    }

    #[Route('', name: 'create', methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    #[OA\Post(
        path: '/api/voyages',
        summary: 'Créer un voyage',
        security: [['cookieAuth' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(ref: new Model(type: CreateVoyageDTO::class))
        )
    )]
    #[OA\Response(response: 201, description: 'Voyage créé')]
    #[OA\Response(response: 400, description: 'Données invalides')]
    public function create(
        #[MapRequestPayload] CreateVoyageDTO $dto
    ): JsonResponse {
        $voyage = $this->voyageService->createVoyage($dto, $this->getUser());

        return $this->json($voyage, Response::HTTP_CREATED, [], ['groups' => ['voyage:read']]);
    }

    #[Route('/{id}', name: 'update', methods: ['PUT'])]
    #[IsGranted('VOYAGE_EDIT', subject: 'id')]
    #[OA\Put(
        path: '/api/voyages/{id}',
        summary: 'Modifier un voyage',
        security: [['cookieAuth' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(ref: new Model(type: UpdateVoyageDTO::class))
        )
    )]
    #[OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))]
    #[OA\Response(response: 200, description: 'Voyage mis à jour')]
    #[OA\Response(response: 403, description: 'Accès refusé')]
    #[OA\Response(response: 404, description: 'Voyage non trouvé')]
    public function update(
        int $id,
        #[MapRequestPayload] UpdateVoyageDTO $dto
    ): JsonResponse {
        $voyage = $this->voyageService->updateVoyage($id, $dto);

        return $this->json($voyage, Response::HTTP_OK, [], ['groups' => ['voyage:read']]);
    }

    #[Route('/{id}/statut', name: 'update_status', methods: ['PATCH'])]
    #[IsGranted('VOYAGE_EDIT', subject: 'id')]
    #[OA\Patch(
        path: '/api/voyages/{id}/statut',
        summary: 'Changer le statut d\'un voyage',
        security: [['cookieAuth' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['statut'],
                properties: [
                    new OA\Property(property: 'statut', type: 'string', enum: ['actif', 'complet', 'termine', 'annule'])
                ]
            )
        )
    )]
    #[OA\Response(response: 200, description: 'Statut mis à jour')]
    public function updateStatus(int $id, Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);
        $statut = $data['statut'] ?? null;

        if (!$statut || !in_array($statut, ['actif', 'complet', 'termine', 'annule'])) {
            return $this->json(['message' => 'Statut invalide'], Response::HTTP_BAD_REQUEST);
        }

        $voyage = $this->voyageService->updateStatut($id, $statut);

        return $this->json($voyage, Response::HTTP_OK, [], ['groups' => ['voyage:read']]);
    }

    #[Route('/{id}', name: 'delete', methods: ['DELETE'])]
    #[IsGranted('VOYAGE_DELETE', subject: 'id')]
    #[OA\Delete(
        path: '/api/voyages/{id}',
        summary: 'Supprimer un voyage',
        security: [['cookieAuth' => []]]
    )]
    #[OA\Response(response: 204, description: 'Voyage supprimé')]
    public function delete(int $id): JsonResponse
    {
        $this->voyageService->deleteVoyage($id);

        return $this->json(null, Response::HTTP_NO_CONTENT);
    }

    #[Route('/user/{userId}', name: 'by_user', methods: ['GET'])]
    #[IsGranted('ROLE_USER')]
    #[OA\Get(
        path: '/api/voyages/user/{userId}',
        summary: 'Voyages d\'un utilisateur',
        security: [['cookieAuth' => []]]
    )]
    #[OA\Response(response: 200, description: 'Liste des voyages')]
    public function byUser(int $userId): JsonResponse
    {
        $voyages = $this->voyageService->getVoyagesByUser($userId);

        return $this->json($voyages, Response::HTTP_OK, [], ['groups' => ['voyage:list']]);
    }
}
