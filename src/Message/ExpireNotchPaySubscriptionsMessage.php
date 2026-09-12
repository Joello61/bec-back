<?php

declare(strict_types=1);

namespace App\Message;

/**
 * Message pour déclencher l'expiration des abonnements Mobile Money en délai de grâce
 * dépassé sans renouvellement (Lot 3)
 */
final readonly class ExpireNotchPaySubscriptionsMessage
{
    public function __construct(
        public ?int $batchSize = 100
    ) {}
}
