<?php

declare(strict_types=1);

namespace App\Message;

/**
 * Message pour déclencher l'expiration des voyages
 */
final readonly class ExpireVoyagesMessage
{
    public function __construct(
        public ?int $batchSize = 100
    ) {}
}
