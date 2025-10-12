<?php

declare(strict_types=1);

namespace App\Controller;

use App\DTO\CreateDemandeDTO;
use App\DTO\UpdateDemandeDTO;
use App\Entity\User;
use App\Repository\DemandeRepository;
use App\Service\CurrencyService;
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
use Symfony\Component\Serializer\SerializerInterface;

#[Route('/api/demandes', name: 'api_demande_')]
#[OA\Tag(name: 'Demandes')]
class DemandeController extends AbstractController
{
    public function __construct(
        private readonly DemandeService $demandeService,
        private readonly DemandeRepository $demandeRepository,
        private readonly CurrencyService $currencyService,
        private readonly SerializerInterface $serializer,
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
        /** @var User $currentUser */
        $currentUser = $this->getUser();
        $viewerCurrency = $currentUser->getSettings()?->getDevise()
            ?? $this->currencyService->getDefaultCurrency();

        $page = $request->query->getInt('page', 1);
        $limit = $request->query->getInt('limit', 10);
        $filters = [
            'villeDepart' => $request->query->get('villeDepart'),
            'villeArrivee' => $request->query->get('villeArrivee'),
            'statut' => $request->query->get('statut'),
        ];

        $result = $this->demandeService->getPaginatedDemandes($page, $limit, $filters);

        // ==================== CONVERSION AUTOMATIQUE ====================
        $demandesWithConversion = array_map(function ($demande) use ($viewerCurrency) {
            $demandeData = json_decode(
                $this->serializer->serialize($demande, 'json', ['groups' => ['demande:list']]),
                true
            );

            // Ajouter les montants convertis si la devise est différente
            if ($demande->getCurrency() !== $viewerCurrency) {
                $converted = $this->demandeService->convertDemandeAmounts($demande, $viewerCurrency);
                $demandeData['converted'] = $converted;
            }
            $demandeData['viewerCurrency'] = $viewerCurrency;

            return $demandeData;
        }, $result['data']);

        $result['data'] = $demandesWithConversion;

        return $this->json($result, Response::HTTP_OK);
    }

    #[Route('/{id}', name: 'show', methods: ['GET'])]
    #[IsGranted('ROLE_USER')]
    #[OA\Get(path: '/api/demandes/{id}', summary: 'Détails d\'une demande', security: [['cookieAuth' => []]])]
    #[OA\Response(response: 200, description: 'Détails')]
    public function show(int $id): JsonResponse
    {
        /** @var User $currentUser */
        $currentUser = $this->getUser();
        $viewerCurrency = $currentUser->getSettings()?->getDevise()
            ?? $this->currencyService->getDefaultCurrency();

        $demande = $this->demandeService->getDemande($id);

        $demandeData = json_decode(
            $this->serializer->serialize($demande, 'json', ['groups' => ['demande:read']]),
            true
        );

        // ==================== CONVERSION AUTOMATIQUE ====================
        if ($demande->getCurrency() !== $viewerCurrency) {
            $converted = $this->demandeService->convertDemandeAmounts($demande, $viewerCurrency);
            $demandeData['converted'] = $converted;
        }
        $demandeData['viewerCurrency'] = $viewerCurrency;

        return $this->json($demandeData, Response::HTTP_OK);
    }

    #[Route('/{id}/matching-voyages', name: 'matching_voyages', methods: ['GET'])]
    #[IsGranted('ROLE_USER')]
    #[OA\Get(path: '/api/demandes/{id}/matching-voyages', summary: 'Voyages correspondants avec score', security: [['cookieAuth' => []]])]
    #[OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))]
    #[OA\Response(response: 200, description: 'Liste des voyages correspondants avec score de correspondance')]
    public function matchingVoyages(int $id): JsonResponse
    {
        /** @var User $currentUser */
        $currentUser = $this->getUser();
        $viewerCurrency = $currentUser->getSettings()?->getDevise()
            ?? $this->currencyService->getDefaultCurrency();

        $matchingVoyages = $this->demandeService->findMatchingVoyages($id, $currentUser);

        // ==================== CONVERSION AUTOMATIQUE ====================
        $matchingVoyagesWithConversion = array_map(function ($match) use ($viewerCurrency) {
            if (isset($match['voyage']) && $match['voyage']->getCurrency() !== $viewerCurrency) {
                $voyageData = json_decode(
                    $this->serializer->serialize($match['voyage'], 'json', ['groups' => ['voyage:list']]),
                    true
                );

                // Conversion des montants
                if ($match['voyage']->getPrixParKilo() || $match['voyage']->getCommissionProposeePourUnBagage()) {
                    $converted = [
                        'originalCurrency' => $match['voyage']->getCurrency(),
                        'targetCurrency' => $viewerCurrency,
                    ];

                    if ($match['voyage']->getPrixParKilo()) {
                        $converted['prixParKilo'] = $this->currencyService->convert(
                            (float) $match['voyage']->getPrixParKilo(),
                            $match['voyage']->getCurrency(),
                            $viewerCurrency
                        );
                        $converted['prixParKiloFormatted'] = $this->currencyService->formatAmount(
                            $converted['prixParKilo'],
                            $viewerCurrency
                        );
                    }

                    if ($match['voyage']->getCommissionProposeePourUnBagage()) {
                        $converted['commission'] = $this->currencyService->convert(
                            (float) $match['voyage']->getCommissionProposeePourUnBagage(),
                            $match['voyage']->getCurrency(),
                            $viewerCurrency
                        );
                        $converted['commissionFormatted'] = $this->currencyService->formatAmount(
                            $converted['commission'],
                            $viewerCurrency
                        );
                    }

                    $voyageData['converted'] = $converted;
                }

                $voyageData['viewerCurrency'] = $viewerCurrency;
                $match['voyage'] = $voyageData;
            }

            return $match;
        }, $matchingVoyages);

        return $this->json($matchingVoyagesWithConversion, Response::HTTP_OK);
    }

