<?php

declare(strict_types=1);

namespace App\DTO;

use Symfony\Component\Serializer\Annotation\Groups;

readonly class DashboardDTO
{
    /**
     * @param array<string, mixed> $summary
     * @param array<string, mixed> $voyages
     * @param array<string, mixed> $demandes
     * @param array<string, mixed> $notifications
     * @param array<string, mixed> $messages
     * @param array<string, mixed> $stats
     */
    public function __construct(
        #[Groups(['dashboard:read'])]
        public array $summary,

        #[Groups(['dashboard:read'])]
        public array $voyages,

        #[Groups(['dashboard:read'])]
        public array $demandes,

        #[Groups(['dashboard:read'])]
        public array $notifications,

        #[Groups(['dashboard:read'])]
        public array $messages,

        #[Groups(['dashboard:read'])]
        public array $stats
    ) {}

    /**
     * @param array<string, mixed> $summary
     * @param array<string, mixed> $voyages
     * @param array<string, mixed> $demandes
     * @param array<string, mixed> $notifications
     * @param array<string, mixed> $messages
     * @param array<string, mixed> $stats
     */
    public static function create(
        array $summary,
        array $voyages,
        array $demandes,
        array $notifications,
        array $messages,
        array $stats
    ): self {
        return new self(
            summary: $summary,
            voyages: $voyages,
            demandes: $demandes,
            notifications: $notifications,
            messages: $messages,
            stats: $stats
        );
    }
}
