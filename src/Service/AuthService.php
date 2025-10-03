<?php

declare(strict_types=1);

namespace App\Service;

use App\DTO\RegisterDTO;
use App\Entity\User;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
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
        private LoggerInterface $logger
    ) {}

    public function register(RegisterDTO $dto): User
    {
        // Vérifier si l'email existe déjà
        $existingUser = $this->userRepository->findByEmail($dto->email);
        if ($existingUser) {
            throw new BadRequestHttpException('Cet email est déjà utilisé');
        }

        // Vérifier si le téléphone existe déjà (optionnel)
        if ($dto->telephone) {
            $existingPhone = $this->userRepository->findOneBy(['telephone' => $dto->telephone]);
            if ($existingPhone) {
                throw new BadRequestHttpException('Ce numéro de téléphone est déjà utilisé');
            }
        }

        try {
            $user = new User();
            $user->setEmail($dto->email)
                ->setNom($dto->nom)
                ->setPrenom($dto->prenom)
                ->setTelephone($dto->telephone)
                ->setPassword($this->passwordHasher->hashPassword($user, $dto->password))
                ->setRoles(['ROLE_USER']);

            $this->entityManager->persist($user);
            $this->entityManager->flush();

            // Envoyer email de bienvenue (de manière asynchrone si possible)
            try {
                $this->emailService->sendWelcomeEmail($user);
            } catch (\Exception $e) {
                // Logger l'erreur mais ne pas bloquer l'inscription
                $this->logger->error('Erreur lors de l\'envoi de l\'email de bienvenue', [
                    'user_id' => $user->getId(),
                    'error' => $e->getMessage()
                ]);
            }

            $this->logger->info('Nouvel utilisateur inscrit', [
                'user_id' => $user->getId(),
                'email' => $user->getEmail()
            ]);

            return $user;
        } catch (\Exception $e) {
            $this->logger->error('Erreur lors de l\'inscription', [
                'error' => $e->getMessage(),
                'email' => $dto->email
            ]);
            throw new BadRequestHttpException('Une erreur est survenue lors de l\'inscription');
        }
    }

    public function changePassword(User $user, string $currentPassword, string $newPassword): void
    {
        if (!$this->passwordHasher->isPasswordValid($user, $currentPassword)) {
            throw new BadRequestHttpException('Le mot de passe actuel est incorrect');
        }

        if (strlen($newPassword) < 8) {
            throw new BadRequestHttpException('Le nouveau mot de passe doit contenir au moins 8 caractères');
        }

        try {
            $user->setPassword($this->passwordHasher->hashPassword($user, $newPassword));
            $this->entityManager->flush();

            $this->logger->info('Mot de passe changé', ['user_id' => $user->getId()]);
        } catch (\Exception $e) {
            $this->logger->error('Erreur lors du changement de mot de passe', [
                'user_id' => $user->getId(),
                'error' => $e->getMessage()
            ]);
            throw new BadRequestHttpException('Erreur lors du changement de mot de passe');
        }
    }

    public function verifyEmail(User $user): void
    {
        if ($user->isEmailVerifie()) {
            throw new BadRequestHttpException('Cet email est déjà vérifié');
        }

        try {
            $user->setEmailVerifie(true);
            $this->entityManager->flush();

            $this->logger->info('Email vérifié', ['user_id' => $user->getId()]);
        } catch (\Exception $e) {
            $this->logger->error('Erreur lors de la vérification de l\'email', [
                'user_id' => $user->getId(),
                'error' => $e->getMessage()
            ]);
            throw new BadRequestHttpException('Erreur lors de la vérification de l\'email');
        }
    }

    public function verifyPhone(User $user): void
    {
        if ($user->isTelephoneVerifie()) {
            throw new BadRequestHttpException('Ce numéro est déjà vérifié');
        }

        try {
            $user->setTelephoneVerifie(true);
            $this->entityManager->flush();

            $this->logger->info('Téléphone vérifié', ['user_id' => $user->getId()]);
        } catch (\Exception $e) {
            $this->logger->error('Erreur lors de la vérification du téléphone', [
                'user_id' => $user->getId(),
                'error' => $e->getMessage()
            ]);
            throw new BadRequestHttpException('Erreur lors de la vérification du téléphone');
        }
    }
}
