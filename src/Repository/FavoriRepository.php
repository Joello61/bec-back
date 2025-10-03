<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Favori;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class FavoriRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Favori::class);
    }

    public function findByUser(int $userId): array
    {
        return $this->createQueryBuilder('f')
            ->leftJoin('f.voyage', 'v')
            ->leftJoin('f.demande', 'd')
            ->addSelect('v', 'd')
            ->where('f.user = :userId')
            ->setParameter('userId', $userId)
            ->orderBy('f.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    public function findVoyagesByUser(int $userId): array
    {
        return $this->createQueryBuilder('f')
            ->leftJoin('f.voyage', 'v')
            ->leftJoin('v.voyageur', 'u')
            ->addSelect('v', 'u')
            ->where('f.user = :userId')
            ->andWhere('f.voyage IS NOT NULL')
            ->setParameter('userId', $userId)
            ->orderBy('f.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    public function findDemandesByUser(int $userId): array
    {
        return $this->createQueryBuilder('f')
            ->leftJoin('f.demande', 'd')
            ->leftJoin('d.client', 'u')
            ->addSelect('d', 'u')
            ->where('f.user = :userId')
            ->andWhere('f.demande IS NOT NULL')
            ->setParameter('userId', $userId)
            ->orderBy('f.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    public function findByUserAndVoyage(int $userId, int $voyageId): ?Favori
    {
        return $this->createQueryBuilder('f')
            ->where('f.user = :userId')
            ->andWhere('f.voyage = :voyageId')
            ->setParameter('userId', $userId)
            ->setParameter('voyageId', $voyageId)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function findByUserAndDemande(int $userId, int $demandeId): ?Favori
    {
        return $this->createQueryBuilder('f')
            ->where('f.user = :userId')
            ->andWhere('f.demande = :demandeId')
            ->setParameter('userId', $userId)
            ->setParameter('demandeId', $demandeId)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function countByVoyage(int $voyageId): int
    {
        return $this->createQueryBuilder('f')
            ->select('COUNT(f.id)')
            ->where('f.voyage = :voyageId')
            ->setParameter('voyageId', $voyageId)
            ->getQuery()
            ->getSingleScalarResult();
    }
}
