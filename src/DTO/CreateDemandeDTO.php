<?php

declare(strict_types=1);

namespace App\DTO;

use Symfony\Component\Validator\Constraints as Assert;

class CreateDemandeDTO
{
    #[Assert\NotBlank(message: 'La ville de départ est obligatoire')]
    #[Assert\Length(
        min: 2,
        max: 255,
        minMessage: 'La ville de départ doit contenir au moins {{ limit }} caractères',
        maxMessage: 'La ville de départ ne peut pas dépasser {{ limit }} caractères'
    )]
    public string $villeDepart;

    #[Assert\NotBlank(message: 'La ville d\'arrivée est obligatoire')]
    #[Assert\Length(
        min: 2,
        max: 255,
        minMessage: 'La ville d\'arrivée doit contenir au moins {{ limit }} caractères',
        maxMessage: 'La ville d\'arrivée ne peut pas dépasser {{ limit }} caractères'
    )]
    public string $villeArrivee;

    #[Assert\Type(type: \DateTimeInterface::class, message: 'La date limite n\'est pas valide')]
    #[Assert\GreaterThanOrEqual('today', message: 'La date limite doit être dans le futur')]
    public ?\DateTimeInterface $dateLimite = null;

    #[Assert\NotBlank(message: 'Le poids estimé est obligatoire')]
    #[Assert\Positive(message: 'Le poids doit être positif')]
    #[Assert\LessThanOrEqual(value: 50, message: 'Le poids ne peut pas dépasser {{ compared_value }} kg')]
    public float $poidsEstime;

    // ==================== NOUVEAUX CHAMPS ====================

    #[Assert\Positive(message: 'Le prix par kilo doit être positif')]
    #[Assert\LessThanOrEqual(value: 100000, message: 'Le prix par kilo ne peut pas dépasser {{ compared_value }} XAF')]
    public ?float $prixParKilo = null;

    #[Assert\Positive(message: 'La commission doit être positive')]
    #[Assert\LessThanOrEqual(value: 1000000, message: 'La commission ne peut pas dépasser {{ compared_value }} XAF')]
    public ?float $commissionProposeePourUnBagage = null;

    #[Assert\NotBlank(message: 'La description est obligatoire')]
    #[Assert\Length(
        min: 10,
        max: 1000,
        minMessage: 'La description doit contenir au moins {{ limit }} caractères',
        maxMessage: 'La description ne peut pas dépasser {{ limit }} caractères'
    )]
    public string $description;
}
