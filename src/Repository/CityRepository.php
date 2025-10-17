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
     */
    public function searchByCountryAndName(
        Country $country,
        string $query,
        int $limit = 50
    ): array {
        return $this->createQueryBuilder('c')
            ->where('c.country = :country')
            ->andWhere('c.name LIKE :query OR c.alternateName LIKE :query')
            ->setParameter('country', $country)
            ->setParameter('query', $query . '%')
            ->orderBy('c.population', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * Recherche de villes par code pays (ISO 3166-1 alpha-2)
     */
    public function searchByCountryCodeAndName(
        string $countryCode,
        string $query,
        int $limit = 50
    ): array {
        return $this->createQueryBuilder('c')
            ->innerJoin('c.country', 'co')
            ->where('co.code = :countryCode')
            ->andWhere('c.name LIKE :query OR c.alternateName LIKE :query')
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
     */
    public function findByNameAndCountry(string $name, Country $country): ?City
    {
        return $this->createQueryBuilder('c')
            ->where('c.country = :country')
            ->andWhere('c.name = :name OR c.alternateName = :name')
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
     */
    public function searchGlobal(string $query, int $limit = 50): array
    {
        return $this->createQueryBuilder('c')
            ->innerJoin('c.country', 'co')
            ->where('c.name LIKE :query OR c.alternateName LIKE :query')
            ->setParameter('query', $query . '%')
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
