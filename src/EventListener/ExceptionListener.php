<?php

declare(strict_types=1);

namespace App\EventListener;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Psr\Log\LoggerInterface;

readonly class ExceptionListener
{
    public function __construct(
        private LoggerInterface $logger,
        private string $environment
    ) {}

    public function onKernelException(ExceptionEvent $event): void
    {
        $exception = $event->getThrowable();
        $request = $event->getRequest();

        // Ne gérer que les routes API
        if (!str_starts_with($request->getPathInfo(), '/api')) {
            return;
        }

        $statusCode = JsonResponse::HTTP_INTERNAL_SERVER_ERROR;
        $message = 'Une erreur est survenue';
        $errors = [];

        // Gestion des exceptions HTTP
        if ($exception instanceof HttpExceptionInterface) {
            $statusCode = $exception->getStatusCode();
            $message = $exception->getMessage();
        }

        // Gestion des erreurs d'authentification
        if ($exception instanceof AuthenticationException) {
            $statusCode = Response::HTTP_UNAUTHORIZED;
            $message = 'Authentification requise';
        }

        // Gestion des erreurs d'autorisation
        if ($exception instanceof AccessDeniedException) {
            $statusCode = Response::HTTP_FORBIDDEN;
            $message = 'Accès refusé';
        }

        // En développement OU si APP_DEBUG=1, ajouter plus de détails
        if ($this->environment === 'dev' || $_ENV['APP_DEBUG'] ?? false) {
            $message = $exception->getMessage();  // ← AJOUTER : Message réel !
            $errors = [
                'exception' => get_class($exception),
                'message' => $exception->getMessage(),
                'file' => $exception->getFile(),
                'line' => $exception->getLine(),
                'trace' => explode("\n", $exception->getTraceAsString()),
            ];
        }

        // ==================== ACCESS DENIED (Voters) ====================
        if ($exception instanceof AccessDeniedException || $exception instanceof AccessDeniedHttpException) {
            $user = $request->attributes->get('_security_user');

            // Vérifier si c'est un problème de profil incomplet
            if ($user && method_exists($user, 'isProfileComplete') && !$user->isProfileComplete()) {
                $response = new JsonResponse([
                    'success' => false,
                    'error' => 'PROFILE_INCOMPLETE',
                    'message' => 'Vous devez compléter votre profil pour effectuer cette action',
                    'profileComplete' => false,
                    'details' => $this->getProfileMissingFields($user)
                ], Response::HTTP_FORBIDDEN);

                $event->setResponse($response);
                return;
            }

            // Autres cas d'access denied
            $response = new JsonResponse([
                'success' => false,
                'error' => 'ACCESS_DENIED',
                'message' => 'Vous n\'avez pas les permissions nécessaires pour effectuer cette action'
            ], Response::HTTP_FORBIDDEN);

            $event->setResponse($response);
            return;
        }

        // Logger l'erreur
        $this->logger->error('API Exception', [
            'exception' => get_class($exception),
            'message' => $exception->getMessage(),
            'code' => $statusCode,
            'path' => $request->getPathInfo(),
            'method' => $request->getMethod(),
        ]);

        // Créer la réponse JSON
        $response = new JsonResponse([
            'success' => false,
            'message' => $message,
            'errors' => $errors,
        ], $statusCode);

        $event->setResponse($response);
    }

        /**
         * Retourne les champs manquants du profil
         */
        private function getProfileMissingFields($user): array
    {
        $missing = [];

        if (!$user->isEmailVerifie()) {
            $missing[] = 'email_verification';
        }

        if (!$user->getTelephone()) {
            $missing[] = 'telephone';
        }

        if (!$user->isTelephoneVerifie() && $user->getTelephone()) {
            $missing[] = 'telephone_verification';
        }

        if (!$user->getPays() || !$user->getVille()) {
            $missing[] = 'location';
        }

        $africanFormat = $user->getQuartier() !== null;
        $diasporaFormat = $user->getAdresseLigne1() !== null && $user->getCodePostal() !== null;

        if (!$africanFormat && !$diasporaFormat) {
            $missing[] = 'address';
        }

        return $missing;
    }
}
