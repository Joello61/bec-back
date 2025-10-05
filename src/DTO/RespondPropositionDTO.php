<?php

declare(strict_types=1);

namespace App\DTO;

use Symfony\Component\Validator\Constraints as Assert;

class RespondPropositionDTO
{
    #[Assert\NotBlank(message: 'La réponse est obligatoire')]
    #[Assert\Choice(
        choices: ['accepter', 'refuser'],
        message: 'La réponse doit être "accepter" ou "refuser"'
    )]
    public string $action;

    #[Assert\Length(
        max: 500,
        maxMessage: 'Le message de refus ne peut pas dépasser {{ limit }} caractères'
    )]
    public ?string $messageRefus = null;
}
