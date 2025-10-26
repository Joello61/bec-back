<?php

namespace App\Service;

use App\Entity\RefreshToken;
use App\Entity\User;
use App\Repository\RefreshTokenRepository;
use Doctrine\ORM\EntityManagerInterface;
use Exception;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\PasswordHasher\Hasher\PasswordHasherFactoryInterface;
use Psr\Log\LoggerInterface;

class RefreshTokenManager
{
    // Durée de vie du refresh token en secondes (ex: 30 jours)
    private int $refreshTokenTtl;
    private $hasher;

    private const SELECTOR_LENGTH = 16; // Longueur en octets pour le sélecteur
    private const VALIDATOR_LENGTH = 32; // Longueur en octets pour le validateur (avant hachage)

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly RefreshTokenRepository $refreshTokenRepository,
        private readonly RequestStack $requestStack,
        private readonly PasswordHasherFactoryInterface $hasherFactory,
        private readonly LoggerInterface $logger,
        int $refreshTokenTtl = 2592000
    ) {
        $this->refreshTokenTtl = $refreshTokenTtl;
        $this->hasher = $this->hasherFactory->getPasswordHasher('common');
    }

    /**
     * Crée un nouveau refresh token, le sauvegarde (haché) et retourne le token brut.
     * Optionnellement, invalide les anciens tokens de l'utilisateur (rotation).
     *
     * @param User    $user        L'utilisateur pour qui créer le token.
     * @param bool    $invalidateOldTokens Activer la rotation (invalider les anciens).
     * @return string Le token brut (non haché) à mettre dans le cookie.
     * @throws Exception
     */
    public function createAndSaveRefreshToken(User $user, bool $invalidateOldTokens = true): string
    {
        if ($invalidateOldTokens) {
            $this->invalidateUserTokens($user);
        }

        $selector = bin2hex(random_bytes(self::SELECTOR_LENGTH));
        $rawValidator = bin2hex(random_bytes(self::VALIDATOR_LENGTH));

        $validatorHash = $this->hasher->hash($rawValidator);

        $refreshTokenEntity = new RefreshToken();
        $request = $this->requestStack->getCurrentRequest();

        $refreshTokenEntity->setUser($user)
            ->setSelector($selector)
            ->setValidatorHash($validatorHash)
            ->setExpiresAt(new \DateTimeImmutable("+{$this->refreshTokenTtl} seconds"))
            ->setClientIp($request?->getClientIp())
            ->setUserAgent($request?->headers->get('User-Agent'));

        $this->entityManager->persist($refreshTokenEntity);
        $this->entityManager->flush();

        return $selector . '.' . $rawValidator;
    }

    /**
     * Valide un token brut reçu (ex: depuis un cookie).
     *
     * @param string $fullRawToken
     * @return RefreshToken|null L'entité RefreshToken valide, ou null si invalide/expiré.
     */
    public function validateRefreshToken(string $fullRawToken): ?RefreshToken
    {
        $parts = explode('.', $fullRawToken, 2);
        if (count($parts) !== 2) {
            $this->logger->warning('Format de Refresh Token invalide reçu.');
            return null;
        }
        $selector = $parts[0];
        $rawValidator = $parts[1];

        $tokenEntity = $this->refreshTokenRepository->findOneNonExpiredBySelector($selector);

        if (!$tokenEntity) {
            $this->logger->info('Aucun Refresh Token non expiré trouvé pour le sélecteur fourni.');
            return null;
        }

        if ($this->hasher->verify($tokenEntity->getValidatorHash(), $rawValidator)) {
            return $tokenEntity;
        } else {
            $this->logger->warning('Refresh Token trouvé par sélecteur mais hash validateur invalide.', ['tokenId' => $tokenEntity->getId()]);
            return null;
        }
    }

    /**
     * Invalide (supprime) un token spécifique basé sur sa valeur brute.
     *
     * @param string $fullRawToken
     */
    public function invalidateToken(string $fullRawToken): void
    {
        // On valide d'abord pour trouver l'entité correspondante
        $tokenEntity = $this->validateRefreshToken($fullRawToken);
        if ($tokenEntity) {
            $this->entityManager->remove($tokenEntity);
            $this->entityManager->flush();
            $this->logger->info('Refresh Token invalidé.', ['tokenId' => $tokenEntity->getId()]);
        } else {
            $this->logger->warning('Tentative d\'invalidation d\'un Refresh Token déjà invalide ou inconnu.');
        }
    }

    /**
     * Invalide (supprime) tous les refresh tokens d'un utilisateur donné.
     * Utile pour la déconnexion ou la rotation.
     *
     * @param User $user
     */
    public function invalidateUserTokens(User $user): void
    {
        $tokens = $this->refreshTokenRepository->findBy(['user' => $user]);
        if (count($tokens) > 0) {
            foreach ($tokens as $token) {
                $this->entityManager->remove($token);
            }
            $this->entityManager->flush();
            $this->logger->info('Tous les Refresh Tokens invalidés pour user ' . $user->getId());
        }
    }

    /**
     * Supprime tous les tokens expirés de la base de données.
     * À appeler via une commande planifiée.
     *
     * @return int Le nombre de tokens supprimés.
     */
    public function deleteExpiredTokens(): int
    {
        $count = $this->refreshTokenRepository->deleteExpiredTokens();
        if ($count > 0) {
            $this->logger->info($count . ' Refresh Tokens expirés supprimés.');
        }
        return $count;
    }
}
