<?php

declare(strict_types=1);

namespace App\Message;

/**
 * Message pour declencher l'envoi des rappels de fin de boost imminente (Lot N7)
 */
final readonly class SendBoostEndingReminderMessage
{
    public function __construct(
        public ?int $batchSize = 100
    ) {}
}
