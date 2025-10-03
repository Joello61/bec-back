<?php

declare(strict_types=1);

namespace App\DTO;

use Symfony\Component\Validator\Constraints as Assert;

class UpdateUserDTO
{
    #[Assert\Length(
        min: 2,
        max: 100,
        minMessage: 'Le nom doit contenir au moins {{ limit }} caractères',
        maxMessage: 'Le nom ne peut pas dépasser {{ limit }} caractères'
    )]
    public ?string $nom = null;

    #[Assert\Length(
        min: 2,
        max: 100,
        minMessage: 'Le prénom doit contenir au moins {{ limit }} caractères',
        maxMessage: 'Le prénom ne peut pas dépasser {{ limit }} caractères'
    )]
    public ?string $prenom = null;

    #[Assert\Regex(
        pattern: '/^(6[5-9]\d{7}|2[3-4]\d{7})$/',
        message: 'Le numéro de téléphone camerounais n\'est pas valide'
    )]
    public ?string $telephone = null;

    #[Assert\Length(
        max: 1000,
        maxMessage: 'La bio ne peut pas dépasser {{ limit }} caractères'
    )]
    public ?string $bio = null;

    public ?string $photo = null;
}
