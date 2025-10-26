<?php

declare(strict_types=1);

namespace App\Service;

use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Response;

/**
 * Service centralisé pour la gestion des cookies de l'application
 * Permet d'éviter la duplication de code dans les contrôleurs
 */
readonly class CookieManager
{
    // Constantes pour les noms de cookies
    public const COOKIE_JWT = 'bagage_token';
    public const COOKIE_REFRESH_TOKEN = 'bagage_refresh_token';
    public const COOKIE_MERCURE = 'mercureAuthorization';

    public function __construct(
        private ParameterBagInterface $params,
        private LoggerInterface $logger
    ) {}

    /**
     * Crée un cookie JWT principal
     */
    public function createJwtCookie(string $token): Cookie
    {
        return $this->createSecureCookie(
            name: self::COOKIE_JWT,
            value: $token,
            ttl: $this->getJwtTtl(),
            sameSite: $this->getSameSite()
        );
    }

    /**
     * Crée un cookie de refresh token
     */
    public function createRefreshTokenCookie(string $token): Cookie
    {
        return $this->createSecureCookie(
            name: self::COOKIE_REFRESH_TOKEN,
            value: $token,
            ttl: $this->getRefreshTokenTtl(),
            sameSite: 'none'
        );
    }

    /**
     * Crée un cookie Mercure pour les notifications temps réel
     */
    public function createMercureCookie(string $token): Cookie
    {
        return $this->createSecureCookie(
            name: self::COOKIE_MERCURE,
            value: $token,
            ttl: 3600, // 1 heure
            sameSite: 'none' // Nécessaire pour Mercure
        );
    }

    /**
     * Crée un cookie sécurisé avec les paramètres standards
     */
    private function createSecureCookie(
        string $name,
        string $value,
        int $ttl,
        string $sameSite = 'lax'
    ): Cookie {
        return Cookie::create($name)
            ->withValue($value)
            ->withExpires(time() + $ttl)
            ->withPath('/')
            ->withDomain($this->getCookieDomain())
            ->withSecure($this->isCookieSecure())
            ->withHttpOnly(true)
            ->withSameSite($sameSite);
    }

    /**
     * Crée un cookie expiré pour suppression
     */
    public function createExpiredCookie(string $cookieName, string $sameSite = 'lax'): Cookie
    {
        return Cookie::create($cookieName)
            ->withValue('')
            ->withExpires(time() - 3600)
            ->withPath('/')
            ->withDomain($this->getCookieDomain())
            ->withSecure($this->isCookieSecure())
            ->withHttpOnly(true)
            ->withSameSite($sameSite);
    }

    /**
     * Attache un cookie à une réponse
     */
    public function attachCookie(Response $response, Cookie $cookie): Response
    {
        $response->headers->setCookie($cookie);
        return $response;
    }

    /**
     * Attache plusieurs cookies à une réponse
     *
     * @param Cookie[] $cookies
     */
    public function attachCookies(Response $response, array $cookies): Response
    {
        foreach ($cookies as $cookie) {
            $response->headers->setCookie($cookie);
        }
        return $response;
    }

    /**
     * Supprime un cookie de la réponse
     */
    public function clearCookie(Response $response, string $cookieName, string $sameSite = 'lax'): Response
    {
        $expiredCookie = $this->createExpiredCookie($cookieName, $sameSite);
        return $this->attachCookie($response, $expiredCookie);
    }

    /**
     * Supprime tous les cookies d'authentification
     */
    public function clearAuthCookies(Response $response): Response
    {
        $this->clearCookie($response, self::COOKIE_JWT);
        $this->clearCookie($response, self::COOKIE_REFRESH_TOKEN);
        $this->clearCookie($response, self::COOKIE_MERCURE, 'none');

        $this->logger->info('Tous les cookies d\'authentification ont été supprimés');

        return $response;
    }

    /**
     * Crée un ensemble complet de cookies d'authentification
     *
     * @return Cookie[] Un tableau de cookies [jwt, refreshToken, mercure]
     */
    public function createAuthCookies(
        string $jwtToken,
        string $refreshToken,
        string $mercureToken
    ): array {
        return [
            'jwt' => $this->createJwtCookie($jwtToken),
            'refreshToken' => $this->createRefreshTokenCookie($refreshToken),
            'mercure' => $this->createMercureCookie($mercureToken),
        ];
    }

    /**
     * Attache un ensemble complet de cookies d'authentification à une réponse
     */
    public function attachAuthCookies(
        Response $response,
        string $jwtToken,
        string $refreshToken,
        string $mercureToken
    ): Response {
        $cookies = $this->createAuthCookies($jwtToken, $refreshToken, $mercureToken);
        return $this->attachCookies($response, $cookies);
    }

    // ==================== Getters pour les paramètres ====================

    private function getCookieDomain(): ?string
    {
        return $this->params->get('app.jwt_cookie_domain');
    }

    private function isCookieSecure(): bool
    {
        return $this->params->get('app.jwt_cookie_secure');
    }

    private function getSameSite(): string
    {
        return $this->params->get('app.jwt_cookie_same_site');
    }

    private function getJwtTtl(): int
    {
        return (int) $this->params->get('app.jwt_ttl');
    }

    private function getRefreshTokenTtl(): int
    {
        return (int) $this->params->get('app.refresh_token_ttl');
    }

    /**
     * Obtient le nom du cookie JWT
     */
    public function getJwtCookieName(): string
    {
        return self::COOKIE_JWT;
    }

    /**
     * Obtient le nom du cookie refresh token
     */
    public function getRefreshTokenCookieName(): string
    {
        return self::COOKIE_REFRESH_TOKEN;
    }

    /**
     * Obtient le nom du cookie Mercure
     */
    public function getMercureCookieName(): string
    {
        return self::COOKIE_MERCURE;
    }
}
