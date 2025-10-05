<?php

declare(strict_types=1);

namespace App\Service\OAuth;

use App\Entity\User;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use League\OAuth2\Client\Provider\Facebook;
use League\OAuth2\Client\Provider\FacebookUser;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

readonly class FacebookAuthService
{
    private Facebook $facebookProvider;

    public function __construct(
        private EntityManagerInterface $entityManager,
        private UserRepository $userRepository,
        private LoggerInterface $logger,
        string $facebookAppId,
        string $facebookAppSecret,
        string $facebookRedirectUri
    ) {
        $this->facebookProvider = new Facebook([
            'clientId' => $facebookAppId,
            'clientSecret' => $facebookAppSecret,
            'redirectUri' => $facebookRedirectUri,
            'graphApiVersion' => 'v18.0',
        ]);
    }

    /**
     * Génère l'URL d'autorisation Facebook
     */
    public function getAuthorizationUrl(): string
    {
        return $this->facebookProvider->getAuthorizationUrl([
            'scope' => ['email', 'public_profile']
        ]);
    }

    /**
     * Récupère le state pour la vérification CSRF
     */
    public function getState(): string
    {
        return $this->facebookProvider->getState();
    }

    /**
     * Authentifie un utilisateur via Facebook
     */
    public function authenticate(string $code): User
    {
        try {
            // Échanger le code contre un access token
            $accessToken = $this->facebookProvider->getAccessToken('authorization_code', [
                'code' => $code
            ]);

            // Récupérer les informations de l'utilisateur
            /** @var FacebookUser $facebookUser */
            $facebookUser = $this->facebookProvider->getResourceOwner($accessToken);

            $facebookId = $facebookUser->getId();
            $email = $facebookUser->getEmail();
            $name = $facebookUser->getName();
            $avatar = $facebookUser->getPictureUrl();

            if (!$email) {
                throw new BadRequestHttpException('L\'email n\'est pas disponible depuis Facebook');
            }

            // Parser le nom (Facebook donne le nom complet)
            $nameParts = explode(' ', $name, 2);
            $firstName = $nameParts[0] ?? 'Prénom';
            $lastName = $nameParts[1] ?? 'Nom';

            // Vérifier si l'utilisateur existe déjà avec ce Facebook ID
            $user = $this->userRepository->findOneBy(['facebookId' => $facebookId]);

            if ($user) {
                // Utilisateur existant, mettre à jour les infos si nécessaire
                $this->updateUserFromFacebook($user, $email, $firstName, $lastName, $avatar);
                $this->logger->info('Connexion Facebook réussie', [
                    'user_id' => $user->getId(),
                    'facebook_id' => $facebookId
                ]);
                return $user;
            }

            // Vérifier si un utilisateur existe avec cet email
            $user = $this->userRepository->findByEmail($email);

            if ($user) {
                // Lier le compte Facebook à l'utilisateur existant
                $user->setFacebookId($facebookId);
                $user->setAuthProvider('facebook');
                $user->setEmailVerifie(true); // Email vérifié par Facebook

                if (!$user->getPhoto() && $avatar) {
                    $user->setPhoto($avatar);
                }

                $this->entityManager->flush();

                $this->logger->info('Compte Facebook lié à un utilisateur existant', [
                    'user_id' => $user->getId(),
                    'facebook_id' => $facebookId
                ]);

                return $user;
            }

            // Créer un nouvel utilisateur
            $user = new User();
            $user->setEmail($email)
                ->setNom($lastName)
                ->setPrenom($firstName)
                ->setFacebookId($facebookId)
                ->setAuthProvider('facebook')
                ->setEmailVerifie(true) // Email vérifié par Facebook
                ->setRoles(['ROLE_USER']);

            if ($avatar) {
                $user->setPhoto($avatar);
            }

            // Pas de mot de passe pour les comptes OAuth
            $user->setPassword(null);

            $this->entityManager->persist($user);
            $this->entityManager->flush();

            $this->logger->info('Nouvel utilisateur créé via Facebook', [
                'user_id' => $user->getId(),
                'facebook_id' => $facebookId,
                'email' => $email
            ]);

            return $user;

        } catch (\Exception $e) {
            $this->logger->error('Erreur lors de l\'authentification Facebook', [
                'error' => $e->getMessage()
            ]);
            throw new BadRequestHttpException('Erreur lors de l\'authentification Facebook');
        }
    }

    /**
     * Met à jour les informations de l'utilisateur depuis Facebook
     */
    private function updateUserFromFacebook(
        User $user,
        string $email,
        string $firstName,
        string $lastName,
        ?string $avatar
    ): void {
        $updated = false;

        if ($user->getEmail() !== $email) {
            $user->setEmail($email);
            $updated = true;
        }

        if ($user->getPrenom() !== $firstName) {
            $user->setPrenom($firstName);
            $updated = true;
        }

        if ($user->getNom() !== $lastName) {
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
