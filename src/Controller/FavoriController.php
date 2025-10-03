<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\FavoriService;
use OpenApi\Attributes as OA;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/api/favoris', name: 'api_favori_')]
#[OA\Tag(name: 'Favoris')]
#[IsGranted('ROLE_USER')]
class FavoriController extends AbstractController
{
    public function __construct(
        private readonly FavoriService $favoriService
    ) {}

    #[Route('', name: 'list', methods: ['GET'])]
    #[OA\Get(
        path: '/api/favoris',
        summary: 'Liste de mes favoris',
        security: [['cookieAuth' => []]]
    )]
    #[OA\Response(response: 200, description: 'Liste des favoris')]
    public function list(): JsonResponse
    {
        $favoris = $this->favoriService->getUserFavoris($this->getUser()->getId());

        return $this->json($favoris, Response::HTTP_OK, [], ['groups' => ['favori:list']]);
    }

    #[Route('/voyages', name: 'voyages', methods: ['GET'])]
    #[OA\Get(
        path: '/api/favoris/voyages',
        summary: 'Mes voyages favoris',
        security: [['cookieAuth' => []]]
    )]
    #[OA\Response(response: 200, description: 'Liste des voyages favoris')]
    public function voyages(): JsonResponse
    {
        $favoris = $this->favoriService->getUserFavorisVoyages($this->getUser()->getId());

        return $this->json($favoris, Response::HTTP_OK, [], ['groups' => ['favori:list']]);
    }

    #[Route('/demandes', name: 'demandes', methods: ['GET'])]
    #[OA\Get(
        path: '/api/favoris/demandes',
        summary: 'Mes demandes favorites',
        security: [['cookieAuth' => []]]
    )]
    #[OA\Response(response: 200, description: 'Liste des demandes favorites')]
    public function demandes(): JsonResponse
    {
        $favoris = $this->favoriService->getUserFavorisDemandes($this->getUser()->getId());

        return $this->json($favoris, Response::HTTP_OK, [], ['groups' => ['favori:list']]);
    }

    #[Route('/voyage/{voyageId}', name: 'add_voyage', methods: ['POST'])]
    #[OA\Post(
        path: '/api/favoris/voyage/{voyageId}',
        summary: 'Ajouter un voyage aux favoris',
        security: [['cookieAuth' => []]]
    )]
    #[OA\Parameter(name: 'voyageId', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))]
    #[OA\Response(response: 201, description: 'Voyage ajouté aux favoris')]
    #[OA\Response(response: 400, description: 'Déjà dans les favoris')]
    public function addVoyage(int $voyageId): JsonResponse
    {
        $favori = $this->favoriService->addVoyageToFavoris($this->getUser(), $voyageId);

        return $this->json($favori, Response::HTTP_CREATED, [], ['groups' => ['favori:read']]);
    }

    #[Route('/demande/{demandeId}', name: 'add_demande', methods: ['POST'])]
    #[OA\Post(
        path: '/api/favoris/demande/{demandeId}',
        summary: 'Ajouter une demande aux favoris',
        security: [['cookieAuth' => []]]
    )]
    #[OA\Parameter(name: 'demandeId', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))]
    #[OA\Response(response: 201, description: 'Demande ajoutée aux favoris')]
    #[OA\Response(response: 400, description: 'Déjà dans les favoris')]
    public function addDemande(int $demandeId): JsonResponse
    {
        $favori = $this->favoriService->addDemandeToFavoris($this->getUser(), $demandeId);

        return $this->json($favori, Response::HTTP_CREATED, [], ['groups' => ['favori:read']]);
    }

    #[Route('/{id}', name: 'remove', methods: ['DELETE'])]
    #[OA\Delete(
        path: '/api/favoris/{id}',
        summary: 'Retirer des favoris',
        security: [['cookieAuth' => []]]
    )]
    #[OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))]
    #[OA\Response(response: 204, description: 'Retiré des favoris')]
    public function remove(int $id): JsonResponse
    {
        $this->favoriService->removeFromFavoris($id);

        return $this->json(null, Response::HTTP_NO_CONTENT);
    }
}
