<?php

declare(strict_types=1);

namespace App\Controller;

use App\DTO\CreateVoyageDTO;
use App\DTO\UpdateVoyageDTO;
use App\Entity\User;
use App\Repository\VoyageRepository;
use App\Service\AvisService;
use App\Service\CurrencyService;
use App\Service\VoyageService;
use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Serializer\Exception\ExceptionInterface;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;
use Symfony\Component\Serializer\SerializerInterface;

#[Route('/api/voyages', name: 'api_voyage_')]
#[OA\Tag(name: 'Voyages')]
class VoyageController extends AbstractController
{

    public function __construct(
        private readonly VoyageService $voyageService,
        private readonly AvisService $avisService,
        private readonly VoyageRepository $voyageRepository,
        private readonly CurrencyService $currencyService,
        private readonly SerializerInterface $serializer,
        private readonly NormalizerInterface $normalizer,
    ) {}

    #[Route('/public', name: 'public_list', methods: ['GET'])]
    #[OA\Get(path: '/api/voyages/public', summary: 'Liste publique des voyages')]
    #[OA\Parameter(name: 'page', in: 'query', schema: new OA\Schema(type: 'integer', default: 1))]
    #[OA\Parameter(name: 'limit', in: 'query', schema: new OA\Schema(type: 'integer', default: 10))]
    #[OA\Parameter(name: 'villeDepart', in: 'query', schema: new OA\Schema(type: 'string'))]
    #[OA\Parameter(name: 'villeArrivee', in: 'query', schema: new OA\Schema(type: 'string'))]
    #[OA\Parameter(name: 'dateDepart', in: 'query', schema: new OA\Schema(type: 'string', format: 'date'))]
    #[OA\Response(response: 200, description: 'Liste paginée des voyages')]
    public function publicList(Request $request): JsonResponse {

        $page = $request->query->getInt('page', 1);
        $limit = $request->query->getInt('limit', 10);
        $filters = [
            'villeDepart' => $request->query->get('villeDepart'),
            'villeArrivee' => $request->query->get('villeArrivee'),
            'dateDepart' => $request->query->get('dateDepart'),
        ];

        try {
            $result = $this->voyageService->getPublicPaginatedVoyages($page, $limit, $filters);
            $normalizedVoyages = $this->normalizer->normalize($result['data'], null, ['groups' => ['public:voyage:list']]);
        } catch (ExceptionInterface $e) {
            return new JsonResponse(['error' => $e->getMessage()], Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        return new JsonResponse([
            'data' => $normalizedVoyages,
            'pagination' => $result['pagination']
        ], Response::HTTP_OK);
    }

    #[Route('', name: 'list', methods: ['GET'])]
    #[IsGranted('ROLE_USER')]
    #[OA\Get(path: '/api/voyages', summary: 'Liste des voyages', security: [['cookieAuth' => []]])]
    #[OA\Parameter(name: 'page', in: 'query', schema: new OA\Schema(type: 'integer', default: 1))]
    #[OA\Parameter(name: 'limit', in: 'query', schema: new OA\Schema(type: 'integer', default: 10))]
    #[OA\Parameter(name: 'villeDepart', in: 'query', schema: new OA\Schema(type: 'string'))]
    #[OA\Parameter(name: 'villeArrivee', in: 'query', schema: new OA\Schema(type: 'string'))]
    #[OA\Parameter(name: 'dateDepart', in: 'query', schema: new OA\Schema(type: 'string', format: 'date'))]
    #[OA\Parameter(name: 'statut', in: 'query', schema: new OA\Schema(type: 'string'))]
    #[OA\Response(response: 200, description: 'Liste paginée des voyages')]
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
            'dateDepart' => $request->query->get('dateDepart'),
            'statut' => $request->query->get('statut'),
        ];

        $result = $this->voyageService->getPaginatedVoyages($page, $limit, $filters, $currentUser);

        // ==================== CONVERSION AUTOMATIQUE ====================
        $voyagesWithConversion = array_map(function ($voyage) use ($viewerCurrency) {
            $voyageData = json_decode(
                $this->serializer->serialize($voyage, 'json', ['groups' => ['voyage:list']]),
                true
            );

            // Ajouter les montants convertis si la devise est différente
            if ($voyage->getCurrency() !== $viewerCurrency) {
                $converted = $this->voyageService->convertVoyageAmounts($voyage, $viewerCurrency);
                $voyageData['converted'] = $converted;
            }
            $voyageData['viewerCurrency'] = $viewerCurrency;

            return $voyageData;
        }, $result['data']);

        $result['data'] = $voyagesWithConversion;

        return $this->json($result, Response::HTTP_OK);
    }

