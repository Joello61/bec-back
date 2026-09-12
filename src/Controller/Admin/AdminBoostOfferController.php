<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\DTO\Admin\CreateBoostOfferDTO;
use App\DTO\Admin\UpdateBoostOfferDTO;
use App\Entity\User;
use App\Repository\BoostOfferRepository;
use App\Service\Admin\CatalogAdminService;
use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/api/admin/boost-offers', name: 'api_admin_boost_offers_')]
#[OA\Tag(name: 'Admin - Catalogue')]
#[IsGranted('ROLE_ADMIN')]
class AdminBoostOfferController extends AbstractController
{
    public function __construct(
        private readonly BoostOfferRepository $boostOfferRepository,
        private readonly CatalogAdminService $catalogAdminService,
    ) {}

    #[Route('', name: 'list', methods: ['GET'])]
    #[OA\Get(path: '/api/admin/boost-offers', summary: 'Catalogue complet des offres de boost (admin)', security: [['cookieAuth' => []]])]
    #[OA\Response(response: 200, description: 'Liste des offres')]
    public function list(): JsonResponse
    {
        $offers = $this->boostOfferRepository->findAllForAdmin();

        return $this->json($offers, Response::HTTP_OK, [], ['groups' => ['admin:boost_offer:read']]);
    }

    #[Route('', name: 'create', methods: ['POST'])]
    #[OA\Post(
        path: '/api/admin/boost-offers',
        summary: 'Créer une offre de boost',
        security: [['cookieAuth' => []]],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: new Model(type: CreateBoostOfferDTO::class)))
    )]
    #[OA\Response(response: 201, description: 'Offre créée')]
    public function create(#[MapRequestPayload] CreateBoostOfferDTO $dto): JsonResponse
    {
        /** @var User $admin */
        $admin = $this->getUser();

        try {
            $offer = $this->catalogAdminService->createBoostOffer($dto, $admin);

            return $this->json($offer, Response::HTTP_CREATED, [], ['groups' => ['admin:boost_offer:read']]);
        } catch (\InvalidArgumentException $e) {
            return $this->json(['message' => $e->getMessage()], Response::HTTP_BAD_REQUEST);
        }
    }

    #[Route('/{id}', name: 'update', requirements: ['id' => '\d+'], methods: ['PUT'])]
    #[OA\Put(
        path: '/api/admin/boost-offers/{id}',
        summary: 'Modifier une offre de boost',
        security: [['cookieAuth' => []]],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: new Model(type: UpdateBoostOfferDTO::class)))
    )]
    #[OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))]
    #[OA\Response(response: 200, description: 'Offre mise à jour')]
    #[OA\Response(response: 400, description: 'Offre introuvable')]
    public function update(int $id, #[MapRequestPayload] UpdateBoostOfferDTO $dto): JsonResponse
    {
        /** @var User $admin */
        $admin = $this->getUser();

        try {
            $offer = $this->catalogAdminService->updateBoostOffer($id, $dto, $admin);

            return $this->json($offer, Response::HTTP_OK, [], ['groups' => ['admin:boost_offer:read']]);
        } catch (\InvalidArgumentException $e) {
            return $this->json(['message' => $e->getMessage()], Response::HTTP_BAD_REQUEST);
        }
    }

    #[Route('/{id}', name: 'delete', requirements: ['id' => '\d+'], methods: ['DELETE'])]
    #[OA\Delete(
        path: '/api/admin/boost-offers/{id}',
        summary: 'Supprimer une offre de boost (soft-delete)',
        security: [['cookieAuth' => []]]
    )]
    #[OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))]
    #[OA\Response(response: 200, description: 'Offre supprimée')]
    #[OA\Response(response: 400, description: 'Offre introuvable')]
    public function delete(int $id): JsonResponse
    {
        /** @var User $admin */
        $admin = $this->getUser();

        try {
            $this->catalogAdminService->deleteBoostOffer($id, $admin);

            return $this->json(['success' => true, 'message' => 'Offre supprimée avec succès'], Response::HTTP_OK);
        } catch (\InvalidArgumentException $e) {
            return $this->json(['message' => $e->getMessage()], Response::HTTP_BAD_REQUEST);
        }
    }
}
