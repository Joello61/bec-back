<?php

declare(strict_types=1);

namespace App\Controller;

use App\DTO\LoginDTO;
use App\DTO\RegisterDTO;
use App\Entity\User;
use App\Service\AuthService;
use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/api', name: 'api_auth_')]
#[OA\Tag(name: 'Authentification')]
class AuthController extends AbstractController
{
    public function __construct(
        private readonly AuthService $authService,
    ) {}

    /**
     * Le login est géré automatiquement par Lexik JWT via security.yaml
     * Cette route ne fait que documenter l'endpoint dans l'API doc
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
                    type: 'object',
                    properties: [
                        new OA\Property(property: 'id', type: 'integer'),
                        new OA\Property(property: 'email', type: 'string'),
                        new OA\Property(property: 'nom', type: 'string'),
                        new OA\Property(property: 'prenom', type: 'string'),
                        new OA\Property(property: 'roles', type: 'array', items: new OA\Items(type: 'string'))
                    ]
                )
            ]
        )
    )]
    #[OA\Response(response: 401, description: 'Email ou mot de passe incorrect')]
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
                new OA\Property(property: 'message', type: 'string', example: 'Inscription réussie'),
                new OA\Property(
                    property: 'user',
                    type: 'object',
                    properties: [
                        new OA\Property(property: 'id', type: 'integer'),
                        new OA\Property(property: 'email', type: 'string'),
                        new OA\Property(property: 'nom', type: 'string'),
                        new OA\Property(property: 'prenom', type: 'string')
                    ]
                )
            ]
        )
    )]
    #[OA\Response(response: 400, description: 'Données invalides')]
    public function register(
        #[MapRequestPayload] RegisterDTO $dto
    ): JsonResponse {
        $user = $this->authService->register($dto);

        return $this->json([
            'success' => true,
            'message' => 'Inscription réussie. Veuillez vérifier votre email pour activer votre compte.',
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
        description: 'Déconnexion réussie',
        content: new OA\JsonContent(
            properties: [
                new OA\Property(property: 'success', type: 'boolean', example: true),
                new OA\Property(property: 'message', type: 'string', example: 'Déconnexion réussie')
            ]
        )
    )]
    public function logout(): JsonResponse
    {
        $response = $this->json([
            'success' => true,
            'message' => 'Déconnexion réussie'
        ]);

        // Supprimer le cookie JWT en utilisant le nom configuré
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
    #[OA\Response(
        response: 200,
        description: 'Informations utilisateur',
        content: new OA\JsonContent(
            properties: [
                new OA\Property(property: 'id', type: 'integer'),
                new OA\Property(property: 'email', type: 'string'),
                new OA\Property(property: 'nom', type: 'string'),
                new OA\Property(property: 'prenom', type: 'string'),
                new OA\Property(property: 'telephone', type: 'string', nullable: true),
                new OA\Property(property: 'photo', type: 'string', nullable: true),
                new OA\Property(property: 'bio', type: 'string', nullable: true),
                new OA\Property(property: 'emailVerifie', type: 'boolean'),
                new OA\Property(property: 'telephoneVerifie', type: 'boolean'),
                new OA\Property(property: 'roles', type: 'array', items: new OA\Items(type: 'string'))
            ]
        )
    )]
    #[OA\Response(response: 401, description: 'Non authentifié')]
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
            'roles' => $user->getRoles(),
            'createdAt' => $user->getCreatedAt()?->format('c'),
        ]);
    }
}
