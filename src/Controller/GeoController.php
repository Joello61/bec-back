<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\GeoDataService;
use OpenApi\Attributes as OA;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/geo', name: 'api_geo_')]
#[OA\Tag(name: 'Géographie')]
class GeoController extends AbstractController
{
    public function __construct(
        private readonly GeoDataService $geoDataService
    ) {}

    /**
     * GET /api/geo/countries
     * Liste de tous les pays
     */
    #[Route('/countries', name: 'countries', methods: ['GET'])]
    #[OA\Get(
        path: '/api/geo/countries',
        description: 'Retourne la liste complète des pays triés par nom français',
        summary: 'Liste de tous les pays'
    )]
    #[OA\Response(
        response: 200,
        description: 'Liste des pays',
        content: new OA\JsonContent(
            type: 'array',
            items: new OA\Items(
                properties: [
                    new OA\Property(property: 'value', type: 'string', example: 'FR'),
                    new OA\Property(property: 'label', type: 'string', example: 'France'),
                    new OA\Property(property: 'continent', type: 'string', example: 'EU'),
                ],
                type: 'object'
            )
        )
    )]
    public function getCountries(): JsonResponse
    {
        $countries = $this->geoDataService->getAllCountries();

        return $this->json($countries, Response::HTTP_OK);
    }

    /**
     * GET /api/geo/cities?country=France
     * Liste des villes d'un pays (top 100)
     */
    #[Route('/cities', name: 'cities', methods: ['GET'])]
    #[OA\Get(
        path: '/api/geo/cities',
        description: 'Retourne les villes d\'un pays (top 100 villes les plus peuplées)',
        summary: 'Liste des villes par pays'
    )]
    #[OA\Parameter(
        name: 'country',
        description: 'Nom français du pays (ex: France, Cameroun, États-Unis)',
        in: 'query',
        required: true,
        schema: new OA\Schema(type: 'string', example: 'France')
    )]
    #[OA\Response(
        response: 200,
        description: 'Liste des villes',
        content: new OA\JsonContent(
            type: 'array',
            items: new OA\Items(
                properties: [
                    new OA\Property(property: 'value', type: 'string', example: 'Paris'),
                    new OA\Property(property: 'label', type: 'string', example: 'Paris'),
                ],
                type: 'object'
            )
        )
    )]
    #[OA\Response(
        response: 400,
        description: 'Paramètre manquant',
        content: new OA\JsonContent(
            properties: [
                new OA\Property(property: 'error', type: 'string', example: 'Le paramètre "country" est requis'),
            ],
            type: 'object'
        )
    )]
    #[OA\Response(
        response: 404,
        description: 'Pays non trouvé'
    )]
    public function getCities(Request $request): JsonResponse
    {
        $countryName = $request->query->get('country');

        if (!$countryName) {
            return $this->json([
                'error' => 'Le paramètre "country" est requis'
            ], Response::HTTP_BAD_REQUEST);
        }

        // Récupérer le code ISO à partir du nom français
        $countryCode = $this->geoDataService->getCountryCodeByNameFr(trim($countryName));

        if (!$countryCode) {
            return $this->json([
                'error' => 'Pays non trouvé'
            ], Response::HTTP_NOT_FOUND);
        }

        $cities = $this->geoDataService->getCitiesByCountryCode($countryCode);

        return $this->json($cities, Response::HTTP_OK);
    }

    /**
     * GET /api/geo/cities/search?country=France&q=nan
     * Recherche de villes dans un pays (autocomplete)
     */
    #[Route('/cities/search', name: 'cities_search', methods: ['GET'])]
    #[OA\Get(
        path: '/api/geo/cities/search',
        description: 'Recherche autocomplete de villes dans un pays donné (permet de trouver des villes hors du top 100)',
        summary: 'Recherche de villes par pays'
    )]
    #[OA\Parameter(
        name: 'country',
        description: 'Nom français du pays',
        in: 'query',
        required: true,
        schema: new OA\Schema(type: 'string', example: 'France')
    )]
    #[OA\Parameter(
        name: 'q',
        description: 'Terme de recherche (min 2 caractères)',
        in: 'query',
        required: true,
        schema: new OA\Schema(type: 'string', example: 'nan')
    )]
    #[OA\Response(response: 200, description: 'Résultats de recherche')]
    #[OA\Response(response: 400, description: 'Paramètres invalides')]
    public function searchCities(Request $request): JsonResponse
    {
        $countryName = $request->query->get('country');
        $query = $request->query->get('q', '');

        if (!$countryName) {
            return $this->json([
                'error' => 'Le paramètre "country" est requis'
            ], Response::HTTP_BAD_REQUEST);
        }

        if (strlen($query) < 2) {
            return $this->json([
                'error' => 'Le terme de recherche doit contenir au moins 2 caractères'
            ], Response::HTTP_BAD_REQUEST);
        }

        // Récupérer le code ISO à partir du nom français
        $countryCode = $this->geoDataService->getCountryCodeByNameFr(trim($countryName));

        if (!$countryCode) {
            return $this->json([
                'error' => 'Pays non trouvé'
            ], Response::HTTP_NOT_FOUND);
        }

        $cities = $this->geoDataService->searchCitiesByCountryCode($countryCode, $query);

        return $this->json($cities, Response::HTTP_OK);
    }

    /**
     * GET /api/geo/cities/top100
     * Top 100 des villes les plus peuplées du monde
     */
    #[Route('/cities/top100', name: 'cities_top100', methods: ['GET'])]
    #[OA\Get(
        path: '/api/geo/cities/top100',
        description: 'Retourne les 100 villes les plus peuplées du monde, tous pays confondus',
        summary: 'Top 100 des villes mondiales'
    )]
    #[OA\Response(
        response: 200,
        description: 'Liste des villes les plus peuplées',
        content: new OA\JsonContent(
            type: 'array',
            items: new OA\Items(
                properties: [
                    new OA\Property(property: 'value', type: 'string', example: 'Tokyo'),
                    new OA\Property(property: 'label', type: 'string', example: 'Tokyo'),
                    new OA\Property(property: 'country', type: 'string', example: 'Japon'),
                    new OA\Property(property: 'countryCode', type: 'string', example: 'JP'),
                    new OA\Property(property: 'population', type: 'integer', example: 37400068),
                    new OA\Property(property: 'admin1Name', type: 'string', example: 'Tokyo', nullable: true),
                ],
                type: 'object'
            )
        )
    )]
    public function getTopCitiesGlobal(): JsonResponse
    {
        $cities = $this->geoDataService->getTopCitiesGlobal();

        return $this->json($cities, Response::HTTP_OK);
    }

    /**
     * GET /api/geo/cities/search-global?q=paris
     * Recherche globale de villes (tous pays confondus)
     */
    #[Route('/cities/search-global', name: 'cities_search_global', methods: ['GET'])]
    #[OA\Get(
        path: '/api/geo/cities/search-global',
        description: 'Recherche de villes dans le monde entier, tous pays confondus (autocomplete global)',
        summary: 'Recherche globale de villes'
    )]
    #[OA\Parameter(
        name: 'q',
        description: 'Terme de recherche (min 2 caractères)',
        in: 'query',
        required: true,
        schema: new OA\Schema(type: 'string', example: 'paris')
    )]
    #[OA\Parameter(
        name: 'limit',
        description: 'Nombre maximum de résultats (défaut: 50, max: 100)',
        in: 'query',
        required: false,
        schema: new OA\Schema(type: 'integer', default: 50, example: 50)
    )]
    #[OA\Response(
        response: 200,
        description: 'Résultats de recherche',
        content: new OA\JsonContent(
            type: 'array',
            items: new OA\Items(
                properties: [
                    new OA\Property(property: 'value', type: 'string', example: 'Paris'),
                    new OA\Property(property: 'label', type: 'string', example: 'Paris'),
                    new OA\Property(property: 'country', type: 'string', example: 'France'),
                    new OA\Property(property: 'countryCode', type: 'string', example: 'FR'),
                    new OA\Property(property: 'population', type: 'integer', example: 2138551),
                    new OA\Property(property: 'admin1Name', type: 'string', example: 'Île-de-France', nullable: true),
                ],
                type: 'object'
            )
        )
    )]
    #[OA\Response(
        response: 400,
        description: 'Paramètre invalide',
        content: new OA\JsonContent(
            properties: [
                new OA\Property(property: 'error', type: 'string', example: 'Le terme de recherche doit contenir au moins 2 caractères'),
            ],
            type: 'object'
        )
    )]
    public function searchCitiesGlobal(Request $request): JsonResponse
    {
        $query = $request->query->get('q', '');
        $limit = (int) $request->query->get('limit', 50);

        // Validation
        if (strlen($query) < 2) {
            return $this->json([
                'error' => 'Le terme de recherche doit contenir au moins 2 caractères'
            ], Response::HTTP_BAD_REQUEST);
        }

        // Limiter le nombre de résultats max
        if ($limit > 100) {
            $limit = 100;
        }

        $cities = $this->geoDataService->searchCitiesGlobal($query, $limit);

        return $this->json($cities, Response::HTTP_OK);
    }
}
