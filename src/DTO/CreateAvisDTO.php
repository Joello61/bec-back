<?php

declare(strict_types=1);

namespace App\DTO;

use Symfony\Component\Validator\Constraints as Assert;

class CreateAvisDTO
{
    #[Assert\NotBlank(message: 'L\'utilisateur cible est obligatoire')]
    #[Assert\Positive(message: 'L\'ID de l\'utilisateur cible doit être positif')]
    public int $cibleId;

    #[Assert\Positive(message: 'L\'ID du voyage doit être positif')]
    public ?int $voyageId = null;

    #[Assert\NotBlank(message: 'La note est obligatoire')]
    #[Assert\Range(
        notInRangeMessage: 'La note doit être entre {{ min }} et {{ max }}',
        min: 1,
        max: 5
    )]
    public int $note;

    #[Assert\Length(
        max: 500,
        maxMessage: 'Le commentaire ne peut pas dépasser {{ limit }} caractères'
    )]
    public ?string $commentaire = null;
}
