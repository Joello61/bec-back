<?php

declare(strict_types=1);

namespace App\Controller;

use App\DTO\CreateAvisDTO;
use App\Entity\User;
use App\Repository\AvisRepository;
use App\Service\AvisService;
use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/api/avis', name: 'api_avis_')]
#[OA\Tag(name: 'Avis')]
#[IsGranted('ROLE_USER')]
class AvisController extends AbstractController
{
    public function __construct(
        private readonly AvisService $avisService,
        private readonly AvisRepository $avisRepository,
    ) {}

    #[Route('/user/{userId}', name: 'by_user', methods: ['GET'])]
    #[OA\Get(
        path: '/api/avis/user/{userId}',
        summary: 'Avis reçus par un utilisateur',
        security: [['cookieAuth' => []]]
    )]
    #[OA\Parameter(name: 'userId', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))]
    #[OA\Response(response: 200, description: 'Liste des avis')]
    public function byUser(int $userId): JsonResponse
    {
        $avis = $this->avisService->getAvisByUser($userId);
        $stats = $this->avisService->getStatsByUser($userId);

        return $this->json([
            'avis' => $avis,
            'stats' => $stats
        ], Response::HTTP_OK, [], ['groups' => ['avis:list']]);
    }

    #[Route('', name: 'create', methods: ['POST'])]
    #[OA\Post(
        path: '/api/avis',
        summary: 'Créer un avis',
        security: [['cookieAuth' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(ref: new Model(type: CreateAvisDTO::class))
        )
    )]
    #[OA\Response(response: 201, description: 'Avis créé')]
    #[OA\Response(response: 400, description: 'Données invalides')]
    public function create(
        #[MapRequestPayload] CreateAvisDTO $dto
    ): JsonResponse {
        /* @var User $user*/
        $user = $this->getUser();

        $this->denyAccessUnlessGranted('AVIS_CREATE');

        $avis = $this->avisService->createAvis($dto, $user);
        return $this->json($avis, Response::HTTP_CREATED, [], ['groups' => ['avis:read']]);
    }

    #[Route('/{id}', name: 'update', methods: ['PUT'])]
    #[IsGranted('ROLE_USER')]
    #[OA\Put(
        path: '/api/avis/{id}',
        summary: 'Modifier un avis',
        security: [['cookieAuth' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                properties: [
                    new OA\Property(property: 'note', type: 'integer', maximum: 5, minimum: 1),
                    new OA\Property(property: 'commentaire', type: 'string', maxLength: 500)
                ]
            )
        )
    )]
    #[OA\Response(response: 200, description: 'Avis modifié')]
    public function update(int $id, #[MapRequestPayload] CreateAvisDTO $dto): JsonResponse
    {
        $avis = $this->avisRepository->find($id);

        if (!$avis) {
            throw $this->createNotFoundException('Avis non trouvé');
        }

        $this->denyAccessUnlessGranted('AVIS_EDIT', $avis);

        $updatedAvis = $this->avisService->updateAvis($id, $dto);
        return $this->json($updatedAvis, Response::HTTP_OK, [], ['groups' => ['avis:read']]);
    }

    #[Route('/{id}', name: 'delete', methods: ['DELETE'])]
    #[IsGranted('ROLE_USER')]
    #[OA\Delete(
        path: '/api/avis/{id}',
        summary: 'Supprimer un avis',
        security: [['cookieAuth' => []]]
    )]
    #[OA\Response(response: 204, description: 'Avis supprimé')]
    public function delete(int $id): JsonResponse
    {
        $avis = $this->avisRepository->find($id);

        if (!$avis) {
            throw $this->createNotFoundException('Avis non trouvé');
        }

        $this->denyAccessUnlessGranted('AVIS_DELETE', $avis);

        $this->avisService->deleteAvis($id);
        return $this->json(null, Response::HTTP_NO_CONTENT);
    }
}

