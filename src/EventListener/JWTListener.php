<?php

declare(strict_types=1);

namespace App\EventListener;

use Lexik\Bundle\JWTAuthenticationBundle\Event\AuthenticationSuccessEvent;
use Lexik\Bundle\JWTAuthenticationBundle\Event\JWTCreatedEvent;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Response;

class JWTListener
{
    public function onAuthenticationSuccess(AuthenticationSuccessEvent $event): void
    {
        $data = $event->getData();
        $response = $event->getResponse();

        if (!$response instanceof Response) {
            return;
        }

        $token = $data['token'] ?? null;

        if (!$token) {
            return;
        }

        // Créer le cookie avec le token
        $cookie = Cookie::create(
            $_ENV['JWT_COOKIE_NAME'] ?? 'bagage_token',
            $token,
            time() + (int)($_ENV['JWT_TTL'] ?? 86400),
            '/',
            $_ENV['JWT_COOKIE_DOMAIN'] ?? 'localhost',
            (bool)($_ENV['JWT_COOKIE_SECURE'] ?? false),
            (bool)($_ENV['JWT_COOKIE_HTTPONLY'] ?? true),
            false,
            $_ENV['JWT_COOKIE_SAMESITE'] ?? 'lax'
        );

        $response->headers->setCookie($cookie);

        // Retirer le token de la réponse JSON pour plus de sécurité
        unset($data['token']);

        // Ajouter les infos utilisateur
        $user = $event->getUser();
        $data['user'] = [
            'id' => $user->getId(),
            'email' => $user->getEmail(),
            'nom' => $user->getNom(),
            'prenom' => $user->getPrenom(),
            'roles' => $user->getRoles(),
        ];

        $event->setData($data);
    }

    public function onJWTCreated(JWTCreatedEvent $event): void
    {
        $payload = $event->getData();
        $user = $event->getUser();

        // Ajouter des données personnalisées au token
        $payload['id'] = $user->getId();
        $payload['nom'] = $user->getNom();
        $payload['prenom'] = $user->getPrenom();

        $event->setData($payload);
    }
}
