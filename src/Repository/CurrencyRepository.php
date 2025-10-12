<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Currency;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Currency>
 */
class CurrencyRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Currency::class);
    }

    /**
     * Récupère une devise par son code (EUR, USD, XAF, etc.)
     */
    public function findByCode(string $code): ?Currency
    {
        return $this->findOneBy(['code' => strtoupper($code)]);
    }

    /**
     * Récupère toutes les devises actives
     */
    public function findAllActive(): array
    {
        return $this->createQueryBuilder('c')
            ->where('c.isActive = :active')
            ->setParameter('active', true)
            ->orderBy('c.name', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Récupère la devise utilisée dans un pays spécifique
     */
    public function findByCountry(string $countryCode): ?Currency
    {
        return $this->createQueryBuilder('c')
            ->where('JSON_CONTAINS(c.countries, :country) = 1')
            ->andWhere('c.isActive = :active')
            ->setParameter('country', json_encode(strtoupper($countryCode)))
            ->setParameter('active', true)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Récupère toutes les devises dont le taux de change est obsolète (> 24h)
     */
    public function findWithStaleExchangeRates(): array
    {
        $yesterday = new \DateTime('-24 hours');

        return $this->createQueryBuilder('c')
            ->where('c.rateUpdatedAt < :yesterday OR c.rateUpdatedAt IS NULL')
            ->andWhere('c.isActive = :active')
            ->andWhere('c.code != :eur') // EUR est la référence, pas besoin de mise à jour
            ->setParameter('yesterday', $yesterday)
            ->setParameter('active', true)
            ->setParameter('eur', 'EUR')
            ->getQuery()
            ->getResult();
    }

    /**
     * Récupère les devises les plus utilisées
     */
    public function findMostUsed(int $limit = 5): array
    {
        return $this->createQueryBuilder('c')
            ->where('c.isActive = :active')
            ->andWhere('c.code IN (:popular)')
            ->setParameter('active', true)
            ->setParameter('popular', ['EUR', 'USD', 'XAF', 'CAD', 'GBP'])
            ->orderBy('FIELD(c.code, :order)', 'ASC')
            ->setParameter('order', 'EUR,USD,XAF,CAD,GBP')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * Vérifie si une devise existe et est active
     */
    public function existsAndActive(string $code): bool
    {
        $count = $this->createQueryBuilder('c')
            ->select('COUNT(c.id)')
            ->where('c.code = :code')
            ->andWhere('c.isActive = :active')
            ->setParameter('code', strtoupper($code))
            ->setParameter('active', true)
            ->getQuery()
            ->getSingleScalarResult();

        return $count > 0;
    }

    /**
     * Récupère toutes les devises avec leur mapping pays
     */
    public function findAllWithCountries(): array
    {
        return $this->createQueryBuilder('c')
            ->where('c.isActive = :active')
            ->andWhere('JSON_LENGTH(c.countries) > 0')
            ->setParameter('active', true)
            ->orderBy('c.code', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function save(Currency $currency): void
    {
        $this->getEntityManager()->persist($currency);
        $this->getEntityManager()->flush();
    }

    public function remove(Currency $currency): void
    {
        $this->getEntityManager()->remove($currency);
        $this->getEntityManager()->flush();
    }
}
