<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\PasswordResetToken;
use App\Entity\User;
use App\Entity\VerificationCode;
use App\Repository\PasswordResetTokenRepository;
use App\Repository\VerificationCodeRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Psr\Log\LoggerInterface;

readonly class VerificationService
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private VerificationCodeRepository $verificationCodeRepository,
        private PasswordResetTokenRepository $passwordResetTokenRepository,
        private EmailService $emailService,
        private LoggerInterface $logger
    ) {}

    /**
     * Génère et envoie un code de vérification email
     */
    public function sendEmailVerification(User $user): void
    {
        if ($user->isEmailVerifie()) {
            throw new BadRequestHttpException('Cet email est déjà vérifié');
        }

        // Supprimer les anciens codes pour cet email
        $this->verificationCodeRepository->deleteOldCodesForEmail($user->getEmail());

        // Générer un code à 6 chiffres
        $code = $this->generateNumericCode(6);

        // Créer le code de vérification
        $verificationCode = new VerificationCode();
        $verificationCode->setEmail($user->getEmail())
            ->setCode($code)
            ->setType('email')
            ->setExpiresAt(new \DateTime('+15 minutes'));

        $this->entityManager->persist($verificationCode);
        $this->entityManager->flush();

        // Envoyer l'email
        try {
            $this->emailService->sendEmailVerificationCode($user, $code);
            $this->logger->info('Code de vérification email envoyé', [
                'user_id' => $user->getId(),
                'email' => $user->getEmail()
            ]);
        } catch (\Exception $e) {
            $this->logger->error('Erreur lors de l\'envoi du code email', [
                'user_id' => $user->getId(),
                'error' => $e->getMessage()
            ]);
            throw new BadRequestHttpException('Erreur lors de l\'envoi du code de vérification');
        }
    }

    /**
     * Vérifie un code email
     */
    public function verifyEmailCode(User $user, string $code): void
    {
        $verificationCode = $this->verificationCodeRepository->findValidCodeForEmail(
            $user->getEmail(),
            $code
        );

        if (!$verificationCode) {
            throw new BadRequestHttpException('Code invalide ou expiré');
        }

        // Marquer le code comme utilisé
        $verificationCode->setUsed(true);
        $verificationCode->setUsedAt(new \DateTime());

        // Vérifier l'email de l'utilisateur
        $user->setEmailVerifie(true);

        $this->entityManager->flush();

        $this->logger->info('Email vérifié avec succès', [
            'user_id' => $user->getId()
        ]);
    }

    /**
     * Génère et envoie un code de vérification SMS
     */
    public function sendPhoneVerification(User $user): void
    {
        if (!$user->getTelephone()) {
            throw new BadRequestHttpException('Aucun numéro de téléphone associé');
        }

        if ($user->isTelephoneVerifie()) {
            throw new BadRequestHttpException('Ce numéro est déjà vérifié');
        }

        // Supprimer les anciens codes pour ce numéro
        $this->verificationCodeRepository->deleteOldCodesForPhone($user->getTelephone());

        // Générer un code à 6 chiffres
        $code = $this->generateNumericCode(6);

        // Créer le code de vérification
        $verificationCode = new VerificationCode();
        $verificationCode->setPhone($user->getTelephone())
            ->setCode($code)
            ->setType('phone')
            ->setExpiresAt(new \DateTime('+15 minutes'));

        $this->entityManager->persist($verificationCode);
        $this->entityManager->flush();

        // TODO: Envoyer le SMS via Twilio ou autre service
        // Pour le moment, on log juste le code (à supprimer en production)
        $this->logger->info('Code de vérification SMS généré', [
            'user_id' => $user->getId(),
            'phone' => $user->getTelephone(),
            'code' => $code // À SUPPRIMER EN PRODUCTION
        ]);
    }

    /**
     * Vérifie un code SMS
     */
    public function verifyPhoneCode(User $user, string $code): void
    {
        if (!$user->getTelephone()) {
            throw new BadRequestHttpException('Aucun numéro de téléphone associé');
        }

        $verificationCode = $this->verificationCodeRepository->findValidCodeForPhone(
            $user->getTelephone(),
            $code
        );

        if (!$verificationCode) {
            throw new BadRequestHttpException('Code invalide ou expiré');
        }

        // Marquer le code comme utilisé
        $verificationCode->setUsed(true);
        $verificationCode->setUsedAt(new \DateTime());

        // Vérifier le téléphone de l'utilisateur
        $user->setTelephoneVerifie(true);

        $this->entityManager->flush();

        $this->logger->info('Téléphone vérifié avec succès', [
            'user_id' => $user->getId()
        ]);
    }

    /**
     * Crée un token de réinitialisation de mot de passe
     */
    public function createPasswordResetToken(User $user): string
    {
        // Limiter les demandes de reset (max 3 par heure)
        $recentCount = $this->passwordResetTokenRepository->countRecentTokensForUser($user, 60);
        if ($recentCount >= 3) {
            throw new BadRequestHttpException('Trop de demandes de réinitialisation. Veuillez réessayer dans 1 heure.');
        }

        // Supprimer les anciens tokens de cet utilisateur
        $this->passwordResetTokenRepository->deleteOldTokensForUser($user);

        // Générer un token unique
        $token = bin2hex(random_bytes(32));

        // Créer le token
        $resetToken = new PasswordResetToken();
        $resetToken->setUser($user)
            ->setToken($token)
            ->setExpiresAt(new \DateTime('+1 hour'));

        $this->entityManager->persist($resetToken);
        $this->entityManager->flush();

        // Envoyer l'email
        try {
            $this->emailService->sendPasswordResetEmail($user, $token);
            $this->logger->info('Token de réinitialisation créé', [
                'user_id' => $user->getId()
            ]);
        } catch (\Exception $e) {
            $this->logger->error('Erreur lors de l\'envoi de l\'email de reset', [
                'user_id' => $user->getId(),
                'error' => $e->getMessage()
            ]);
            throw new BadRequestHttpException('Erreur lors de l\'envoi de l\'email');
        }

        return $token;
    }

    /**
     * Valide un token de réinitialisation
     */
    public function validateResetToken(string $token): PasswordResetToken
    {
        $resetToken = $this->passwordResetTokenRepository->findValidToken($token);

        if (!$resetToken) {
            throw new BadRequestHttpException('Token invalide ou expiré');
        }

        return $resetToken;
    }

    /**
     * Marque un token comme utilisé
     */
    public function markTokenAsUsed(PasswordResetToken $token): void
    {
        $token->setUsed(true);
        $token->setUsedAt(new \DateTime());
        $this->entityManager->flush();
    }

    /**
     * Nettoie les codes et tokens expirés (à appeler périodiquement)
     */
    public function cleanupExpired(): void
    {
        $deletedCodes = $this->verificationCodeRepository->deleteExpiredCodes();
        $deletedTokens = $this->passwordResetTokenRepository->deleteExpiredTokens();

        $this->logger->info('Nettoyage effectué', [
            'deleted_codes' => $deletedCodes,
            'deleted_tokens' => $deletedTokens
        ]);
    }

    /**
     * Génère un code numérique aléatoire
     */
    private function generateNumericCode(int $length): string
    {
        $code = '';
        for ($i = 0; $i < $length; $i++) {
            $code .= random_int(0, 9);
        }
        return $code;
    }
}
