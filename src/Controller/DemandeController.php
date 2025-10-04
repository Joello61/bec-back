<?php

declare(strict_types=1);

namespace App\Controller;

use App\DTO\CreateDemandeDTO;
use App\DTO\UpdateDemandeDTO;
use App\Service\DemandeService;
use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/api/demandes', name: 'api_demande_')]
#[OA\Tag(name: 'Demandes')]
class DemandeController extends AbstractController
{
    public function __construct(
        private readonly DemandeService $demandeService
    ) {}

    #[Route('', name: 'list', methods: ['GET'])]
    #[IsGranted('ROLE_USER')]
    #[OA\Get(path: '/api/demandes', summary: 'Liste des demandes', security: [['cookieAuth' => []]])]
    #[OA\Parameter(name: 'page', in: 'query', schema: new OA\Schema(type: 'integer', default: 1))]
    #[OA\Parameter(name: 'limit', in: 'query', schema: new OA\Schema(type: 'integer', default: 10))]
    #[OA\Parameter(name: 'villeDepart', in: 'query', schema: new OA\Schema(type: 'string'))]
    #[OA\Parameter(name: 'villeArrivee', in: 'query', schema: new OA\Schema(type: 'string'))]
    #[OA\Parameter(name: 'statut', in: 'query', schema: new OA\Schema(type: 'string'))]
    #[OA\Response(response: 200, description: 'Liste paginée')]
    public function list(Request $request): JsonResponse
    {
        $page = $request->query->getInt('page', 1);
        $limit = $request->query->getInt('limit', 10);
        $filters = [
            'villeDepart' => $request->query->get('villeDepart'),
            'villeArrivee' => $request->query->get('villeArrivee'),
            'statut' => $request->query->get('statut'),
        ];

        $result = $this->demandeService->getPaginatedDemandes($page, $limit, $filters);

        return $this->json($result, Response::HTTP_OK, [], ['groups' => ['demande:list']]);
    }

    #[Route('/{id}', name: 'show', methods: ['GET'])]
    #[IsGranted('ROLE_USER')]
    #[OA\Get(path: '/api/demandes/{id}', summary: 'Détails d\'une demande', security: [['cookieAuth' => []]])]
    #[OA\Response(response: 200, description: 'Détails')]
    #[OA\Response(response: 404, description: 'Non trouvé')]
    public function show(int $id): JsonResponse
    {
        $demande = $this->demandeService->getDemande($id);

        return $this->json($demande, Response::HTTP_OK, [], ['groups' => ['demande:read']]);
    }

    #[Route('', name: 'create', methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    #[OA\Post(
        path: '/api/demandes',
        summary: 'Créer une demande',
        security: [['cookieAuth' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(ref: new Model(type: CreateDemandeDTO::class))
        )
    )]
    #[OA\Response(response: 201, description: 'Demande créée')]
    public function create(
        #[MapRequestPayload] CreateDemandeDTO $dto
    ): JsonResponse {
        $demande = $this->demandeService->createDemande($dto, $this->getUser());

        return $this->json($demande, Response::HTTP_CREATED, [], ['groups' => ['demande:read']]);
    }

    #[Route('/{id}', name: 'update', methods: ['PUT'])]
    #[IsGranted('DEMANDE_EDIT', subject: 'id')]
    #[OA\Put(
        path: '/api/demandes/{id}',
        summary: 'Modifier une demande',
        security: [['cookieAuth' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(ref: new Model(type: UpdateDemandeDTO::class))
        )
    )]
    #[OA\Response(response: 200, description: 'Demande mise à jour')]
    public function update(
        int $id,
        #[MapRequestPayload] UpdateDemandeDTO $dto
    ): JsonResponse {
        $demande = $this->demandeService->updateDemande($id, $dto);

        return $this->json($demande, Response::HTTP_OK, [], ['groups' => ['demande:read']]);
    }

    #[Route('/{id}/statut', name: 'update_status', methods: ['PATCH'])]
    #[IsGranted('DEMANDE_EDIT', subject: 'id')]
    #[OA\Patch(
        path: '/api/demandes/{id}/statut',
        summary: 'Changer le statut',
        security: [['cookieAuth' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['statut'],
                properties: [
                    new OA\Property(property: 'statut', type: 'string', enum: ['en_recherche', 'voyageur_trouve', 'annulee'])
                ]
            )
        )
    )]
    #[OA\Response(response: 200, description: 'Statut mis à jour')]
    public function updateStatus(int $id, Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);
        $statut = $data['statut'] ?? null;

        if (!$statut || !in_array($statut, ['en_recherche', 'voyageur_trouve', 'annulee'])) {
            return $this->json(['message' => 'Statut invalide'], Response::HTTP_BAD_REQUEST);
        }

        $demande = $this->demandeService->updateStatut($id, $statut);

        return $this->json($demande, Response::HTTP_OK, [], ['groups' => ['demande:read']]);
    }

    #[Route('/{id}', name: 'delete', methods: ['DELETE'])]
    #[IsGranted('DEMANDE_DELETE', subject: 'id')]
    #[OA\Delete(path: '/api/demandes/{id}', summary: 'Supprimer une demande', security: [['cookieAuth' => []]])]
    #[OA\Response(response: 204, description: 'Demande supprimée')]
    public function delete(int $id): JsonResponse
    {
        $this->demandeService->deleteDemande($id);

        return $this->json(null, Response::HTTP_NO_CONTENT);
    }

    #[Route('/user/{userId}', name: 'by_user', methods: ['GET'])]
    #[IsGranted('ROLE_USER')]
    #[OA\Get(path: '/api/demandes/user/{userId}', summary: 'Demandes d\'un utilisateur', security: [['cookieAuth' => []]])]
    #[OA\Response(response: 200, description: 'Liste')]
    public function byUser(int $userId): JsonResponse
    {
        $demandes = $this->demandeService->getDemandesByUser($userId);

        return $this->json($demandes, Response::HTTP_OK, [], ['groups' => ['demande:list']]);
    }
}
