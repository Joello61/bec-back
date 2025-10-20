<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\City;
use App\Entity\Country;
use App\Repository\CityRepository;
use App\Repository\CountryRepository;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

readonly class GeoDataService
{
    public function __construct(
        private CountryRepository $countryRepository,
        private CityRepository $cityRepository,
        private CacheInterface $cache
    ) {}

    /**
     * Récupère tous les pays pour le select
     * Résultat mis en cache pour 1 mois
     */
    public function getAllCountries(): array
    {
        return $this->cache->get('geo_countries_all', function (ItemInterface $item) {
            $item->expiresAfter(2592000); // 30 jours

            $countries = $this->countryRepository->findAllSorted();

            return array_map(fn(Country $country) => [
                'value' => $country->getCode(),
                'label' => $country->getNameFr() ?? $country->getName(),
                'continent' => $country->getContinent(),
            ], $countries);
        });
    }

    /**
     * Récupère le code ISO d'un pays à partir de son nom français
     * Résultat mis en cache
     */
    public function getCountryCodeByNameFr(string $nameFr): ?string
    {
        $cacheKey = sprintf('geo_country_code_%s', md5(strtolower($nameFr)));

        return $this->cache->get($cacheKey, function (ItemInterface $item) use ($nameFr) {
            $item->expiresAfter(2592000); // 30 jours

            $country = $this->countryRepository->findOneBy(['nameFr' => $nameFr]);

            return $country?->getCode();
        });
    }

    /**
     * Récupère les villes d'un pays par CODE ISO (FR, CM, US, etc.)
     * Retourne les villes les plus peuplées (top 100)
     * Résultat mis en cache
     */
    public function getCitiesByCountryCode(string $countryCode): array
    {
        $countryCode = strtoupper(trim($countryCode));
        $cacheKey = sprintf('geo_cities_code_%s', $countryCode);

        return $this->cache->get($cacheKey, function (ItemInterface $item) use ($countryCode) {
            $item->expiresAfter(604800); // 7 jours

            $cities = $this->cityRepository->findTopCitiesByCountryCode($countryCode, 100);

            return array_map(fn(City $city) => [
                'value' => $city->getName(),
                'label' => $city->getName(),
                'population' => $city->getPopulation(),
                'admin1Name' => $city->getAdmin1Name(),
            ], $cities);
        });
    }

    /**
     * Recherche de villes dans un pays (autocomplete) par CODE ISO
     * Permet de trouver des villes hors du top 100
     */
    public function searchCitiesByCountryCode(string $countryCode, string $query, int $limit = 50): array
    {
        if (strlen($query) < 2) {
            return [];
        }

        $countryCode = strtoupper(trim($countryCode));
        $cacheKey = sprintf('geo_cities_search_%s_%s', $countryCode, md5(strtolower($query)));

        return $this->cache->get($cacheKey, function (ItemInterface $item) use ($countryCode, $query, $limit) {
            $item->expiresAfter(86400); // 1 jour

            $cities = $this->cityRepository->searchByCountryCodeAndName($countryCode, $query, $limit);

            return array_map(fn(City $city) => [
                'value' => $city->getName(),
                'label' => $city->getName(),
                'population' => $city->getPopulation(),
                'admin1Name' => $city->getAdmin1Name(),
            ], $cities);
        });
    }

    /**
     * Vérifie si un pays existe par CODE ISO
     */
    public function countryExistsByCode(string $code): bool
    {
        $code = strtoupper(trim($code));
        return $this->countryRepository->existsByCode($code);
    }

    /**
     * Vérifie si un pays existe par nom français
     */
    public function countryExists(string $nameFr): bool
    {
        return $this->countryRepository->existsByNameFr($nameFr);
    }

    /**
     * Récupère les villes les plus peuplées du monde (top 100)
     * Résultat mis en cache
     */
    public function getTopCitiesGlobal(int $limit = 100): array
    {
        $cacheKey = sprintf('geo_cities_global_top_%d', $limit);

        return $this->cache->get($cacheKey, function (ItemInterface $item) use ($limit) {
            $item->expiresAfter(604800); // 7 jours

            $cities = $this->cityRepository->findTopCitiesGlobal($limit);

            return array_map(fn(City $city) => [
                'value' => $city->getName(),
                'label' => $city->getName(),
                'country' => $city->getCountry()->getNameFr() ?? $city->getCountry()->getName(),
                'countryCode' => $city->getCountry()->getCode(),
                'population' => $city->getPopulation(),
                'admin1Name' => $city->getAdmin1Name(),
            ], $cities);
        });
    }

    /**
     * Recherche globale de villes (tous pays confondus)
     * Résultat mis en cache
     */
    public function searchCitiesGlobal(string $query, int $limit = 50): array
    {
        if (strlen($query) < 2) {
            return [];
        }

        $cacheKey = sprintf('geo_cities_search_global_%s', md5(strtolower($query)));

        return $this->cache->get($cacheKey, function (ItemInterface $item) use ($query, $limit) {
            $item->expiresAfter(86400); // 1 jour

            $cities = $this->cityRepository->searchGlobal($query, $limit);

            return array_map(fn(City $city) => [
                'value' => $city->getName(),
                'label' => $city->getName(),
                'country' => $city->getCountry()->getNameFr() ?? $city->getCountry()->getName(),
                'countryCode' => $city->getCountry()->getCode(),
                'population' => $city->getPopulation(),
                'admin1Name' => $city->getAdmin1Name(),
            ], $cities);
        });
    }

    public function getContinentByPays(string $pays): ?string
    {
        return $this->countryRepository->findContinentByPays($pays);
    }

    public function getTimeZoneByCityAndPays(string $city, int $pays): ?string
    {
        return $this->cityRepository->findTimeZoneByCityAndPays($city, $pays);

    }
}
