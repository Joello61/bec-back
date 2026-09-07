<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Entity\User;
use Symfony\Component\BrowserKit\Cookie;

/**
 * Authentifie le client de test via un vrai JWT (pas de mock du firewall).
 * Attend que la classe consommatrice expose `$this->client` (KernelBrowser)
 * et `$this->jwtManager` (JWTTokenManagerInterface), comme le fait déjà chaque
 * test qui l'utilisait avant l'extraction de ce trait.
 */
trait JwtAuthenticationTrait
{
    protected function authenticateAs(User $user): void
    {
        $token = $this->jwtManager->create($user);
        $this->client->getCookieJar()->set(new Cookie('bagage_token', $token, null, '/', 'localhost', false, false));
    }
}
