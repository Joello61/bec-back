<?php

declare(strict_types=1);

namespace App\Service;

use App\DTO\RegisterDTO;
use App\Entity\User;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Exception;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Psr\Log\LoggerInterface;

readonly class AuthService
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private UserRepository $userRepository,
        private UserPasswordHasherInterface $passwordHasher,
        private EmailService $emailService,
        private VerificationService $verificationService,
        private SettingsService $settingsService,
        private LoggerInterface $logger
    ) {}

    public function register(RegisterDTO $dto): User
    {
        // Vérifier si l'email existe déjà
        $existingUser = $this->userRepository->findByEmail($dto->email);
        if ($existingUser) {
            throw new BadRequestHttpException('Cet email est déjà utilisé');
        }

        try {
            $user = new User();
            $user->setEmail($dto->email)
                ->setNom($dto->nom)
                ->setPrenom($dto->prenom)
                ->setPassword($this->passwordHasher->hashPassword($user, $dto->password))
                ->setRoles(['ROLE_USER'])
                ->setAuthProvider('local');

            $this->entityManager->persist($user);
            $this->entityManager->flush();

            // Créer les settings par défaut
            $this->settingsService->createDefaultSettings($user);

            // Envoyer le code de vérification email IMMÉDIATEMENT
            try {
                $this->verificationService->sendEmailVerification($user);
                $this->emailService->sendWelcomeEmail($user);
            } catch (Exception $e) {
                $this->logger->error('Erreur lors de l\'envoi des emails', [
                    'user_id' => $user->getId(),
                    'error' => $e->getMessage()
                ]);
            }

            $this->logger->info('Nouvel utilisateur inscrit', [
                'user_id' => $user->getId(),
                'email' => $user->getEmail()
            ]);

            return $user;
        } catch (Exception $e) {
            $this->logger->error('Erreur lors de l\'inscription', [
                'error' => $e->getMessage(),
                'email' => $dto->email
            ]);
            throw new BadRequestHttpException('Une erreur est survenue lors de l\'inscription');
        }
    }

    public function changePassword(User $user, string $currentPassword, string $newPassword): void
    {
        // Vérifier si l'utilisateur a un mot de passe (peut ne pas en avoir si OAuth)
        if (!$user->getPassword()) {
            throw new BadRequestHttpException('Ce compte utilise une connexion externe (Google/Facebook)');
        }

        if (!$this->passwordHasher->isPasswordValid($user, $currentPassword)) {
            throw new BadRequestHttpException('Le mot de passe actuel est incorrect');
        }

        if (strlen($newPassword) < 8) {
            throw new BadRequestHttpException('Le nouveau mot de passe doit contenir au moins 8 caractères');
        }

        try {
            $user->setPassword($this->passwordHasher->hashPassword($user, $newPassword));
            $this->entityManager->flush();

            // Envoyer un email de confirmation
            try {
                $this->emailService->sendPasswordChangedEmail($user);
            } catch (Exception $e) {
                $this->logger->error('Erreur lors de l\'envoi de l\'email de confirmation', [
                    'user_id' => $user->getId(),
                    'error' => $e->getMessage()
                ]);
            }

            $this->logger->info('Mot de passe changé', ['user_id' => $user->getId()]);
        } catch (Exception $e) {
            $this->logger->error('Erreur lors du changement de mot de passe', [
                'user_id' => $user->getId(),
                'error' => $e->getMessage()
            ]);
            throw new BadRequestHttpException('Erreur lors du changement de mot de passe');
        }
    }

    public function resetPassword(string $token, string $newPassword): void
    {
        // Valider le token
        $resetToken = $this->verificationService->validateResetToken($token);
        $user = $resetToken->getUser();

        if (strlen($newPassword) < 8) {
            throw new BadRequestHttpException('Le mot de passe doit contenir au moins 8 caractères');
        }

        try {
            // Définir le nouveau mot de passe
            $user->setPassword($this->passwordHasher->hashPassword($user, $newPassword));

            // Marquer le token comme utilisé
            $this->verificationService->markTokenAsUsed($resetToken);

            $this->entityManager->flush();

            // Envoyer un email de confirmation
            try {
                $this->emailService->sendPasswordChangedEmail($user);
            } catch (Exception $e) {
                $this->logger->error('Erreur lors de l\'envoi de l\'email', [
                    'user_id' => $user->getId(),
                    'error' => $e->getMessage()
                ]);
            }

            $this->logger->info('Mot de passe réinitialisé', [
                'user_id' => $user->getId()
            ]);
        } catch (Exception $e) {
            $this->logger->error('Erreur lors de la réinitialisation', [
                'user_id' => $user->getId(),
                'error' => $e->getMessage()
            ]);
            throw new BadRequestHttpException('Erreur lors de la réinitialisation du mot de passe');
        }
    }

    public function requestPasswordReset(string $email): void
    {
        $user = $this->userRepository->findByEmail($email);

        // Ne pas révéler si l'email existe ou non (sécurité)
        if (!$user) {
            $this->logger->warning('Tentative de reset pour email inconnu', ['email' => $email]);
            return;
        }

        // Vérifier que l'utilisateur a un mot de passe (pas OAuth)
        if (!$user->getPassword()) {
            $this->logger->warning('Tentative de reset pour compte OAuth', [
                'user_id' => $user->getId(),
                'provider' => $user->getAuthProvider()
            ]);
            throw new BadRequestHttpException('Ce compte utilise une connexion externe (Google/Facebook)');
        }

        try {
            $this->verificationService->createPasswordResetToken($user);
        } catch (Exception $e) {
            $this->logger->error('Erreur lors de la création du token de reset', [
                'user_id' => $user->getId(),
                'error' => $e->getMessage()
            ]);
            throw new BadRequestHttpException($e->getMessage());
        }
    }

    /**
     * @throws Exception
     */
    public function verifyEmail(User $user, string $code): void
    {
        try {
            $this->verificationService->verifyEmailCode($user, $code);
            $this->logger->info('Email vérifié', ['user_id' => $user->getId()]);
        } catch (Exception $e) {
            $this->logger->error('Erreur lors de la vérification de l\'email', [
                'user_id' => $user->getId(),
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * @throws Exception
     */
    public function verifyPhone(User $user, string $code): void
    {
        try {
            $this->verificationService->verifyPhoneCode($user, $code);
            $this->logger->info('Téléphone vérifié', ['user_id' => $user->getId()]);
        } catch (Exception $e) {
            $this->logger->error('Erreur lors de la vérification du téléphone', [
                'user_id' => $user->getId(),
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * @throws Exception
     */
    public function resendEmailVerification(User $user): void
    {
        try {
            $this->verificationService->sendEmailVerification($user);
        } catch (Exception $e) {
            $this->logger->error('Erreur lors du renvoi du code email', [
                'user_id' => $user->getId(),
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * @throws Exception
     */
    public function resendPhoneVerification(User $user): void
    {
        try {
            $this->verificationService->sendPhoneVerification($user);
        } catch (Exception $e) {
            $this->logger->error('Erreur lors du renvoi du code SMS', [
                'user_id' => $user->getId(),
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }
}
