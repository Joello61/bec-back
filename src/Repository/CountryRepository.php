<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Country;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Country>
 */
class CountryRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Country::class);
    }

    /**
     * Récupère tous les pays triés par nom français (ou anglais si non dispo)
     */
    public function findAllSorted(): array
    {
        return $this->createQueryBuilder('c')
            ->orderBy(
                "CASE WHEN c.nameFr IS NULL THEN c.name ELSE c.nameFr END",
                'ASC'
            )
            ->getQuery()
            ->getResult();
    }

    /**
     * Recherche par code (ISO 3166-1 alpha-2)
     */
    public function findByCode(string $code): ?Country
    {
        return $this->findOneBy(['code' => strtoupper($code)]);
    }

    /**
     * Recherche par code ISO3
     */
    public function findByIso3(string $iso3): ?Country
    {
        return $this->findOneBy(['iso3' => strtoupper($iso3)]);
    }

    /**
     * Recherche de pays par nom (partiel)
     */
    public function searchByName(string $query, int $limit = 20): array
    {
        return $this->createQueryBuilder('c')
            ->where('c.name LIKE :query')
            ->orWhere('c.nameFr LIKE :query')
            ->setParameter('query', '%' . $query . '%')
            ->orderBy(
                "CASE WHEN c.nameFr IS NULL THEN c.name ELSE c.nameFr END",
                'ASC'
            )
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * Récupère les pays par continent
     */
    public function findByContinent(string $continent): array
    {
        return $this->createQueryBuilder('c')
            ->where('c.continent = :continent')
            ->setParameter('continent', $continent)
            ->orderBy(
                "CASE WHEN c.nameFr IS NULL THEN c.name ELSE c.nameFr END",
                'ASC'
            )
            ->getQuery()
            ->getResult();
    }

    /**
     * Récupère les pays africains
     */
    public function findAfricanCountries(): array
    {
        return $this->findByContinent('AF');
    }

    /**
     * Compte le nombre total de pays
     */
    public function countAll(): int
    {
        return (int) $this->createQueryBuilder('c')
            ->select('COUNT(c.id)')
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Vérifie si un pays existe par son nom français
     */
    public function existsByNameFr(string $nameFr): bool
    {
        return (bool) $this->createQueryBuilder('c')
            ->select('COUNT(c.id)')
            ->where('c.nameFr = :name_fr')
            ->setParameter('name_fr', $nameFr)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Vérifie si un pays existe par son code ISO
     */
    public function existsByCode(string $code): bool
    {
        return (bool) $this->createQueryBuilder('c')
            ->select('COUNT(c.id)')
            ->where('c.code = :code')
            ->setParameter('code', strtoupper($code))
            ->getQuery()
            ->getSingleScalarResult();
    }
}
