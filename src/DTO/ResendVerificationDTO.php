<?php

declare(strict_types=1);

namespace App\DTO;

use Symfony\Component\Validator\Constraints as Assert;

class ResendVerificationDTO
{
    #[Assert\NotBlank(message: 'Le type est obligatoire')]
    #[Assert\Choice(
        choices: ['email', 'phone'],
        message: 'Le type doit être "email" ou "phone"'
    )]
    public string $type;
}
