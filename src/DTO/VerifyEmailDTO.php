<?php

declare(strict_types=1);

namespace App\DTO;

use Symfony\Component\Validator\Constraints as Assert;

class VerifyEmailDTO
{
    #[Assert\NotBlank(message: 'Le code est obligatoire')]
    #[Assert\Length(
        exactly: 6,
        exactMessage: 'Le code doit contenir exactement {{ limit }} caractères'
    )]
    #[Assert\Regex(
        pattern: '/^\d{6}$/',
        message: 'Le code doit contenir uniquement des chiffres'
    )]
    public string $code;
}
