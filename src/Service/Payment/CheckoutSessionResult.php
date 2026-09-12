<?php

declare(strict_types=1);

namespace App\Service\Payment;

final readonly class CheckoutSessionResult
{
    public function __construct(
        public string $checkoutUrl,
        public ?string $providerCustomerId = null,
    ) {}
}
