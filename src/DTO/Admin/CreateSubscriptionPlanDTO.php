<?php

declare(strict_types=1);

namespace App\DTO\Admin;

use Symfony\Component\Validator\Constraints as Assert;

class CreateSubscriptionPlanDTO
{
    /**
     * Identifiant technique du palier, immuable une fois créé - utilisé par
     * SubscriptionService::checkout(string $planCode).
     */
    #[Assert\NotBlank(message: 'Le code est obligatoire')]
    #[Assert\Regex(pattern: '/^[a-z0-9_-]+$/', message: 'Le code ne doit contenir que des minuscules, chiffres, tirets et underscores')]
    #[Assert\Length(max: 30)]
    public string $code;

    #[Assert\NotBlank(message: 'Le nom est obligatoire')]
    #[Assert\Length(max: 100)]
    public string $name;

    #[Assert\PositiveOrZero(message: 'Le prix EUR doit être positif ou nul')]
    public ?string $priceAmountEur = null;

    #[Assert\PositiveOrZero(message: 'Le prix XAF doit être positif ou nul')]
    public ?string $priceAmountXaf = null;

    #[Assert\PositiveOrZero(message: 'Le prix EUR annuel doit être positif ou nul')]
    public ?string $priceAmountEurYearly = null;

    #[Assert\PositiveOrZero(message: 'Le prix XAF annuel doit être positif ou nul')]
    public ?string $priceAmountXafYearly = null;

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

    #[Assert\Length(max: 255)]
    public ?string $stripePriceIdYearly = null;
}
