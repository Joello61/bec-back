<?php

declare(strict_types=1);

namespace App\DTO\Admin;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * Volontairement sans `code` : identifiant immuable après création, référencé par
 * SubscriptionService::checkout(string $planCode) et le frontend - le rendre modifiable
 * risquerait de casser des abonnements déjà liés à ce code.
 */
class UpdateSubscriptionPlanDTO
{
    #[Assert\NotBlank(message: 'Le nom est obligatoire')]
    #[Assert\Length(max: 100)]
    public string $name;

    #[Assert\PositiveOrZero(message: 'Le prix EUR doit être positif ou nul')]
    public ?string $priceAmountEur = null;

    #[Assert\PositiveOrZero(message: 'Le prix XAF doit être positif ou nul')]
    public ?string $priceAmountXaf = null;

    #[Assert\NotBlank]
    #[Assert\Choice(choices: ['monthly'], message: 'Seule la périodicité mensuelle est supportée')]
    public string $billingPeriod = 'monthly';

    #[Assert\PositiveOrZero(message: 'Le quota de voyages actifs doit être positif ou nul')]
    public ?int $maxActiveVoyages = null;

    #[Assert\PositiveOrZero(message: 'Le quota de demandes actives doit être positif ou nul')]
    public ?int $maxActiveDemandes = null;

    public bool $hasBadge = false;

    public bool $hasViewStats = false;

    public bool $isFeatured = false;

    public bool $isActive = true;

    public int $sortOrder = 0;

    #[Assert\Length(max: 255)]
    public ?string $stripePriceId = null;
}
