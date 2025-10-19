<?php

declare(strict_types=1);

namespace App\Service\OAuth;

use App\Entity\User;
use App\Repository\UserRepository;
use App\Service\SettingsService;
use Doctrine\ORM\EntityManagerInterface;
use League\OAuth2\Client\Provider\Google;
use League\OAuth2\Client\Provider\GoogleUser;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

readonly class GoogleAuthService
{
    private Google $googleProvider;

    public function __construct(
        private EntityManagerInterface $entityManager,
        private UserRepository $userRepository,
        private LoggerInterface $logger,
        private SettingsService $settingsService,
        string $googleClientId,
        string $googleClientSecret,
        string $googleRedirectUri
    ) {
        $this->googleProvider = new Google([
            'clientId' => $googleClientId,
            'clientSecret' => $googleClientSecret,
            'redirectUri' => $googleRedirectUri,
        ]);
    }

    /**
     * Génère l'URL d'autorisation Google
     */
    public function getAuthorizationUrl(): string
    {
        return $this->googleProvider->getAuthorizationUrl([
            'scope' => ['email', 'profile']
        ]);
    }

    /**
     * Récupère le state pour la vérification CSRF
     */
    public function getState(): string
    {
        return $this->googleProvider->getState();
    }

    /**
     * Authentifie un utilisateur via Google
     */
    public function authenticate(string $code): User
    {
        try {
            // Échanger le code contre un access token
            $accessToken = $this->googleProvider->getAccessToken('authorization_code', [
                'code' => $code
            ]);

            // Récupérer les informations de l'utilisateur
            /** @var GoogleUser $googleUser */
            $googleUser = $this->googleProvider->getResourceOwner($accessToken);

            $googleId = $googleUser->getId();
            $email = $googleUser->getEmail();
            $firstName = $googleUser->getFirstName();
            $lastName = $googleUser->getLastName();
            $avatar = $googleUser->getAvatar();

            // Vérifier si l'utilisateur existe déjà avec ce Google ID
            $user = $this->userRepository->findOneBy(['googleId' => $googleId]);

            if ($user) {
                // Utilisateur existant, mettre à jour les infos si nécessaire
                $this->updateUserFromGoogle($user, $email, $firstName, $lastName, $avatar);
                $this->logger->info('Connexion Google réussie', [
                    'user_id' => $user->getId(),
                    'google_id' => $googleId
                ]);
                return $user;
            }

            // Vérifier si un utilisateur existe avec cet email
            $user = $this->userRepository->findByEmail($email);

            if ($user) {
                // Lier le compte Google à l'utilisateur existant
                $user->setGoogleId($googleId);
                $user->setAuthProvider('google');
                $user->setEmailVerifie(true); // Email vérifié par Google

                if (!$user->getPhoto() && $avatar) {
                    $user->setPhoto($avatar);
                }

                $this->entityManager->flush();

                $this->logger->info('Compte Google lié à un utilisateur existant', [
                    'user_id' => $user->getId(),
                    'google_id' => $googleId
                ]);

                return $user;
            }

            // Créer un nouvel utilisateur
            $user = new User();
            $user->setEmail($email)
                ->setNom($lastName ?? 'Nom')
                ->setPrenom($firstName ?? 'Prénom')
                ->setGoogleId($googleId)
                ->setAuthProvider('google')
                ->setEmailVerifie(true) // Email vérifié par Google
                ->setRoles(['ROLE_USER']);

            if ($avatar) {
                $user->setPhoto($avatar);
            }

            // Pas de mot de passe pour les comptes OAuth
            $user->setPassword(null);

            $this->entityManager->persist($user);
            $this->entityManager->flush();

            $this->settingsService->createDefaultSettings($user);

            $this->logger->info('Nouvel utilisateur créé via Google', [
                'user_id' => $user->getId(),
                'google_id' => $googleId,
                'email' => $email
            ]);

            return $user;

        } catch (\Exception $e) {
            $this->logger->error('Erreur lors de l\'authentification Google', [
                'error' => $e->getMessage()
            ]);
            throw new BadRequestHttpException('Erreur lors de l\'authentification Google');
        }
    }

    /**
     * Met à jour les informations de l'utilisateur depuis Google
     */
    private function updateUserFromGoogle(
        User $user,
        string $email,
        ?string $firstName,
        ?string $lastName,
        ?string $avatar
    ): void {
        $updated = false;

        if ($user->getEmail() !== $email) {
            $user->setEmail($email);
            $updated = true;
        }

        if ($firstName && $user->getPrenom() !== $firstName) {
            $user->setPrenom($firstName);
            $updated = true;
        }

        if ($lastName && $user->getNom() !== $lastName) {
            $user->setNom($lastName);
            $updated = true;
        }

        if ($avatar && !$user->getPhoto()) {
            $user->setPhoto($avatar);
            $updated = true;
        }

        if (!$user->isEmailVerifie()) {
            $user->setEmailVerifie(true);
            $updated = true;
        }

        if ($updated) {
            $this->entityManager->flush();
        }
    }
}
