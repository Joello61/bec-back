<?php

declare(strict_types=1);

namespace App\EventListener;

use App\Entity\User;
use Lexik\Bundle\JWTAuthenticationBundle\Event\AuthenticationSuccessEvent;
use Lexik\Bundle\JWTAuthenticationBundle\Event\JWTCreatedEvent;
use Lexik\Bundle\JWTAuthenticationBundle\Event\AuthenticationFailureEvent;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAuthenticationException;

class JWTListener
{
    /**
     * Appelé après une tentative d'authentification réussie
     * Vérifie que l'email est vérifié avant d'autoriser la connexion
     */
    public function onAuthenticationSuccess(AuthenticationSuccessEvent $event): void
    {
        $data = $event->getData();
        /** @var User $user */
        $user = $event->getUser();

        // Vérifier que l'email est vérifié pour les comptes locaux
        if ($user->getAuthProvider() === 'local' && !$user->isEmailVerifie()) {
            // Empêcher la connexion si l'email n'est pas vérifié
            throw new CustomUserMessageAuthenticationException(
                'Veuillez vérifier votre adresse email avant de vous connecter. Un code de vérification vous a été envoyé.'
            );
        }

        // Enrichir la réponse avec les données utilisateur
        $data['success'] = true;
        $data['message'] = 'Connexion réussie';
        $data['user'] = [
            'id' => $user->getId(),
            'email' => $user->getEmail(),
            'nom' => $user->getNom(),
            'prenom' => $user->getPrenom(),
            'roles' => $user->getRoles(),
            'emailVerifie' => $user->isEmailVerifie(),
            'telephoneVerifie' => $user->isTelephoneVerifie(),
            'photo' => $user->getPhoto(),
            'authProvider' => $user->getAuthProvider(),
        ];

        // Retirer le token de la réponse JSON pour plus de sécurité
        // (il sera dans le cookie HttpOnly)
        unset($data['token']);

        $event->setData($data);
    }

    /**
     * Appelé lors de la création du token JWT
     * Permet d'ajouter des claims personnalisés
     */
    public function onJWTCreated(JWTCreatedEvent $event): void
    {
        $payload = $event->getData();
        /** @var User $user */
        $user = $event->getUser();

        // Ajouter des données personnalisées au payload du JWT
        $payload['id'] = $user->getId();
        $payload['nom'] = $user->getNom();
        $payload['prenom'] = $user->getPrenom();
        $payload['emailVerifie'] = $user->isEmailVerifie();
        $payload['authProvider'] = $user->getAuthProvider();

        $event->setData($payload);
    }

    /**
     * Appelé en cas d'échec de l'authentification
     * Personnalise le message d'erreur
     */
    public function onAuthenticationFailure(AuthenticationFailureEvent $event): void
    {
        $exception = $event->getException();

        // Personnaliser le message selon le type d'erreur
        $message = match (true) {
            str_contains($exception->getMessage(), 'email') => $exception->getMessage(),
            default => 'Email ou mot de passe incorrect',
        };

        $response = new JsonResponse([
            'success' => false,
            'message' => $message,
        ], Response::HTTP_UNAUTHORIZED);

        $event->setResponse($response);
    }
}
