<?php

namespace App\Controller;

use App\Entity\User;
use App\Service\CookieManager;
use App\Service\MercureTokenService;
use App\Service\RefreshTokenManager;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use OpenApi\Attributes as OA;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/api/token')]
class TokenController extends AbstractController
{
    public function __construct(
        private readonly RefreshTokenManager $refreshTokenManager,
        private readonly JWTTokenManagerInterface $jwtManager,
        private readonly MercureTokenService $mercureTokenService,
        private readonly LoggerInterface $logger,
        private readonly CookieManager $cookieManager,
    ) {}

    #[Route('/refresh', name: 'api_token_refresh', methods: ['POST'])]
    #[OA\Post(
        path: '/api/token/refresh',
        description: 'Attend le cookie HttpOnly "bagage_refresh_token". Renvoie un nouveau "bagage_token" dans un cookie HttpOnly.',
        summary: 'Rafraîchit le token JWT principal en utilisant le refresh token',
        requestBody: null
    )]
    #[OA\Response(
        response: 204,
        description: 'Token rafraîchi avec succès. Nouveau cookie "bagage_token" attaché.'
    )]
    #[OA\Response(
        response: 401,
        description: 'Refresh token invalide, expiré ou manquant.'
    )]
    public function refresh(Request $request): Response
    {
        $rawRefreshToken = $request->cookies->get(
            $this->cookieManager->getRefreshTokenCookieName()
        );

        if (!$rawRefreshToken) {
            $this->logger->warning('Tentative de refresh sans cookie refresh token.');
            return new JsonResponse(['message' => 'Refresh token manquant'], Response::HTTP_UNAUTHORIZED);
        }

        $refreshTokenEntity = $this->refreshTokenManager->validateRefreshToken($rawRefreshToken);

        if (!$refreshTokenEntity) {
            $this->logger->warning('Tentative de refresh avec un token invalide ou expiré.');
            $response = new JsonResponse(['message' => 'Refresh token invalide ou expiré'], Response::HTTP_UNAUTHORIZED);

            return $this->cookieManager->clearCookie(
                $response,
                $this->cookieManager->getRefreshTokenCookieName()
            );
        }

        $user = $refreshTokenEntity->getUser();
        if (!$user) {
            $this->logger->error('Refresh token valide mais sans utilisateur associé ?!', ['tokenId' => $refreshTokenEntity->getId()]);
            $this->refreshTokenManager->invalidateToken($rawRefreshToken);
            return new JsonResponse(['message' => 'Erreur interne'], Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        try {
            $this->refreshTokenManager->invalidateToken($rawRefreshToken);
            $newRawRefreshToken = $this->refreshTokenManager->createAndSaveRefreshToken($user, false);
        } catch (\Exception $e) {
            $this->logger->error('Erreur lors de la rotation du refresh token', ['error' => $e->getMessage()]);
            return new JsonResponse(['message' => 'Erreur lors de la rotation du token'], Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        $newJwtToken = $this->jwtManager->create($user);

        $response = new Response(null, Response::HTTP_NO_CONTENT);

        $jwtCookie = $this->cookieManager->createJwtCookie($newJwtToken);
        $refreshCookie = $this->cookieManager->createRefreshTokenCookie($newRawRefreshToken);

        $this->cookieManager->attachCookies($response, [$jwtCookie, $refreshCookie]);

        $this->logger->info('Token JWT rafraîchi pour user ' . $user->getId());
        return $response;
    }

    /**
     * Rafraîchit le cookie d'autorisation Mercure pour l'utilisateur connecté.
     * Attend le cookie d'authentification principal (ex: bagage_token).
     */
    #[Route('/mercure/refresh', name: 'api_mercure_refresh', methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    #[OA\Post(
        path: '/api/mercure/refresh',
        description: 'Génère un nouveau token Mercure et l\'attache dans un cookie HttpOnly "mercureAuthorization".',
        summary: 'Rafraîchit le cookie JWT Mercure',
        security: [['cookieAuth' => []]]
    )]
    #[OA\Response(
        response: 204,
        description: 'Cookie Mercure rafraîchi avec succès.'
    )]
    #[OA\Response(
        response: 401,
        description: 'Utilisateur non authentifié.'
    )]
    #[OA\Response(
        response: 500,
        description: 'Erreur lors de la génération du token Mercure.'
    )]
    public function refreshMercureCookie(): Response
    {
        /** @var User|null $user */
        $user = $this->getUser();

        // Vérifier si l'utilisateur est bien authentifié (sécurité)
        if (!$user) {
            return new JsonResponse(['message' => 'Authentification requise'], Response::HTTP_UNAUTHORIZED);
        }

        try {
            $mercureToken = $this->mercureTokenService->generate($user);

            $response = new Response(null, Response::HTTP_NO_CONTENT);
            $mercureCookie = $this->cookieManager->createMercureCookie($mercureToken);
            $this->cookieManager->attachCookie($response, $mercureCookie);

            $this->logger->info('Cookie Mercure rafraîchi via API pour user '.$user->getId());
            return $response;

        } catch (\Exception $e) {
            $this->logger->error(
                'Impossible de rafraîchir le cookie Mercure via API',
                ['user_id' => $user->getId(), 'error' => $e->getMessage()]
            );
            // Retourner une erreur serveur
            return new JsonResponse(
                ['message' => 'Erreur lors du rafraîchissement du token Mercure'],
                Response::HTTP_INTERNAL_SERVER_ERROR
            );
        }
    }
}
