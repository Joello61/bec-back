<?php

declare(strict_types=1);

namespace App\OpenApi\Schema;

/**
 * Schema de reponse de UserController::manageAvatar() (upload et suppression),
 * extrait du bloc OA\JsonContent inline (audit Backend-Qualite #7, Phase 6).
 */
class AvatarUploadResponse
{
    public bool $success;

    public string $message;

    public ?string $photoUrl;
}
