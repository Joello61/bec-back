<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\SubscriptionPlan;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<SubscriptionPlan>
 */
class SubscriptionPlanRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, SubscriptionPlan::class);
    }

    public function findByCode(string $code): ?SubscriptionPlan
    {
        return $this->findOneBy(['code' => $code, 'deletedAt' => null]);
    }

    /**
     * Plans proposés à la souscription (actifs, non soft-supprimés), triés pour affichage.
     * @return SubscriptionPlan[]
     */
    public function findAllActive(): array
    {
        return $this->createQueryBuilder('p')
            ->where('p.isActive = :active')
            ->andWhere('p.deletedAt IS NULL')
            ->setParameter('active', true)
            ->orderBy('p.sortOrder', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Catalogue complet pour l'admin (Lot 5) : y compris inactifs et soft-supprimés,
     * contrairement à findAllActive() destiné à l'API publique.
     * @return SubscriptionPlan[]
     */
    public function findAllForAdmin(): array
    {
        return $this->createQueryBuilder('p')
            ->orderBy('p.sortOrder', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
