<?php

declare(strict_types=1);

namespace App\Message;

/**
 * Message pour déclencher l'expiration des boosts (monétisation Lot 2)
 */
final readonly class ExpireBoostsMessage
{
    public function __construct(
        public ?int $batchSize = 100
    ) {}
}
