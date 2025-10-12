<?php

declare(strict_types=1);

namespace App\EventListener;

use App\Entity\User;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

/**
 * Listener qui bloque automatiquement les utilisateurs bannis
 * sauf sur les routes de logout
 */
#[AsEventListener(event: KernelEvents::REQUEST, priority: 10)]
class BannedUserListener
{
    public function __construct(
        private readonly TokenStorageInterface $tokenStorage,
    ) {}

    public function __invoke(RequestEvent $event): void
    {
        // Ne traiter que les requêtes principales
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        $token = $this->tokenStorage->getToken();

        // Pas de token = pas d'utilisateur connecté
        if (!$token) {
            return;
        }

        $user = $token->getUser();

        // Pas un objet User
        if (!$user instanceof User) {
            return;
        }

        // L'utilisateur n'est pas banni
        if (!$user->isBanned()) {
            return;
        }

        // Autoriser l'accès à la route de logout
        $path = $request->getPathInfo();
        if (str_contains($path, '/logout') || str_contains($path, '/api/logout')) {
            return;
        }

        // Autoriser l'accès aux routes publiques
        $publicRoutes = [
            '/api/login',
            '/api/register',
            '/api/forgot-password',
            '/api/reset-password',
        ];

        foreach ($publicRoutes as $publicRoute) {
            if (str_starts_with($path, $publicRoute)) {
                return;
            }
        }

        // Bloquer l'accès avec un message personnalisé
        $bannedAt = $user->getBannedAt();
        $banReason = $user->getBanReason();

        $message = 'Votre compte a été suspendu.';

        if ($bannedAt) {
            $message .= sprintf(' Date : %s.', $bannedAt->format('d/m/Y à H:i'));
        }

        if ($banReason) {
            $message .= sprintf(' Raison : %s', $banReason);
        }

        $message .= ' Veuillez contacter le support si vous pensez qu\'il s\'agit d\'une erreur.';

        $response = new JsonResponse([
            'error' => 'account_banned',
            'message' => $message,
            'bannedAt' => $bannedAt?->format('c'),
            'reason' => $banReason,
        ], Response::HTTP_FORBIDDEN);

        $event->setResponse($response);
    }
}
