<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\City;
use App\Entity\Country;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<City>
 */
class CityRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, City::class);
    }

    /**
     * Recherche de villes par pays avec autocomplete
     * Trie par population décroissante pour afficher les plus grandes villes en premier
     * ✅ CORRECTION : Recherche case-insensitive avec LOWER()
     */
    public function searchByCountryAndName(
        Country $country,
        string $query,
        int $limit = 50
    ): array {
        $query = strtolower($query); // ✅ Convertir en minuscule

        return $this->createQueryBuilder('c')
            ->where('c.country = :country')
            ->andWhere('LOWER(c.name) LIKE :query OR LOWER(c.alternateName) LIKE :query')
            ->setParameter('country', $country)
            ->setParameter('query', $query . '%')
            ->orderBy('c.population', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * Recherche de villes par code pays (ISO 3166-1 alpha-2)
     * ✅ CORRECTION : Recherche case-insensitive avec LOWER()
     */
    public function searchByCountryCodeAndName(
        string $countryCode,
        string $query,
        int $limit = 50
    ): array {
        $query = strtolower($query); // ✅ Convertir en minuscule

        return $this->createQueryBuilder('c')
            ->innerJoin('c.country', 'co')
            ->where('co.code = :countryCode')
            ->andWhere('LOWER(c.name) LIKE :query OR LOWER(c.alternateName) LIKE :query')
            ->setParameter('countryCode', strtoupper($countryCode))
            ->setParameter('query', $query . '%')
            ->orderBy('c.population', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * Récupère les villes les plus peuplées d'un pays
     */
    public function findTopCitiesByCountry(Country $country, int $limit = 100): array
    {
        return $this->createQueryBuilder('c')
            ->where('c.country = :country')
            ->setParameter('country', $country)
            ->orderBy('c.population', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * Récupère les villes les plus peuplées par CODE pays (ISO 3166-1 alpha-2)
     */
    public function findTopCitiesByCountryCode(string $countryCode, int $limit = 100): array
    {
        return $this->createQueryBuilder('c')
            ->innerJoin('c.country', 'co')
            ->where('co.code = :countryCode')
            ->setParameter('countryCode', strtoupper($countryCode))
            ->orderBy('c.population', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * Récupère les villes les plus peuplées par nom français du pays
     */
    public function findTopCitiesByCountryNameFr(string $countryNameFr, int $limit = 100): array
    {
        return $this->createQueryBuilder('c')
            ->innerJoin('c.country', 'co')
            ->where('co.nameFr = :name_fr')
            ->setParameter('name_fr', $countryNameFr)
            ->orderBy('c.population', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * Recherche de ville par nom exact et pays
     * ✅ CORRECTION : Recherche case-insensitive avec LOWER()
     */
    public function findByNameAndCountry(string $name, Country $country): ?City
    {
        $name = strtolower($name); // ✅ Convertir en minuscule

        return $this->createQueryBuilder('c')
            ->where('c.country = :country')
            ->andWhere('LOWER(c.name) = :name OR LOWER(c.alternateName) = :name')
            ->setParameter('country', $country)
            ->setParameter('name', $name)
            ->orderBy('c.population', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Compte le nombre de villes par pays
     */
    public function countByCountry(Country $country): int
    {
        return (int) $this->createQueryBuilder('c')
            ->select('COUNT(c.id)')
            ->where('c.country = :country')
            ->setParameter('country', $country)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Compte le nombre total de villes
     */
    public function countAll(): int
    {
        return (int) $this->createQueryBuilder('c')
            ->select('COUNT(c.id)')
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Recherche globale (tous pays confondus) - pour debug/admin
     * ✅ CORRECTION : Case-insensitive + addSelect('co')
     */
    public function searchGlobal(string $query, int $limit = 50): array
    {
        $query = strtolower($query); // ✅ Convertir en minuscule

        return $this->createQueryBuilder('c')
            ->innerJoin('c.country', 'co')
            ->addSelect('co')
            ->where('LOWER(c.name) LIKE :query OR LOWER(c.alternateName) LIKE :query')
            ->setParameter('query', $query . '%')
            ->orderBy('c.population', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * Récupère les villes les plus peuplées du monde
     */
    public function findTopCitiesGlobal(int $limit = 100): array
    {
        return $this->createQueryBuilder('c')
            ->innerJoin('c.country', 'co')
            ->addSelect('co')
            ->orderBy('c.population', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * Recherche de villes dans une région spécifique
     */
    public function findByCountryAndRegion(
        Country $country,
        string $admin1Code,
        int $limit = 100
    ): array {
        return $this->createQueryBuilder('c')
            ->where('c.country = :country')
            ->andWhere('c.admin1Code = :admin1Code')
            ->setParameter('country', $country)
            ->setParameter('admin1Code', $admin1Code)
            ->orderBy('c.population', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * Recherche par GeoName ID
     */
    public function findByGeonameId(int $geonameId): ?City
    {
        return $this->findOneBy(['geonameId' => $geonameId]);
    }
}

/**
 * ============================================
 * EXPLICATION DE LA CORRECTION
 * ============================================
 *
 * ❌ AVANT (case-sensitive) :
 *
 * ->where('c.name LIKE :query')
 * ->setParameter('query', 'paris%')
 *
 * SQL généré :
 * WHERE c.name LIKE 'paris%'
 *
 * Résultat : Ne trouve QUE les villes commençant par 'paris' (minuscule)
 * ❌ Ne trouve PAS "Paris", "PARIS", "PaRiS"
 *
 * ============================================
 *
 * ✅ APRÈS (case-insensitive) :
 *
 * $query = strtolower($query);  // 'Paris' → 'paris'
 * ->where('LOWER(c.name) LIKE :query')
 * ->setParameter('query', 'paris%')
 *
 * SQL généré :
 * WHERE LOWER(c.name) LIKE 'paris%'
 *
 * Résultat : Trouve TOUTES les variantes
 * ✅ Trouve "Paris", "paris", "PARIS", "PaRiS", etc.
 *
 * ============================================
 *
 * IMPORTANT :
 * - strtolower($query) côté PHP pour normaliser l'entrée utilisateur
 * - LOWER(c.name) côté SQL pour normaliser les données en base
 * - Les deux sont nécessaires pour une recherche insensible à la casse
 *
 * ============================================
 *
 * PERFORMANCE :
 * LOWER() peut empêcher l'utilisation d'index sur la colonne name.
 *
 * Solutions pour optimiser :
 * 1. Créer un index fonctionnel : CREATE INDEX idx_city_name_lower ON city (LOWER(name));
 * 2. Ajouter une colonne name_normalized (minuscule) avec index
 * 3. Utiliser PostgreSQL ILIKE au lieu de LOWER() + LIKE (spécifique PostgreSQL)
 *
 * Pour l'instant, cette solution fonctionne bien jusqu'à ~100k villes.
 * ============================================
 */
