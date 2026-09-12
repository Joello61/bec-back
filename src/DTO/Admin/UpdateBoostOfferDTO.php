<?php

declare(strict_types=1);

namespace App\DTO\Admin;

use Symfony\Component\Validator\Constraints as Assert;

class UpdateBoostOfferDTO
{
    #[Assert\NotBlank(message: 'Le nom est obligatoire')]
    #[Assert\Length(max: 100)]
    public string $name;

    #[Assert\NotBlank(message: 'La durée est obligatoire')]
    #[Assert\Positive(message: 'La durée doit être positive')]
    public int $durationDays;

    #[Assert\NotBlank(message: 'Le prix EUR est obligatoire')]
    #[Assert\PositiveOrZero(message: 'Le prix EUR doit être positif ou nul')]
    public string $priceAmountEur;

    #[Assert\PositiveOrZero(message: 'Le prix XAF doit être positif ou nul')]
    public ?string $priceAmountXaf = null;

    public bool $isFeatured = false;

    public bool $isActive = true;

    public int $sortOrder = 0;
}
