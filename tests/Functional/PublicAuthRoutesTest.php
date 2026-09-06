<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Vérifie que /api/token/refresh et /api/logout restent joignables sans
 * access token JWT valide (cf. Phase 1 du plan de correction : ces routes
 * authentifient via le cookie de refresh token, pas via le JWT d'accès).
 */
class PublicAuthRoutesTest extends WebTestCase
{
    public function testTokenRefreshReachesControllerWithoutAccessToken(): void
    {
        $client = static::createClient();
        $client->request('POST', '/api/token/refresh');

        self::assertResponseStatusCodeSame(401);

        $payload = json_decode($client->getResponse()->getContent(), true);
        self::assertSame('Refresh token manquant', $payload['message']);
    }

    public function testLogoutReachesControllerWithoutAccessToken(): void
    {
        $client = static::createClient();
        $client->request('POST', '/api/logout');

        self::assertResponseIsSuccessful();

        $payload = json_decode($client->getResponse()->getContent(), true);
        self::assertTrue($payload['success']);
    }
}