    /**
     * @throws ExceptionInterface
     */
    #[Route('/{id}', name: 'show', methods: ['GET'])]
    #[IsGranted('ROLE_USER')]
    #[OA\Get(path: '/api/voyages/{id}', summary: 'Détails d\'un voyage', security: [['cookieAuth' => []]])]
    #[OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))]
    #[OA\Response(response: 200, description: 'Détails du voyage')]
    public function show(int $id, NormalizerInterface $normalizer): JsonResponse
    {
        /** @var User $currentUser */
        $currentUser = $this->getUser();
        $viewerCurrency = $currentUser->getSettings()?->getDevise()
            ?? $this->currencyService->getDefaultCurrency();

        $voyage = $this->voyageService->getVoyage($id);
        $dataVoyage = $normalizer->normalize($voyage, null, ['groups' => ['voyage:read']]);

        $noteAvisMoyen = $this->avisService->getStatsByUser($voyage->getVoyageur()->getId())['average'] ?? 0;
        $dataVoyage['voyageur']['noteAvisMoyen'] = $noteAvisMoyen;

        // ==================== CONVERSION AUTOMATIQUE ====================
        if ($voyage->getCurrency() !== $viewerCurrency) {
            $converted = $this->voyageService->convertVoyageAmounts($voyage, $viewerCurrency);
            $dataVoyage['converted'] = $converted;
        }
        $dataVoyage['viewerCurrency'] = $viewerCurrency;

        return $this->json($dataVoyage, Response::HTTP_OK);
    }

    #[Route('/{id}/matching-demandes', name: 'matching_demandes', methods: ['GET'])]
    #[IsGranted('ROLE_USER')]
    #[OA\Get(path: '/api/voyages/{id}/matching-demandes', summary: 'Demandes correspondantes', security: [['cookieAuth' => []]])]
    #[OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))]
    #[OA\Response(response: 200, description: 'Liste des demandes correspondantes')]
    public function matchingDemandes(int $id): JsonResponse
    {
        /** @var User $currentUser */
        $currentUser = $this->getUser();
        $viewerCurrency = $currentUser->getSettings()?->getDevise()
            ?? $this->currencyService->getDefaultCurrency();

        $demandes = $this->voyageService->findMatchingDemandes($id, $currentUser);

        // ==================== CONVERSION AUTOMATIQUE ====================
        $demandesWithConversion = array_map(function ($match) use ($viewerCurrency) {
            if (isset($match['demande']) && $match['demande']->getCurrency() !== $viewerCurrency) {
                $demandeData = json_decode(
                    $this->serializer->serialize($match['demande'], 'json', ['groups' => ['demande:list']]),
                    true
                );

                // Conversion des montants
                if ($match['demande']->getPrixParKilo() || $match['demande']->getCommissionProposeePourUnBagage()) {
                    $converted = [
                        'originalCurrency' => $match['demande']->getCurrency(),
                        'targetCurrency' => $viewerCurrency,
                    ];

                    if ($match['demande']->getPrixParKilo()) {
                        $converted['prixParKilo'] = $this->currencyService->convert(
                            (float) $match['demande']->getPrixParKilo(),
                            $match['demande']->getCurrency(),
                            $viewerCurrency
                        );
                        $converted['prixParKiloFormatted'] = $this->currencyService->formatAmount(
                            $converted['prixParKilo'],
                            $viewerCurrency
                        );
                    }

                    if ($match['demande']->getCommissionProposeePourUnBagage()) {
                        $converted['commission'] = $this->currencyService->convert(
                            (float) $match['demande']->getCommissionProposeePourUnBagage(),
                            $match['demande']->getCurrency(),
                            $viewerCurrency
                        );
                        $converted['commissionFormatted'] = $this->currencyService->formatAmount(
                            $converted['commission'],
                            $viewerCurrency
                        );
                    }

                    $demandeData['converted'] = $converted;
                }

                $demandeData['viewerCurrency'] = $viewerCurrency;
                $match['demande'] = $demandeData;
            }

            return $match;
        }, $demandes);

        return $this->json($demandesWithConversion, Response::HTTP_OK);
    }

