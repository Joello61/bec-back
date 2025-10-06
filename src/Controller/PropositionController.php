<?php

declare(strict_types=1);

namespace App\Controller;

use App\DTO\CreatePropositionDTO;
use App\DTO\RespondPropositionDTO;
use App\Entity\User;
use App\Service\PropositionService;
use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/api/propositions', name: 'api_proposition_')]
#[OA\Tag(name: 'Propositions')]
#[IsGranted('ROLE_USER')]
class PropositionController extends AbstractController
{
    public function __construct(
        private readonly PropositionService $propositionService
    ) {}

    #[Route('/voyage/{voyageId}', name: 'create', methods: ['POST'])]
    #[OA\Post(
        path: '/api/propositions/voyage/{voyageId}',
        summary: 'Faire une proposition sur un voyage',
        security: [['cookieAuth' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(ref: new Model(type: CreatePropositionDTO::class))
        )
    )]
    #[OA\Parameter(name: 'voyageId', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))]
    #[OA\Response(response: 201, description: 'Proposition créée')]
    #[OA\Response(response: 400, description: 'Données invalides')]
    #[OA\Response(response: 404, description: 'Voyage non trouvé')]
    public function create(
        int $voyageId,
        #[MapRequestPayload] CreatePropositionDTO $dto
    ): JsonResponse {
        /** @var User $user */
        $user = $this->getUser();
        $proposition = $this->propositionService->createProposition($voyageId, $dto, $user);

        return $this->json($proposition, Response::HTTP_CREATED, [], ['groups' => ['proposition:read']]);
    }

    #[Route('/{id}/respond', name: 'respond', methods: ['PATCH'])]
    #[OA\Patch(
        path: '/api/propositions/{id}/respond',
        summary: 'Répondre à une proposition (accepter/refuser)',
        security: [['cookieAuth' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(ref: new Model(type: RespondPropositionDTO::class))
        )
    )]
    #[OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))]
    #[OA\Response(response: 200, description: 'Proposition mise à jour')]
    #[OA\Response(response: 400, description: 'Action invalide')]
    #[OA\Response(response: 404, description: 'Proposition non trouvée')]
    public function respond(
        int $id,
        #[MapRequestPayload] RespondPropositionDTO $dto
    ): JsonResponse {
        /** @var User $user */
        $user = $this->getUser();
        $proposition = $this->propositionService->respondToProposition($id, $dto, $user);

        return $this->json($proposition, Response::HTTP_OK, [], ['groups' => ['proposition:read']]);
    }

    #[Route('/voyage/{voyageId}', name: 'by_voyage', methods: ['GET'])]
    #[OA\Get(
        path: '/api/propositions/voyage/{voyageId}',
        summary: 'Récupérer toutes les propositions pour un voyage',
        security: [['cookieAuth' => []]]
    )]
    #[OA\Parameter(name: 'voyageId', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))]
    #[OA\Response(response: 200, description: 'Liste des propositions')]
    public function byVoyage(int $voyageId): JsonResponse
    {
        $propositions = $this->propositionService->getPropositionsByVoyage($voyageId);

        return $this->json($propositions, Response::HTTP_OK, [], ['groups' => ['proposition:list']]);
    }

    #[Route('/voyage/{voyageId}/accepted', name: 'accepted_by_voyage', methods: ['GET'])]
    #[OA\Get(
        path: '/api/propositions/voyage/{voyageId}/accepted',
        summary: 'Récupérer les propositions acceptées pour un voyage',
        security: [['cookieAuth' => []]]
    )]
    #[OA\Parameter(name: 'voyageId', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))]
    #[OA\Response(response: 200, description: 'Liste des propositions acceptées')]
    public function acceptedByVoyage(int $voyageId): JsonResponse
    {
        $propositions = $this->propositionService->getAcceptedPropositionsByVoyage($voyageId);

        return $this->json($propositions, Response::HTTP_OK, [], ['groups' => ['proposition:list']]);
    }

    #[Route('/me/sent', name: 'my_sent', methods: ['GET'])]
    #[OA\Get(
        path: '/api/propositions/me/sent',
        summary: 'Récupérer mes propositions envoyées',
        security: [['cookieAuth' => []]]
    )]
    #[OA\Response(response: 200, description: 'Liste de mes propositions')]
    public function mySent(): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();
        $propositions = $this->propositionService->getPropositionsByClient($user->getId());

        return $this->json($propositions, Response::HTTP_OK, [], ['groups' => ['proposition:list']]);
    }

    #[Route('/me/received', name: 'my_received', methods: ['GET'])]
    #[OA\Get(
        path: '/api/propositions/me/received',
        summary: 'Récupérer mes propositions reçues',
        security: [['cookieAuth' => []]]
    )]
    #[OA\Response(response: 200, description: 'Liste des propositions reçues')]
    public function myReceived(): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();
        $propositions = $this->propositionService->getPropositionsByVoyageur($user->getId());

        return $this->json($propositions, Response::HTTP_OK, [], ['groups' => ['proposition:list']]);
    }

    #[Route('/me/pending-count', name: 'my_pending_count', methods: ['GET'])]
    #[OA\Get(
        path: '/api/propositions/me/pending-count',
        summary: 'Compter mes propositions en attente',
        security: [['cookieAuth' => []]]
    )]
    #[OA\Response(
        response: 200,
        description: 'Nombre de propositions en attente',
        content: new OA\JsonContent(
            properties: [
                new OA\Property(property: 'count', type: 'integer')
            ]
        )
    )]
    public function myPendingCount(): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();
        $count = $this->propositionService->countPendingByVoyageur($user->getId());

        return $this->json(['count' => $count], Response::HTTP_OK);
    }
}
