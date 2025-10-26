<?php

declare(strict_types=1);

namespace App\EventListener;

use App\Entity\User;
use App\Service\CookieManager;
use App\Service\MercureTokenService;
use App\Service\RefreshTokenManager;
use Lexik\Bundle\JWTAuthenticationBundle\Event\AuthenticationSuccessEvent;
use Lexik\Bundle\JWTAuthenticationBundle\Event\JWTCreatedEvent;
use Lexik\Bundle\JWTAuthenticationBundle\Event\AuthenticationFailureEvent;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAuthenticationException;

readonly class JWTListener
{
    public function __construct(
        private MercureTokenService $mercureTokenService,
        private RefreshTokenManager $refreshTokenManager,
        private CookieManager $cookieManager,
        private LoggerInterface $logger,
    ) {}

    /**
     * Appelé après une authentification réussie
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

        try {
            // Générer les tokens
            $mercureToken = $this->mercureTokenService->generate($user);
            $refreshToken = $this->refreshTokenManager->createAndSaveRefreshToken($user);

            // Créer les cookies avec le CookieManager
            $mercureCookie = $this->cookieManager->createMercureCookie($mercureToken);
            $refreshCookie = $this->cookieManager->createRefreshTokenCookie($refreshToken);

            // Attacher les cookies à la réponse
            $response = $event->getResponse();
            if ($response) {
                $this->cookieManager->attachCookies($response, [
                    $mercureCookie,
                    $refreshCookie
                ]);
            }

        } catch (\Exception $e) {
            $this->logger->error('Erreur lors de la création des cookies d\'authentification', [
                'user_id' => $user->getId(),
                'error' => $e->getMessage()
            ]);
        }

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
        unset($data['token']);

        $event->setData($data);
    }
    /**
     * Appelé lors de la création du token JWT principal
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
     */
    public function onAuthenticationFailure(AuthenticationFailureEvent $event): void
    {
        $exception = $event->getException();

        $response = new JsonResponse([
            'success' => false,
            'message' => $exception->getMessage(),
            'type' => get_class($exception),
            'trace' => $exception->getTraceAsString(),
        ], Response::HTTP_UNAUTHORIZED);

        $event->setResponse($response);
    }
}
