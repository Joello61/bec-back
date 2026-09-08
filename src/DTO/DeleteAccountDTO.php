<?php

declare(strict_types=1);

namespace App\DTO;

class DeleteAccountDTO
{
    /**
     * Obligatoire pour un compte local (avec mot de passe), ignore pour un compte OAuth pur.
     */
    public ?string $currentPassword = null;
}
