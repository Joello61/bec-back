<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Transaction;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Transaction>
 */
class TransactionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Transaction::class);
    }

    /**
     * Point de lecture de l'idempotence webhook : UNIQUE(provider, provider_payment_id) en base.
     */
    public function findByProviderPaymentId(string $provider, string $providerPaymentId): ?Transaction
    {
        return $this->findOneBy([
            'provider' => $provider,
            'providerPaymentId' => $providerPaymentId,
        ]);
    }
}
