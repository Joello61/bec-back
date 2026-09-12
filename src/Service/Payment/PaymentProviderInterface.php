<?php

declare(strict_types=1);

namespace App\Service\Payment;

use App\Entity\SubscriptionPlan;
use App\Entity\User;
use App\Entity\UserSubscription;

/**
 * Une seule implementation existe dans ce lot (StripePaymentProvider) : Symfony
 * l'autowire directement par type. Le selecteur multi-provider (!tagged_locator par
 * cle 'card'/'mobile_money') sera introduit au Lot 3 quand Notch Pay rend le choix reel.
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
}
