<?php

declare(strict_types=1);

namespace App\Message;

/**
 * Message pour déclencher l'expiration des demandes
 */
final readonly class ExpireDemandesMessage
{
    public function __construct(
        public ?int $batchSize = 100
    ) {}
}
