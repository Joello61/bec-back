<?php

declare(strict_types=1);

namespace App\Message;

/**
 * Message pour déclencher l'envoi des rappels de renouvellement Mobile Money (Lot 3)
 */
final readonly class SendRenewalReminderMessage
{
    public function __construct(
        public ?int $batchSize = 100
    ) {}
}
