<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Boost;
use App\Entity\Transaction;
use App\Entity\User;
use App\Entity\UserSubscription;
use App\Repository\TransactionRepository;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Point d'idempotence central des webhooks de paiement. UNIQUE(provider,
 * provider_payment_id) en base est la garantie ultime (retries at-least-once
 * documentes cote Stripe et Notch Pay) - la verification prealable en lecture est une
 * optimisation, pas la seule protection.
 */
readonly class PaymentService
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private TransactionRepository $transactionRepository,
        private LoggerInterface $logger,
    ) {}

    /**
     * @param array<string, mixed> $rawPayload
     */
    public function findOrCreateFromProviderEvent(
        string $provider,
        string $providerPaymentId,
        User $user,
        ?UserSubscription $subscription,
        string $type,
        string $paymentMethodFamily,
        string $amount,
        string $currency,
        string $status,
        array $rawPayload,
        ?Boost $boost = null,
    ): Transaction {
        $existing = $this->transactionRepository->findByProviderPaymentId($provider, $providerPaymentId);

        if ($existing !== null) {
            $this->logger->info('Transaction deja traitee (idempotence webhook)', [
                'provider' => $provider,
                'providerPaymentId' => $providerPaymentId,
            ]);

            return $existing;
        }

        try {
            return $this->entityManager->wrapInTransaction(
                function () use (
                    $provider,
                    $providerPaymentId,
                    $user,
                    $subscription,
                    $boost,
                    $type,
                    $paymentMethodFamily,
                    $amount,
                    $currency,
                    $status,
                    $rawPayload,
                ): Transaction {
                    $transaction = new Transaction();
                    $transaction->setProvider($provider)
                        ->setProviderPaymentId($providerPaymentId)
                        ->setUser($user)
                        ->setSubscription($subscription)
                        ->setBoost($boost)
                        ->setType($type)
                        ->setPaymentMethodFamily($paymentMethodFamily)
                        ->setAmount($amount)
                        ->setCurrency($currency)
                        ->setStatus($status)
                        ->setRawPayload($rawPayload);

                    $this->entityManager->persist($transaction);
                    $this->entityManager->flush();

                    return $transaction;
                }
            );
        } catch (UniqueConstraintViolationException) {
            // Course concurrente avec un autre retry du meme webhook : la ligne existe
            // desormais, on la relit plutot que de propager l'erreur.
            $existing = $this->transactionRepository->findByProviderPaymentId($provider, $providerPaymentId);

            if ($existing === null) {
                throw new \RuntimeException(
                    "Transaction introuvable après conflit d'unicité ($provider, $providerPaymentId)"
                );
            }

            return $existing;
        }
    }
}
