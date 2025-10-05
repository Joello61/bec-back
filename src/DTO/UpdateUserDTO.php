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
        pattern: '/^\+?[1-9]\d{1,14}$/',
        message: 'Le numéro de téléphone n\'est pas valide. Format attendu: +33612345678 ou +237612345678'
    )]
    public ?string $telephone = null;

    #[Assert\Length(
        max: 500,
        maxMessage: 'La bio ne peut pas dépasser {{ limit }} caractères'
    )]
    public ?string $bio = null;

    #[Assert\Url(
        message: 'L\'URL de la photo n\'est pas valide'
    )]
    public ?string $photo = null;
}
