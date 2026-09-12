<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\User;
use App\Entity\UserSubscription;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<UserSubscription>
 */
class UserSubscriptionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, UserSubscription::class);
    }

    /**
     * L'abonnement actif de l'utilisateur, s'il existe. Un utilisateur ne peut avoir
     * qu'un seul UserSubscription actif à la fois (garanti par SubscriptionService).
     */
    public function findActiveForUser(User $user): ?UserSubscription
    {
        return $this->createQueryBuilder('s')
            ->andWhere('s.user = :user')
            ->andWhere('s.status = :status')
            ->setParameter('user', $user)
            ->setParameter('status', UserSubscription::STATUS_ACTIVE)
            ->orderBy('s.createdAt', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function findByProviderSubscriptionId(string $provider, string $providerSubscriptionId): ?UserSubscription
    {
        return $this->findOneBy([
            'provider' => $provider,
            'providerSubscriptionId' => $providerSubscriptionId,
        ]);
    }

    /**
     * Identifiant client provider (Stripe Customer) le plus récent connu pour cet
     * utilisateur, pour éviter de recréer un customer à chaque nouveau checkout.
     */
    public function findLatestProviderCustomerId(User $user, string $provider): ?string
    {
        $result = $this->createQueryBuilder('s')
            ->select('s.providerCustomerId')
            ->andWhere('s.user = :user')
            ->andWhere('s.provider = :provider')
            ->andWhere('s.providerCustomerId IS NOT NULL')
            ->setParameter('user', $user)
            ->setParameter('provider', $provider)
            ->orderBy('s.createdAt', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        return $result['providerCustomerId'] ?? null;
    }
}
