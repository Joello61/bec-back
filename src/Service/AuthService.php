<?php

declare(strict_types=1);

namespace App\Service;

use App\DTO\RegisterDTO;
use App\Entity\User;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

readonly class AuthService
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private UserRepository $userRepository,
        private UserPasswordHasherInterface $passwordHasher,
        private EmailService $emailService
    ) {}

    public function register(RegisterDTO $dto): User
    {
        // Vérifier si l'email existe déjà
        $existingUser = $this->userRepository->findByEmail($dto->email);
        if ($existingUser) {
            throw new BadRequestHttpException('Cet email est déjà utilisé');
        }

        $user = new User();
        $user->setEmail($dto->email)
            ->setNom($dto->nom)
            ->setPrenom($dto->prenom)
            ->setTelephone($dto->telephone)
            ->setPassword($this->passwordHasher->hashPassword($user, $dto->password))
            ->setRoles(['ROLE_USER']);

        $this->entityManager->persist($user);
        $this->entityManager->flush();

        // Envoyer email de bienvenue
        $this->emailService->sendWelcomeEmail($user);

        return $user;
    }

    public function changePassword(User $user, string $currentPassword, string $newPassword): void
    {
        if (!$this->passwordHasher->isPasswordValid($user, $currentPassword)) {
            throw new BadRequestHttpException('Le mot de passe actuel est incorrect');
        }

        $user->setPassword($this->passwordHasher->hashPassword($user, $newPassword));
        $this->entityManager->flush();
    }

    public function verifyEmail(User $user): void
    {
        $user->setEmailVerifie(true);
        $this->entityManager->flush();
    }

    public function verifyPhone(User $user): void
    {
        $user->setTelephoneVerifie(true);
        $this->entityManager->flush();
    }
}
