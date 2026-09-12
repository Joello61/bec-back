<?php

declare(strict_types=1);

namespace App\Service\Payment;

use App\Entity\SubscriptionPlan;
use App\Entity\Transaction;
use App\Entity\User;
use App\Entity\UserSubscription;

/**
 * Deux implementations (StripePaymentProvider, NotchPayPaymentProvider), selectionnees
 * via un tagged locator indexe par famille de paiement ('card'/'mobile_money', cf.
 * SubscriptionService::resolveProvider()).
 */
interface PaymentProviderInterface
{
    public function createCheckoutSession(
        User $user,
        SubscriptionPlan $plan,
        string $clientReferenceId,
        ?string $existingProviderCustomerId,
        string $successUrl,
        string $cancelUrl,
    ): CheckoutSessionResult;

    public function cancelSubscription(UserSubscription $subscription): void;

    /**
     * Paiement à l'acte (boost, Lot 2) : mode "payment" avec un prix ad-hoc
     * (price_data inline), distinct de createCheckoutSession() qui exige un Price
     * Stripe recurring pré-créé (mode "subscription").
     */
    public function createOneTimeCheckoutSession(
        User $user,
        string $productName,
        string $amount,
        string $currency,
        string $clientReferenceId,
        ?string $existingProviderCustomerId,
        string $successUrl,
        string $cancelUrl,
    ): CheckoutSessionResult;

    /**
     * Remboursement total (Lot 6.1) - jamais partiel, cf. politique CGU art. 10.5.
     * Doit lever une exception (jamais un faux succès silencieux) si le remboursement
     * echoue cote prestataire.
     */
    public function refundTransaction(Transaction $transaction): void;
}