    #[Route('', name: 'create', methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    #[OA\Post(path: '/api/demandes', summary: 'Créer une demande', security: [['cookieAuth' => []]], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: new Model(type: CreateDemandeDTO::class))))]
    #[OA\Response(response: 201, description: 'Demande créée')]
    public function create(#[MapRequestPayload] CreateDemandeDTO $dto): JsonResponse
    {
        /* @var User $user*/
        $user = $this->getUser();

        $this->denyAccessUnlessGranted('DEMANDE_CREATE');

        $demande = $this->demandeService->createDemande($dto, $user);
        return $this->json($demande, Response::HTTP_CREATED, [], ['groups' => ['demande:read']]);
    }

    #[Route('/{id}', name: 'update', methods: ['PUT'])]
    #[IsGranted('ROLE_USER')]
    #[OA\Put(path: '/api/demandes/{id}', summary: 'Modifier une demande', security: [['cookieAuth' => []]], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: new Model(type: UpdateDemandeDTO::class))))]
    #[OA\Response(response: 200, description: 'Demande mise à jour')]
    public function update(int $id, #[MapRequestPayload] UpdateDemandeDTO $dto): JsonResponse
    {
        $demande = $this->demandeRepository->find($id);

        if (!$demande) {
            throw $this->createNotFoundException('Demande non trouvée');
        }

        $this->denyAccessUnlessGranted('DEMANDE_EDIT', $demande);

        $updatedDemande = $this->demandeService->updateDemande($id, $dto);
        return $this->json($updatedDemande, Response::HTTP_OK, [], ['groups' => ['demande:read']]);
    }

    #[Route('/{id}/statut', name: 'update_status', methods: ['PATCH'])]
    #[IsGranted('ROLE_USER')]
    #[OA\Patch(path: '/api/demandes/{id}/statut', summary: 'Changer le statut', security: [['cookieAuth' => []]])]
    #[OA\Response(response: 200, description: 'Statut mis à jour')]
    public function updateStatus(int $id, Request $request): JsonResponse
    {
        $demande = $this->demandeRepository->find($id);

        if (!$demande) {
            throw $this->createNotFoundException('Demande non trouvée');
        }

        $this->denyAccessUnlessGranted('DEMANDE_EDIT', $demande);

        $data = json_decode($request->getContent(), true);
        $statut = $data['statut'] ?? null;

        if (!$statut || !in_array($statut, ['en_recherche', 'voyageur_trouve', 'annulee'])) {
            return $this->json(['message' => 'Statut invalide'], Response::HTTP_BAD_REQUEST);
        }

        $updatedDemande = $this->demandeService->updateStatut($id, $statut);
        return $this->json($updatedDemande, Response::HTTP_OK, [], ['groups' => ['demande:read']]);
    }

    #[Route('/{id}', name: 'delete', methods: ['DELETE'])]
    #[IsGranted('ROLE_USER')]
    #[OA\Delete(path: '/api/demandes/{id}', summary: 'Supprimer une demande', security: [['cookieAuth' => []]])]
    #[OA\Response(response: 204, description: 'Demande supprimée')]
    public function delete(int $id): JsonResponse
    {
        $demande = $this->demandeRepository->find($id);

        if (!$demande) {
            throw $this->createNotFoundException('Demande non trouvée');
        }

        $this->denyAccessUnlessGranted('DEMANDE_DELETE', $demande);

        $this->demandeService->deleteDemande($id);
        return $this->json(null, Response::HTTP_NO_CONTENT);
    }

    #[Route('/user/{userId}', name: 'by_user', methods: ['GET'])]
    #[IsGranted('ROLE_USER')]
    #[OA\Get(path: '/api/demandes/user/{userId}', summary: 'Demandes d\'un utilisateur', security: [['cookieAuth' => []]])]
    #[OA\Response(response: 200, description: 'Liste')]
    public function byUser(int $userId): JsonResponse
    {
        /** @var User $currentUser */
        $currentUser = $this->getUser();
        $viewerCurrency = $currentUser->getSettings()?->getDevise()
            ?? $this->currencyService->getDefaultCurrency();

        $demandes = $this->demandeService->getDemandesByUser($userId);

        // ==================== CONVERSION AUTOMATIQUE ====================
        $demandesWithConversion = array_map(function ($demande) use ($viewerCurrency) {
            $demandeData = json_decode(
                $this->serializer->serialize($demande, 'json', ['groups' => ['demande:list']]),
                true
            );

            if ($demande->getCurrency() !== $viewerCurrency) {
                $converted = $this->demandeService->convertDemandeAmounts($demande, $viewerCurrency);
                $demandeData['converted'] = $converted;
            }
            $demandeData['viewerCurrency'] = $viewerCurrency;

            return $demandeData;
        }, $demandes);

        return $this->json($demandesWithConversion, Response::HTTP_OK);
    }
}
