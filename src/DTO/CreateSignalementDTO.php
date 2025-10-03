<?php

declare(strict_types=1);

namespace App\DTO;

use Symfony\Component\Validator\Constraints as Assert;

class CreateSignalementDTO
{
    #[Assert\Positive(message: 'L\'ID du voyage doit être positif')]
    public ?int $voyageId = null;

    #[Assert\Positive(message: 'L\'ID de la demande doit être positif')]
    public ?int $demandeId = null;

    #[Assert\NotBlank(message: 'Le motif est obligatoire')]
    #[Assert\Choice(
        choices: ['contenu_inapproprie', 'spam', 'arnaque', 'objet_illegal', 'autre'],
        message: 'Le motif n\'est pas valide'
    )]
    public string $motif;

    #[Assert\NotBlank(message: 'La description est obligatoire')]
    #[Assert\Length(
        min: 10,
        max: 1000,
        minMessage: 'La description doit contenir au moins {{ limit }} caractères',
        maxMessage: 'La description ne peut pas dépasser {{ limit }} caractères'
    )]
    public string $description;
}
