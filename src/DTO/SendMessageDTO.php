<?php

declare(strict_types=1);

namespace App\DTO;

use Symfony\Component\Validator\Constraints as Assert;

class SendMessageDTO
{
    #[Assert\NotBlank(message: 'Le destinataire est obligatoire')]
    #[Assert\Positive(message: 'L\'ID du destinataire doit être positif')]
    public int $destinataireId;

    #[Assert\NotBlank(message: 'Le message est obligatoire')]
    #[Assert\Length(
        min: 1,
        max: 2000,
        minMessage: 'Le message doit contenir au moins {{ limit }} caractère',
        maxMessage: 'Le message ne peut pas dépasser {{ limit }} caractères'
    )]
    public string $contenu;
}
