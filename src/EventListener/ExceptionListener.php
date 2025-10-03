<?php

declare(strict_types=1);

namespace App\EventListener;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Validator\Exception\ValidationFailedException;

class ExceptionListener
{
    public function __construct(
        private readonly string $environment
    ) {}

    public function onKernelException(ExceptionEvent $event): void
    {
        $exception = $event->getThrowable();
        $request = $event->getRequest();

        // Ne gérer que les requêtes API
        if (!str_starts_with($request->getPathInfo(), '/api')) {
            return;
        }

        $statusCode = Response::HTTP_INTERNAL_SERVER_ERROR;
        $message = 'Une erreur est survenue';
        $errors = null;

        // Déterminer le code et le message selon le type d'exception
        if ($exception instanceof HttpExceptionInterface) {
            $statusCode = $exception->getStatusCode();
            $message = $exception->getMessage();
        }

        if ($exception instanceof NotFoundHttpException) {
            $statusCode = Response::HTTP_NOT_FOUND;
            $message = 'Ressource non trouvée';
        }

        if ($exception instanceof AccessDeniedHttpException) {
            $statusCode = Response::HTTP_FORBIDDEN;
            $message = 'Accès refusé';
        }

        if ($exception instanceof AuthenticationException) {
            $statusCode = Response::HTTP_UNAUTHORIZED;
            $message = 'Authentification requise';
        }

        if ($exception instanceof ValidationFailedException) {
            $statusCode = Response::HTTP_BAD_REQUEST;
            $message = 'Erreur de validation';
            $errors = [];

            foreach ($exception->getViolations() as $violation) {
                $errors[$violation->getPropertyPath()][] = $violation->getMessage();
            }
        }

        $data = [
            'error' => true,
            'message' => $message,
            'statusCode' => $statusCode,
        ];

        if ($errors) {
            $data['errors'] = $errors;
        }

        // En dev, ajouter plus de détails
        if ($this->environment === 'dev') {
            $data['debug'] = [
                'exception' => get_class($exception),
                'file' => $exception->getFile(),
                'line' => $exception->getLine(),
                'trace' => $exception->getTraceAsString(),
            ];
        }

        $response = new JsonResponse($data, $statusCode);
        $event->setResponse($response);
    }
}
