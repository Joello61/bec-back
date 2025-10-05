<?php

declare(strict_types=1);

namespace App\DTO;

use Symfony\Component\Validator\Constraints as Assert;

class UpdateVoyageDTO
{
    #[Assert\Length(
        min: 2,
        max: 255,
        minMessage: 'La ville de départ doit contenir au moins {{ limit }} caractères',
        maxMessage: 'La ville de départ ne peut pas dépasser {{ limit }} caractères'
    )]
    public ?string $villeDepart = null;

    #[Assert\Length(
        min: 2,
        max: 255,
        minMessage: 'La ville d\'arrivée doit contenir au moins {{ limit }} caractères',
        maxMessage: 'La ville d\'arrivée ne peut pas dépasser {{ limit }} caractères'
    )]
    public ?string $villeArrivee = null;

    #[Assert\Type(type: \DateTimeInterface::class, message: 'La date de départ n\'est pas valide')]
    #[Assert\GreaterThanOrEqual('today', message: 'La date de départ doit être dans le futur')]
    public ?\DateTimeInterface $dateDepart = null;

    #[Assert\Type(type: \DateTimeInterface::class, message: 'La date d\'arrivée n\'est pas valide')]
    public ?\DateTimeInterface $dateArrivee = null;

    #[Assert\Positive(message: 'Le poids doit être positif')]
    #[Assert\LessThanOrEqual(value: 50, message: 'Le poids ne peut pas dépasser {{ compared_value }} kg')]
    public ?float $poidsDisponible = null;

    // ==================== NOUVEAUX CHAMPS ====================

    #[Assert\Positive(message: 'Le prix par kilo doit être positif')]
    #[Assert\LessThanOrEqual(value: 100000, message: 'Le prix par kilo ne peut pas dépasser {{ compared_value }} XAF')]
    public ?float $prixParKilo = null;

    #[Assert\Positive(message: 'La commission doit être positive')]
    #[Assert\LessThanOrEqual(value: 1000000, message: 'La commission ne peut pas dépasser {{ compared_value }} XAF')]
    public ?float $commissionProposeePourUnBagage = null;

    #[Assert\Length(
        max: 1000,
        maxMessage: 'La description ne peut pas dépasser {{ limit }} caractères'
    )]
    public ?string $description = null;
}
