<?php

declare(strict_types=1);

namespace App\Controller;

use App\DTO\ChangePasswordDTO;
use App\DTO\ForgotPasswordDTO;
use App\DTO\LoginDTO;
use App\DTO\RegisterDTO;
use App\DTO\ResendVerificationDTO;
use App\DTO\ResetPasswordDTO;
use App\DTO\VerifyEmailDTO;
use App\DTO\VerifyPhoneDTO;
use App\Entity\User;
use App\Service\AuthService;
use App\Service\OAuth\FacebookAuthService;
use App\Service\OAuth\GoogleAuthService;
use Exception;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\HttpFoundation\RedirectResponse;

#[Route('/api', name: 'api_auth_')]
#[OA\Tag(name: 'Authentification')]
class AuthController extends AbstractController
{
    public function __construct(
        private readonly AuthService $authService,
        private readonly GoogleAuthService $googleAuthService,
        private readonly FacebookAuthService $facebookAuthService,
        private readonly JWTTokenManagerInterface $jwtManager,
    ) {}

    /**
     * Le login est géré automatiquement par Lexik JWT via security.yaml
     * avec vérification de l'email dans JWTListener
     */
    #[Route('/login', name: 'login', methods: ['POST'])]
    #[OA\Post(
        path: '/api/login',
        summary: 'Connexion d\'un utilisateur',
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(ref: new Model(type: LoginDTO::class))
        )
    )]
    #[OA\Response(
        response: 200,
        description: 'Utilisateur connecté avec succès',
        content: new OA\JsonContent(
            properties: [
                new OA\Property(property: 'success', type: 'boolean', example: true),
                new OA\Property(property: 'message', type: 'string', example: 'Connexion réussie'),
                new OA\Property(
                    property: 'user',
                    properties: [
                        new OA\Property(property: 'id', type: 'integer'),
                        new OA\Property(property: 'email', type: 'string'),
                        new OA\Property(property: 'nom', type: 'string'),
                        new OA\Property(property: 'prenom', type: 'string'),
                        new OA\Property(property: 'roles', type: 'array', items: new OA\Items(type: 'string')),
                        new OA\Property(property: 'emailVerifie', type: 'boolean'),
                    ],
                    type: 'object'
                )
            ]
        )
    )]
    #[OA\Response(response: 401, description: 'Email ou mot de passe incorrect, ou email non vérifié')]
    public function login(): void
    {
        // Cette méthode ne sera jamais appelée car gérée par Lexik JWT
        // Elle existe uniquement pour la documentation OpenAPI
    }

    #[Route('/register', name: 'register', methods: ['POST'])]
    #[OA\Post(
        path: '/api/register',
        summary: 'Inscription d\'un nouvel utilisateur',
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(ref: new Model(type: RegisterDTO::class))
        )
    )]
    #[OA\Response(
        response: 201,
        description: 'Utilisateur créé avec succès',
        content: new OA\JsonContent(
            properties: [
                new OA\Property(property: 'success', type: 'boolean', example: true),
                new OA\Property(property: 'message', type: 'string'),
                new OA\Property(
                    property: 'user',
                    properties: [
                        new OA\Property(property: 'id', type: 'integer'),
                        new OA\Property(property: 'email', type: 'string'),
                        new OA\Property(property: 'nom', type: 'string'),
                        new OA\Property(property: 'prenom', type: 'string')
                    ],
                    type: 'object'
                )
            ]
        )
    )]
    #[OA\Response(response: 400, description: 'Données invalides')]
    public function register(
        #[MapRequestPayload] RegisterDTO $dto,
        RateLimiterFactory $registerLimiter,
        Request $request
    ): JsonResponse {
        // Rate limiting sur l'inscription
        $limiter = $registerLimiter->create($request->getClientIp());
        if (false === $limiter->consume(1)->isAccepted()) {
            return $this->json([
                'success' => false,
                'message' => 'Trop de tentatives d\'inscription. Veuillez réessayer plus tard.'
            ], Response::HTTP_TOO_MANY_REQUESTS);
        }

        $user = $this->authService->register($dto);

        return $this->json([
            'success' => true,
            'message' => 'Inscription réussie. Un code de vérification a été envoyé à votre email.',
            'user' => [
                'id' => $user->getId(),
                'email' => $user->getEmail(),
                'nom' => $user->getNom(),
                'prenom' => $user->getPrenom(),
            ]
        ], Response::HTTP_CREATED);
    }

    #[Route('/logout', name: 'logout', methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    #[OA\Post(
        path: '/api/logout',
        summary: 'Déconnexion',
        security: [['cookieAuth' => []]]
    )]
    #[OA\Response(
        response: 200,
        description: 'Déconnexion réussie'
    )]
    public function logout(): JsonResponse
    {
        $response = $this->json([
            'success' => true,
            'message' => 'Déconnexion réussie'
        ]);

        // Supprimer le cookie JWT
        $cookieName = $_ENV['JWT_COOKIE_NAME'] ?? 'bagage_token';

        $cookie = Cookie::create($cookieName)
            ->withValue('')
            ->withExpires(time() - 3600)
            ->withPath('/')
            ->withDomain($_ENV['JWT_COOKIE_DOMAIN'] ?? null)
            ->withSecure((bool)($_ENV['JWT_COOKIE_SECURE'] ?? false))
            ->withHttpOnly(true)
            ->withSameSite($_ENV['JWT_COOKIE_SAMESITE'] ?? 'lax');

        $response->headers->setCookie($cookie);

        return $response;
    }

    #[Route('/me', name: 'me', methods: ['GET'])]
    #[IsGranted('ROLE_USER')]
    #[OA\Get(
        path: '/api/me',
        summary: 'Informations de l\'utilisateur connecté',
        security: [['cookieAuth' => []]]
    )]
    #[OA\Response(response: 200, description: 'Informations utilisateur')]
    public function me(): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();

        return $this->json([
            'id' => $user->getId(),
            'email' => $user->getEmail(),
            'nom' => $user->getNom(),
            'prenom' => $user->getPrenom(),
            'telephone' => $user->getTelephone(),
            'photo' => $user->getPhoto(),
            'bio' => $user->getBio(),
            'emailVerifie' => $user->isEmailVerifie(),
            'telephoneVerifie' => $user->isTelephoneVerifie(),
            'authProvider' => $user->getAuthProvider(),
            'roles' => $user->getRoles(),
            'createdAt' => $user->getCreatedAt()?->format('c'),
        ]);
    }

    /**
     * @throws Exception
     */
    #[Route('/verify-email', name: 'verify_email', methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    #[OA\Post(
        path: '/api/verify-email',
        summary: 'Vérifier l\'email avec un code',
        security: [['cookieAuth' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(ref: new Model(type: VerifyEmailDTO::class))
        )
    )]
    #[OA\Response(response: 200, description: 'Email vérifié avec succès')]
    #[OA\Response(response: 400, description: 'Code invalide ou expiré')]
    public function verifyEmail(
        #[MapRequestPayload] VerifyEmailDTO $dto
    ): JsonResponse {
        /** @var User $user */
        $user = $this->getUser();

        $this->authService->verifyEmail($user, $dto->code);

        return $this->json([
            'success' => true,
            'message' => 'Email vérifié avec succès'
        ]);
    }

    /**
     * @throws Exception
     */
    #[Route('/verify-phone', name: 'verify_phone', methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    #[OA\Post(
        path: '/api/verify-phone',
        summary: 'Vérifier le téléphone avec un code SMS',
        security: [['cookieAuth' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(ref: new Model(type: VerifyPhoneDTO::class))
        )
    )]
    #[OA\Response(response: 200, description: 'Téléphone vérifié avec succès')]
    public function verifyPhone(
        #[MapRequestPayload] VerifyPhoneDTO $dto
    ): JsonResponse {
        /** @var User $user */
        $user = $this->getUser();

        $this->authService->verifyPhone($user, $dto->code);

        return $this->json([
            'success' => true,
            'message' => 'Téléphone vérifié avec succès'
        ]);
    }

    /**
     * @throws Exception
     */
    #[Route('/resend-verification', name: 'resend_verification', methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    #[OA\Post(
        path: '/api/resend-verification',
        summary: 'Renvoyer un code de vérification',
        security: [['cookieAuth' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(ref: new Model(type: ResendVerificationDTO::class))
        )
    )]
    #[OA\Response(response: 200, description: 'Code renvoyé avec succès')]
    public function resendVerification(
        #[MapRequestPayload] ResendVerificationDTO $dto,
        RateLimiterFactory $verificationLimiter,
        Request $request
    ): JsonResponse {
        /** @var User $user */
        $user = $this->getUser();

        // Rate limiting
        $limiter = $verificationLimiter->create($user->getId());
        if (false === $limiter->consume(1)->isAccepted()) {
            return $this->json([
                'success' => false,
                'message' => 'Trop de demandes. Veuillez réessayer dans quelques minutes.'
            ], Response::HTTP_TOO_MANY_REQUESTS);
        }

        if ($dto->type === 'email') {
            $this->authService->resendEmailVerification($user);
            $message = 'Un nouveau code a été envoyé à votre email';
        } else {
            $this->authService->resendPhoneVerification($user);
            $message = 'Un nouveau code a été envoyé par SMS';
        }

        return $this->json([
            'success' => true,
            'message' => $message
        ]);
    }

    #[Route('/forgot-password', name: 'forgot_password', methods: ['POST'])]
    #[OA\Post(
        path: '/api/forgot-password',
        summary: 'Demander la réinitialisation du mot de passe',
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(ref: new Model(type: ForgotPasswordDTO::class))
        )
    )]
    #[OA\Response(response: 200, description: 'Email de réinitialisation envoyé')]
    public function forgotPassword(
        #[MapRequestPayload] ForgotPasswordDTO $dto,
        RateLimiterFactory $passwordResetLimiter,
        Request $request
    ): JsonResponse {
        // Rate limiting par IP
        $limiter = $passwordResetLimiter->create($request->getClientIp());
        if (false === $limiter->consume(1)->isAccepted()) {
            return $this->json([
                'success' => false,
                'message' => 'Trop de demandes. Veuillez réessayer plus tard.'
            ], Response::HTTP_TOO_MANY_REQUESTS);
        }

        try {
            $this->authService->requestPasswordReset($dto->email);
        } catch (Exception $e) {
            // Ne pas révéler si l'email existe ou non
        }

        // Toujours retourner un succès pour des raisons de sécurité
        return $this->json([
            'success' => true,
            'message' => 'Si cet email existe, un lien de réinitialisation a été envoyé.'
        ]);
    }

    #[Route('/reset-password', name: 'reset_password', methods: ['POST'])]
    #[OA\Post(
        path: '/api/reset-password',
        summary: 'Réinitialiser le mot de passe avec un token',
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(ref: new Model(type: ResetPasswordDTO::class))
        )
    )]
    #[OA\Response(response: 200, description: 'Mot de passe réinitialisé')]
    #[OA\Response(response: 400, description: 'Token invalide ou expiré')]
    public function resetPassword(
        #[MapRequestPayload] ResetPasswordDTO $dto
    ): JsonResponse {
        $this->authService->resetPassword($dto->token, $dto->newPassword);

        return $this->json([
            'success' => true,
            'message' => 'Mot de passe réinitialisé avec succès'
        ]);
    }

    #[Route('/change-password', name: 'change_password', methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    #[OA\Post(
        path: '/api/change-password',
        summary: 'Changer son mot de passe',
        security: [['cookieAuth' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(ref: new Model(type: ChangePasswordDTO::class))
        )
    )]
    #[OA\Response(response: 200, description: 'Mot de passe modifié')]
    #[OA\Response(response: 400, description: 'Mot de passe actuel incorrect')]
    public function changePassword(
        #[MapRequestPayload] ChangePasswordDTO $dto
    ): JsonResponse {
        /** @var User $user */
        $user = $this->getUser();

        $this->authService->changePassword(
            $user,
            $dto->currentPassword,
            $dto->newPassword
        );

        return $this->json([
            'success' => true,
            'message' => 'Mot de passe modifié avec succès'
        ]);
    }

    #[Route('/auth/google', name: 'google_auth', methods: ['GET'])]
    #[OA\Get(
        path: '/api/auth/google',
        summary: 'Obtenir l\'URL d\'autorisation Google',
    )]
    #[OA\Response(
        response: 200,
        description: 'URL d\'autorisation',
        content: new OA\JsonContent(
            properties: [
                new OA\Property(property: 'authUrl', type: 'string'),
                new OA\Property(property: 'state', type: 'string')
            ]
        )
    )]
    public function googleAuth(Request $request): JsonResponse
    {
        $authUrl = $this->googleAuthService->getAuthorizationUrl();
        $state = $this->googleAuthService->getState();

        // Stocker le state en session pour vérification CSRF
        $request->getSession()->set('oauth2_state', $state);

        return $this->json([
            'authUrl' => $authUrl,
            'state' => $state
        ]);
    }

    #[Route('/auth/google/callback', name: 'google_callback', methods: ['GET'])]
    #[OA\Get(
        path: '/api/auth/google/callback',
        summary: 'Callback Google OAuth',
    )]
    #[OA\Response(response: 302, description: 'Redirection vers le frontend')]
    public function googleCallback(Request $request): Response
    {
        $code = $request->query->get('code');
        $state = $request->query->get('state');
        $storedState = $request->getSession()->get('oauth2_state');

        if (!$state || $state !== $storedState) {
            return new RedirectResponse(
                $_ENV['FRONTEND_URL'] . '/auth/login?error=csrf_failed'
            );
        }

        if (!$code) {
            return new RedirectResponse(
                $_ENV['FRONTEND_URL'] . '/auth/login?error=no_code'
            );
        }

        try {
            $user = $this->googleAuthService->authenticate($code);
            $token = $this->jwtManager->create($user);

            $cookieName = $_ENV['JWT_COOKIE_NAME'] ?? 'bagage_token';
            $cookieTtl = (int)($_ENV['JWT_TTL'] ?? 86400);

            $cookie = Cookie::create($cookieName)
                ->withValue($token)
                ->withExpires(time() + $cookieTtl)
                ->withPath('/')
                ->withDomain($_ENV['JWT_COOKIE_DOMAIN'] ?? null)
                ->withSecure((bool)($_ENV['JWT_COOKIE_SECURE'] ?? false))
                ->withHttpOnly((bool)($_ENV['JWT_COOKIE_HTTPONLY'] ?? true))
                ->withSameSite($_ENV['JWT_COOKIE_SAMESITE'] ?? 'lax');

            $response = new RedirectResponse($_ENV['FRONTEND_URL'] . '/auth/oauth-callback');
            $response->headers->setCookie($cookie);

            return $response;

        } catch (\Exception $e) {
            return new RedirectResponse(
                $_ENV['FRONTEND_URL'] . '/auth/login?error=' . urlencode($e->getMessage())
            );
        }
    }

    #[Route('/auth/facebook', name: 'facebook_auth', methods: ['GET'])]
    #[OA\Get(
        path: '/api/auth/facebook',
        summary: 'Obtenir l\'URL d\'autorisation Facebook',
    )]
    #[OA\Response(
        response: 200,
        description: 'URL d\'autorisation',
        content: new OA\JsonContent(
            properties: [
                new OA\Property(property: 'authUrl', type: 'string'),
                new OA\Property(property: 'state', type: 'string')
            ]
        )
    )]
    public function facebookAuth(Request $request): JsonResponse
    {
        $authUrl = $this->facebookAuthService->getAuthorizationUrl();
        $state = $this->facebookAuthService->getState();

        // Stocker le state en session pour vérification CSRF
        $request->getSession()->set('oauth2_state_fb', $state);

        return $this->json([
            'authUrl' => $authUrl,
            'state' => $state
        ]);
    }

    #[Route('/auth/facebook/callback', name: 'facebook_callback', methods: ['GET'])]
    #[OA\Get(
        path: '/api/auth/facebook/callback',
        summary: 'Callback Facebook OAuth',
    )]
    #[OA\Response(response: 302, description: 'Redirection vers le frontend')]
    public function facebookCallback(Request $request): Response
    {
        $code = $request->query->get('code');
        $state = $request->query->get('state');
        $storedState = $request->getSession()->get('oauth2_state_fb');

        if (!$state || $state !== $storedState) {
            return new RedirectResponse(
                $_ENV['FRONTEND_URL'] . '/auth/login?error=csrf_failed'
            );
        }

        if (!$code) {
            return new RedirectResponse(
                $_ENV['FRONTEND_URL'] . '/auth/login?error=no_code'
            );
        }

        try {
            $user = $this->facebookAuthService->authenticate($code);
            $token = $this->jwtManager->create($user);

            $cookieName = $_ENV['JWT_COOKIE_NAME'] ?? 'bagage_token';
            $cookieTtl = (int)($_ENV['JWT_TTL'] ?? 86400);

            $cookie = Cookie::create($cookieName)
                ->withValue($token)
                ->withExpires(time() + $cookieTtl)
                ->withPath('/')
                ->withDomain($_ENV['JWT_COOKIE_DOMAIN'] ?? null)
                ->withSecure((bool)($_ENV['JWT_COOKIE_SECURE'] ?? false))
                ->withHttpOnly((bool)($_ENV['JWT_COOKIE_HTTPONLY'] ?? true))
                ->withSameSite($_ENV['JWT_COOKIE_SAMESITE'] ?? 'lax');

            $response = new RedirectResponse($_ENV['FRONTEND_URL'] . '/auth/oauth-callback');
            $response->headers->setCookie($cookie);

            return $response;

        } catch (\Exception $e) {
            return new RedirectResponse(
                $_ENV['FRONTEND_URL'] . '/auth/login?error=' . urlencode($e->getMessage())
            );
        }
    }
}
