<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\BoostOffer;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<BoostOffer>
 */
class BoostOfferRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, BoostOffer::class);
    }

    public function findById(int $id): ?BoostOffer
    {
        return $this->findOneBy(['id' => $id, 'deletedAt' => null]);
    }

    /**
     * Offres proposées à l'achat (actives, non soft-supprimées), triées pour affichage.
     * @return BoostOffer[]
     */
    public function findAllActive(): array
    {
        return $this->createQueryBuilder('o')
            ->where('o.isActive = :active')
            ->andWhere('o.deletedAt IS NULL')
            ->setParameter('active', true)
            ->orderBy('o.sortOrder', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
