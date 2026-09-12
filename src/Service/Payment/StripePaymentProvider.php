<?php

declare(strict_types=1);

namespace App\Service\Payment;

use App\Entity\SubscriptionPlan;
use App\Entity\User;
use App\Entity\UserSubscription;
use Stripe\StripeClient;

readonly class StripePaymentProvider implements PaymentProviderInterface
{
    public function __construct(
        private StripeClient $stripeClient,
    ) {}

    public function createCheckoutSession(
        User $user,
        SubscriptionPlan $plan,
        string $clientReferenceId,
        ?string $existingProviderCustomerId,
        string $successUrl,
        string $cancelUrl,
    ): CheckoutSessionResult {
        $params = [
            'mode' => 'subscription',
            'line_items' => [
                ['price' => $plan->getStripePriceId(), 'quantity' => 1],
            ],
            'client_reference_id' => $clientReferenceId,
            'success_url' => $successUrl,
            'cancel_url' => $cancelUrl,
        ];

        if ($existingProviderCustomerId !== null) {
            $params['customer'] = $existingProviderCustomerId;
        } else {
            $params['customer_email'] = $user->getEmail();
        }

        $session = $this->stripeClient->checkout->sessions->create($params);

        return new CheckoutSessionResult(
            checkoutUrl: $session->url,
            providerCustomerId: is_string($session->customer) ? $session->customer : null,
        );
    }

    public function cancelSubscription(UserSubscription $subscription): void
    {
        $providerSubscriptionId = $subscription->getProviderSubscriptionId();

        if ($providerSubscriptionId === null) {
            return;
        }

        $this->stripeClient->subscriptions->update($providerSubscriptionId, [
            'cancel_at_period_end' => true,
        ]);
    }
}
