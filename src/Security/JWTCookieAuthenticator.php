<?php

declare(strict_types=1);

namespace App\Security;

use Lexik\Bundle\JWTAuthenticationBundle\Security\Authenticator\JWTAuthenticator;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Http\Authenticator\Passport\Passport;

class JWTCookieAuthenticator extends JWTAuthenticator
{
    public function authenticate(Request $request): Passport
    {
        // Récupérer le token depuis le cookie
        $token = $request->cookies->get($_ENV['JWT_COOKIE_NAME'] ?? 'bagage_token');

        if (!$token) {
            throw new AuthenticationException('JWT Token not found in cookie');
        }

        // Ajouter temporairement le token dans le header pour le traitement standard
        $request->headers->set('Authorization', 'Bearer ' . $token);

        return parent::authenticate($request);
    }

    public function onAuthenticationSuccess(Request $request, TokenInterface $token, string $firewallName): ?Response
    {
        // Laisser la requête continuer
        return null;
    }

    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): ?Response
    {
        return new Response(
            json_encode(['message' => $exception->getMessage()]),
            Response::HTTP_UNAUTHORIZED,
            ['Content-Type' => 'application/json']
        );
    }
}
