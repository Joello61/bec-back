<?php

declare(strict_types=1);

namespace App\DTO;

use Symfony\Component\Validator\Constraints as Assert;

class CreatePropositionDTO
{
    #[Assert\NotBlank(message: 'L\'ID de la demande est obligatoire')]
    #[Assert\Positive(message: 'L\'ID de la demande doit être positif')]
    public int $demandeId;

    #[Assert\NotBlank(message: 'Le prix par kilo est obligatoire')]
    #[Assert\Positive(message: 'Le prix par kilo doit être positif')]
    #[Assert\LessThanOrEqual(value: 1000000, message: 'Le prix par kilo ne peut pas dépasser {{ compared_value }}')]
    public float $prixParKilo;

    #[Assert\NotBlank(message: 'La commission proposée est obligatoire')]
    #[Assert\Positive(message: 'La commission doit être positive')]
    #[Assert\LessThanOrEqual(value: 10000000, message: 'La commission ne peut pas dépasser {{ compared_value }}')]
    public float $commissionProposeePourUnBagage;

    #[Assert\Length(
        max: 1000,
        maxMessage: 'Le message ne peut pas dépasser {{ limit }} caractères'
    )]
    public ?string $message = null;
}
