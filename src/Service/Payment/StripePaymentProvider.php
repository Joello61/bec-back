<?php

declare(strict_types=1);

namespace App\Service\Payment;

use App\Entity\SubscriptionPlan;
use App\Entity\User;
use App\Entity\UserSubscription;
use Stripe\Checkout\Session;
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

        $this->applyCustomer($params, $user, $existingProviderCustomerId);

        $session = $this->stripeClient->checkout->sessions->create($params);

        return $this->toResult($session);
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

    public function createOneTimeCheckoutSession(
        User $user,
        string $productName,
        string $amount,
        string $currency,
        string $clientReferenceId,
        ?string $existingProviderCustomerId,
        string $successUrl,
        string $cancelUrl,
    ): CheckoutSessionResult {
        $params = [
            'mode' => 'payment',
            'line_items' => [
                [
                    'price_data' => [
                        'currency' => strtolower($currency),
                        'product_data' => ['name' => $productName],
                        'unit_amount' => (int) round(((float) $amount) * 100),
                    ],
                    'quantity' => 1,
                ],
            ],
            'client_reference_id' => $clientReferenceId,
            'success_url' => $successUrl,
            'cancel_url' => $cancelUrl,
        ];

        $this->applyCustomer($params, $user, $existingProviderCustomerId);

        $session = $this->stripeClient->checkout->sessions->create($params);

        return $this->toResult($session);
    }

    /**
     * @param array<string, mixed> $params
     */
    private function applyCustomer(array &$params, User $user, ?string $existingProviderCustomerId): void
    {
        if ($existingProviderCustomerId !== null) {
            $params['customer'] = $existingProviderCustomerId;
        } else {
            $params['customer_email'] = $user->getEmail();
        }
    }

    private function toResult(Session $session): CheckoutSessionResult
    {
        return new CheckoutSessionResult(
            checkoutUrl: $session->url,
            providerCustomerId: is_string($session->customer) ? $session->customer : null,
        );
    }
}
