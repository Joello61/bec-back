<?php

declare(strict_types=1);

namespace App\OpenApi\Schema;

/**
 * Schema de reponse de AuthController::googleAuth()/facebookAuth() - identique
 * pour les deux providers, factorise ici plutot que duplique en inline
 * OA\JsonContent (audit Backend-Qualite #7, Phase 6).
 */
class OAuthAuthorizationResponse
{
    public string $authUrl;

    public string $state;
}
