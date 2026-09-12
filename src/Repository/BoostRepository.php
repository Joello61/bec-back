<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Boost;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Boost>
 */
class BoostRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Boost::class);
    }

    /**
     * Ids de voyages parmi $voyageIds ayant actuellement un boost actif - utilisé pour
     * positionner Voyage::isCurrentlyBoosted sans N+1 (une requête batch par page de
     * résultats, jamais une requête par voyage).
     *
     * @param int[] $voyageIds
     * @return int[]
     */
    public function findActiveVoyageIds(array $voyageIds): array
    {
        if ($voyageIds === []) {
            return [];
        }

        $result = $this->createQueryBuilder('b')
            ->select('IDENTITY(b.voyage) as voyageId')
            ->andWhere('b.voyage IN (:ids)')
            ->andWhere('b.status = :status')
            ->andWhere('b.endAt > :now')
            ->setParameter('ids', $voyageIds)
            ->setParameter('status', Boost::STATUS_ACTIVE)
            ->setParameter('now', new \DateTime())
            ->getQuery()
            ->getScalarResult();

        return array_map(static fn (array $row) => (int) $row['voyageId'], $result);
    }

    /**
     * @param int[] $demandeIds
     * @return int[]
     */
    public function findActiveDemandeIds(array $demandeIds): array
    {
        if ($demandeIds === []) {
            return [];
        }

        $result = $this->createQueryBuilder('b')
            ->select('IDENTITY(b.demande) as demandeId')
            ->andWhere('b.demande IN (:ids)')
            ->andWhere('b.status = :status')
            ->andWhere('b.endAt > :now')
            ->setParameter('ids', $demandeIds)
            ->setParameter('status', Boost::STATUS_ACTIVE)
            ->setParameter('now', new \DateTime())
            ->getQuery()
            ->getScalarResult();

        return array_map(static fn (array $row) => (int) $row['demandeId'], $result);
    }

    /**
     * Boosts actifs dont la periode est terminee - consomme par ExpireBoostsHandler.
     * @return Boost[]
     */
    public function findExpiredActiveBoosts(\DateTimeInterface $now): array
    {
        return $this->createQueryBuilder('b')
            ->andWhere('b.status = :status')
            ->andWhere('b.endAt < :now')
            ->setParameter('status', Boost::STATUS_ACTIVE)
            ->setParameter('now', $now)
            ->getQuery()
            ->getResult();
    }
}
