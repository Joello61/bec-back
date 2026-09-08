<?php

declare(strict_types=1);

namespace App\OpenApi\Schema;

/**
 * Schema de reponse de UserController::profileStatus(), extrait du bloc
 * OA\JsonContent inline (audit Backend-Qualite #7, Phase 6).
 */
class ProfileCompletionStatusResponse
{
    public bool $isComplete;

    /** @var string[] */
    public array $missing;

    public bool $emailVerifie;

    public bool $telephoneVerifie;

    public bool $hasAddress;
}
