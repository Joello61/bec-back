<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\CookieManager;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Phase 1 du plan de correction (bec-docs/docs/plan-correction/plan-correction-cobage.md) :
 * les cookies d'authentification doivent être émis en SameSite=Lax (jamais None),
 * front et back étant same-origin en cible de déploiement. CookieManager est
 * l'unique source de configuration des trois cookies (bagage_token,
 * bagage_refresh_token, mercureAuthorization) depuis cette phase.
 */
class CookieManagerTest extends KernelTestCase
{
    private CookieManager $cookieManager;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->cookieManager = static::getContainer()->get(CookieManager::class);
    }

    public function testJwtCookieUsesSameSiteLax(): void
    {
        $cookie = $this->cookieManager->createJwtCookie('dummy-token');

        self::assertSame('lax', $cookie->getSameSite());
    }

    public function testRefreshTokenCookieUsesSameSiteLax(): void
    {
        $cookie = $this->cookieManager->createRefreshTokenCookie('dummy-refresh-token');

        self::assertSame('lax', $cookie->getSameSite());
    }

    public function testMercureCookieUsesSameSiteLax(): void
    {
        $cookie = $this->cookieManager->createMercureCookie('dummy-mercure-token');

        self::assertSame('lax', $cookie->getSameSite());
    }

    public function testAllAuthenticationCookiesShareTheSameSameSitePolicy(): void
    {
        $cookies = $this->cookieManager->createAuthCookies('jwt', 'refresh', 'mercure');

        $sameSiteValues = array_map(
            static fn ($cookie) => $cookie->getSameSite(),
            $cookies
        );

        self::assertCount(1, array_unique($sameSiteValues), 'Les trois cookies doivent partager la même politique SameSite (source unique de configuration, cf. Phase 1)');
    }
}