    #[Route('', name: 'create', methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    #[OA\Post(path: '/api/voyages', summary: 'Créer un voyage', security: [['cookieAuth' => []]], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: new Model(type: CreateVoyageDTO::class))))]
    #[OA\Response(response: 201, description: 'Voyage créé')]
    public function create(#[MapRequestPayload] CreateVoyageDTO $dto): JsonResponse
    {
        /* @var User $user*/
        $user = $this->getUser();

        $this->denyAccessUnlessGranted('VOYAGE_CREATE');

        $voyage = $this->voyageService->createVoyage($dto, $user);
        return $this->json($voyage, Response::HTTP_CREATED, [], ['groups' => ['voyage:read']]);
    }

    #[Route('/{id}', name: 'update', methods: ['PUT'])]
    #[IsGranted('ROLE_USER')]
    #[OA\Put(path: '/api/voyages/{id}', summary: 'Modifier un voyage', security: [['cookieAuth' => []]], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: new Model(type: UpdateVoyageDTO::class))))]
    #[OA\Response(response: 200, description: 'Voyage mis à jour')]
    public function update(int $id, #[MapRequestPayload] UpdateVoyageDTO $dto): JsonResponse
    {
        // Charger le voyage pour vérifier les permissions
        $voyage = $this->voyageRepository->find($id);

        if (!$voyage) {
            throw $this->createNotFoundException('Voyage non trouvé');
        }

        $this->denyAccessUnlessGranted('VOYAGE_EDIT', $voyage);

        $updatedVoyage = $this->voyageService->updateVoyage($id, $dto);
        return $this->json($updatedVoyage, Response::HTTP_OK, [], ['groups' => ['voyage:read']]);
    }

    #[Route('/{id}/statut', name: 'update_status', methods: ['PATCH'])]
    #[IsGranted('ROLE_USER')]
    #[OA\Patch(path: '/api/voyages/{id}/statut', summary: 'Changer le statut', security: [['cookieAuth' => []]])]
    #[OA\Response(response: 200, description: 'Statut mis à jour')]
    public function updateStatus(int $id, Request $request): JsonResponse
    {
        // Charger le voyage pour vérifier les permissions
        $voyage = $this->voyageRepository->find($id);

        if (!$voyage) {
            throw $this->createNotFoundException('Voyage non trouvé');
        }

        $this->denyAccessUnlessGranted('VOYAGE_EDIT', $voyage);

        $data = json_decode($request->getContent(), true);
        $statut = $data['statut'] ?? null;

        if (!$statut || !in_array($statut, ['actif', 'complet', 'termine', 'annule'])) {
            return $this->json(['message' => 'Statut invalide'], Response::HTTP_BAD_REQUEST);
        }

        $updatedVoyage = $this->voyageService->updateStatut($id, $statut);
        return $this->json($updatedVoyage, Response::HTTP_OK, [], ['groups' => ['voyage:read']]);
    }

    #[Route('/{id}', name: 'delete', methods: ['DELETE'])]
    #[IsGranted('ROLE_USER')]
    #[OA\Delete(path: '/api/voyages/{id}', summary: 'Supprimer un voyage', security: [['cookieAuth' => []]])]
    #[OA\Response(response: 204, description: 'Voyage supprimé')]
    public function delete(int $id): JsonResponse
    {
        // Charger le voyage et vérifier les permissions
        $voyage = $this->voyageRepository->find($id);

        if (!$voyage) {
            throw $this->createNotFoundException('Voyage non trouvé');
        }

        $this->denyAccessUnlessGranted('VOYAGE_DELETE', $voyage);

        $this->voyageService->deleteVoyage($id);
        return $this->json(null, Response::HTTP_NO_CONTENT);
    }

    #[Route('/user/{userId}', name: 'by_user', methods: ['GET'])]
    #[IsGranted('ROLE_USER')]
    #[OA\Get(path: '/api/voyages/user/{userId}', summary: 'Voyages d\'un utilisateur', security: [['cookieAuth' => []]])]
    #[OA\Response(response: 200, description: 'Liste des voyages')]
    public function byUser(int $userId): JsonResponse
    {
        /** @var User $currentUser */
        $currentUser = $this->getUser();
        $viewerCurrency = $currentUser->getSettings()?->getDevise()
            ?? $this->currencyService->getDefaultCurrency();

        $voyages = $this->voyageService->getVoyagesByUser($userId);

        // ==================== CONVERSION AUTOMATIQUE ====================
        $voyagesWithConversion = array_map(function ($voyage) use ($viewerCurrency) {
            $voyageData = json_decode(
                $this->serializer->serialize($voyage, 'json', ['groups'=> ['voyage:list']]),
                true
            );

            if ($voyage->getCurrency() !== $viewerCurrency) {
                $converted = $this->voyageService->convertVoyageAmounts($voyage, $viewerCurrency);
                $voyageData['converted'] = $converted;
            }
            $voyageData['viewerCurrency'] = $viewerCurrency;

            return $voyageData;
        }, $voyages);

        return $this->json($voyagesWithConversion, Response::HTTP_OK);
    }
}
