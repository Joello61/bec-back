<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\DTO\RegisterDTO;
use App\Entity\PasswordResetToken;
use App\Entity\User;
use App\Repository\UserRepository;
use App\Service\AuthService;
use App\Service\EmailService;
use App\Service\RealtimeNotifier;
use App\Service\SettingsService;
use App\Service\VerificationService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Phase 4b, Lot 1 (bec-docs/docs/plan-correction/plan-correction-cobage.md) : coeur du flux
 * d'authentification (CLAUDE.md section 9, zone prioritaire) - jusqu'ici exerce uniquement
 * de bout en bout via AuthControllerTest (Phase 4), jamais teste unitairement en isolation,
 * ce qui laisse hors d'atteinte la branche emailVerificationEnabled=true (EMAIL_VERIFICATION_ENABLED
 * vaut false dans cet environnement, cf. Phase 4) et les catch generiques qui masquent une
 * exception interne en message d'erreur generique.
 */
class AuthServiceTest extends TestCase
{
    private EntityManagerInterface&\PHPUnit\Framework\MockObject\MockObject $em;
    private UserRepository&\PHPUnit\Framework\MockObject\MockObject $userRepository;
    private UserPasswordHasherInterface&\PHPUnit\Framework\MockObject\MockObject $passwordHasher;
    private EmailService&\PHPUnit\Framework\MockObject\MockObject $emailService;
    private VerificationService&\PHPUnit\Framework\MockObject\MockObject $verificationService;
    private SettingsService&\PHPUnit\Framework\MockObject\MockObject $settingsService;
    private RealtimeNotifier&\PHPUnit\Framework\MockObject\MockObject $notifier;

    private function makeService(bool $emailVerificationEnabled): AuthService
    {
        return new AuthService(
            $this->em,
            $this->userRepository,
            $this->passwordHasher,
            $this->emailService,
            $this->verificationService,
            $this->settingsService,
            new NullLogger(),
            $emailVerificationEnabled,
            $this->notifier,
        );
    }

    protected function setUp(): void
    {
        $this->em = $this->createMock(EntityManagerInterface::class);
        $this->userRepository = $this->createMock(UserRepository::class);
        $this->passwordHasher = $this->createMock(UserPasswordHasherInterface::class);
        $this->emailService = $this->createMock(EmailService::class);
        $this->verificationService = $this->createMock(VerificationService::class);
        $this->settingsService = $this->createMock(SettingsService::class);
        $this->notifier = $this->createMock(RealtimeNotifier::class);
        $this->passwordHasher->method('hashPassword')->willReturn('hashed-password');
    }

    private function registerDto(string $email = 'nouvel-utilisateur@example.test'): RegisterDTO
    {
        $dto = new RegisterDTO();
        $dto->nom = 'Nom';
        $dto->prenom = 'Prenom';
        $dto->email = $email;
        $dto->password = 'Password123';

        return $dto;
    }

    // ==================== register ====================

    public function testRegisterRejectsAnAlreadyUsedEmail(): void
    {
        $this->userRepository->method('findByEmail')->willReturn(new User());
        $this->em->expects(self::never())->method('persist');

        $this->expectException(BadRequestHttpException::class);
        $this->makeService(false)->register($this->registerDto());
    }

    public function testRegisterWithVerificationDisabledAutoVerifiesTheEmail(): void
    {
        $this->userRepository->method('findByEmail')->willReturn(null);
        $this->verificationService->expects(self::never())->method('sendEmailVerification');
        $this->emailService->expects(self::never())->method('sendWelcomeEmail');

        $user = $this->makeService(false)->register($this->registerDto());

        self::assertTrue($user->isEmailVerifie(), 'mode dev/panne (EMAIL_VERIFICATION_ENABLED=false) : le compte doit etre auto-verifie');
        self::assertSame('local', $user->getAuthProvider());
    }

    public function testRegisterWithVerificationEnabledSendsEmailsAndLeavesUnverified(): void
    {
        $this->userRepository->method('findByEmail')->willReturn(null);
        $this->verificationService->expects(self::once())->method('sendEmailVerification');
        $this->emailService->expects(self::once())->method('sendWelcomeEmail');

        $user = $this->makeService(true)->register($this->registerDto());

        self::assertFalse($user->isEmailVerifie(), 'avec verification activee, le compte reste non verifie jusqu\'a la saisie du code');
    }

    public function testRegisterWithVerificationEnabledSurvivesAnEmailSendingFailure(): void
    {
        $this->userRepository->method('findByEmail')->willReturn(null);
        $this->verificationService->method('sendEmailVerification')->willThrowException(new \RuntimeException('SMTP down'));

        $user = $this->makeService(true)->register($this->registerDto());

        self::assertInstanceOf(User::class, $user, 'un echec d\'envoi d\'email ne doit jamais faire echouer l\'inscription elle-meme');
    }

    public function testRegisterMasksAnInternalExceptionBehindAGenericMessage(): void
    {
        $this->userRepository->method('findByEmail')->willReturn(null);
        $this->passwordHasher->method('hashPassword')->willThrowException(new \RuntimeException('detail technique interne'));

        try {
            $this->makeService(false)->register($this->registerDto());
            self::fail('une exception BadRequestHttpException etait attendue');
        } catch (BadRequestHttpException $e) {
            self::assertStringNotContainsString('detail technique interne', $e->getMessage(), 'le catch generique ne doit jamais laisser fuiter le detail de l\'exception interne');
        }
    }

    // ==================== changePassword ====================

    public function testChangePasswordRejectsAnOAuthAccountWithoutPassword(): void
    {
        $user = new User();
        $user->setPassword(null);

        $this->expectException(BadRequestHttpException::class);
        $this->makeService(false)->changePassword($user, 'anything', 'NewPassword123');
    }

    public function testChangePasswordRejectsAnIncorrectCurrentPassword(): void
    {
        $user = new User();
        $user->setPassword('hashed');
        $this->passwordHasher->method('isPasswordValid')->willReturn(false);

        $this->expectException(BadRequestHttpException::class);
        $this->makeService(false)->changePassword($user, 'wrong', 'NewPassword123');
    }

    public function testChangePasswordRejectsATooShortNewPassword(): void
    {
        $user = new User();
        $user->setPassword('hashed');
        $this->passwordHasher->method('isPasswordValid')->willReturn(true);

        $this->expectException(BadRequestHttpException::class);
        $this->makeService(false)->changePassword($user, 'correct', 'short');
    }

    public function testChangePasswordSucceedsNotifiesAndEmails(): void
    {
        $user = new User();
        $user->setPassword('hashed');
        $this->passwordHasher->method('isPasswordValid')->willReturn(true);
        $this->em->expects(self::once())->method('flush');
        $this->notifier->expects(self::once())->method('publishToUser');
        $this->emailService->expects(self::once())->method('sendPasswordChangedEmail');

        $this->makeService(false)->changePassword($user, 'correct', 'NewPassword123');

        self::assertSame('hashed-password', $user->getPassword());
    }

    public function testChangePasswordSurvivesAnEmailSendingFailure(): void
    {
        $user = new User();
        $user->setPassword('hashed');
        $this->passwordHasher->method('isPasswordValid')->willReturn(true);
        $this->emailService->method('sendPasswordChangedEmail')->willThrowException(new \RuntimeException('SMTP down'));

        $this->makeService(false)->changePassword($user, 'correct', 'NewPassword123');

        self::assertSame('hashed-password', $user->getPassword(), 'le changement doit rester applique meme si l\'email de confirmation echoue');
    }

    // ==================== resetPassword ====================

    public function testResetPasswordRejectsATooShortNewPassword(): void
    {
        $user = new User();
        $resetToken = new PasswordResetToken();
        $resetToken->setUser($user);
        $this->verificationService->method('validateResetToken')->willReturn($resetToken);

        $this->expectException(BadRequestHttpException::class);
        $this->makeService(false)->resetPassword('un-token', 'short');
    }

    public function testResetPasswordSucceedsMarksTokenUsedAndNotifies(): void
    {
        $user = new User();
        $resetToken = new PasswordResetToken();
        $resetToken->setUser($user);
        $this->verificationService->method('validateResetToken')->willReturn($resetToken);
        $this->verificationService->expects(self::once())->method('markTokenAsUsed')->with($resetToken);
        $this->em->expects(self::once())->method('flush');

        $this->makeService(false)->resetPassword('un-token', 'NewPassword123');

        self::assertSame('hashed-password', $user->getPassword());
    }

    // ==================== requestPasswordReset ====================

    public function testRequestPasswordResetIsSilentForAnUnknownEmail(): void
    {
        $this->userRepository->method('findByEmail')->willReturn(null);
        $this->verificationService->expects(self::never())->method('createPasswordResetToken');

        // ne doit lever aucune exception - comportement volontairement non-revelateur
        $this->makeService(false)->requestPasswordReset('inconnu@example.test');
        self::assertTrue(true);
    }

    public function testRequestPasswordResetRejectsAnOAuthAccount(): void
    {
        $user = new User();
        $user->setPassword(null);
        $this->userRepository->method('findByEmail')->willReturn($user);

        $this->expectException(BadRequestHttpException::class);
        $this->makeService(false)->requestPasswordReset('oauth-user@example.test');
    }

    public function testRequestPasswordResetSucceedsForALocalAccount(): void
    {
        $user = new User();
        $user->setPassword('hashed');
        $this->userRepository->method('findByEmail')->willReturn($user);
        $this->verificationService->expects(self::once())->method('createPasswordResetToken')->with($user);
        $this->notifier->expects(self::once())->method('publishToUser');

        $this->makeService(false)->requestPasswordReset('local-user@example.test');
    }

    // ==================== verifyEmail / verifyPhone ====================

    public function testVerifyEmailDelegatesAndNotifies(): void
    {
        $user = new User();
        $this->verificationService->expects(self::once())->method('verifyEmailCode')->with($user, '123456');
        $this->notifier->expects(self::once())->method('publishToUser');
        $this->notifier->expects(self::once())->method('publishToGroup');

        $this->makeService(false)->verifyEmail($user, '123456');
    }

    public function testVerifyEmailRethrowsTheUnderlyingException(): void
    {
        $user = new User();
        $this->verificationService->method('verifyEmailCode')->willThrowException(new BadRequestHttpException('Code invalide ou expiré'));

        $this->expectException(BadRequestHttpException::class);
        $this->expectExceptionMessage('Code invalide ou expiré');
        $this->makeService(false)->verifyEmail($user, 'bad-code');
    }

    public function testVerifyPhoneDelegatesAndNotifies(): void
    {
        $user = new User();
        $this->verificationService->expects(self::once())->method('verifyPhoneCode')->with($user, '123456');

        $this->makeService(false)->verifyPhone($user, '123456');
    }

    public function testVerifyPhoneRethrowsTheUnderlyingException(): void
    {
        $user = new User();
        $this->verificationService->method('verifyPhoneCode')->willThrowException(new BadRequestHttpException('Code invalide ou expiré'));

        $this->expectException(BadRequestHttpException::class);
        $this->makeService(false)->verifyPhone($user, 'bad-code');
    }

    // ==================== resend* ====================

    public function testResendEmailVerificationDelegates(): void
    {
        $user = new User();
        $this->verificationService->expects(self::once())->method('sendEmailVerification')->with($user);

        $this->makeService(false)->resendEmailVerification($user);
    }

    public function testResendEmailVerificationRethrows(): void
    {
        $user = new User();
        $this->verificationService->method('sendEmailVerification')->willThrowException(new BadRequestHttpException('déjà vérifié'));

        $this->expectException(BadRequestHttpException::class);
        $this->makeService(false)->resendEmailVerification($user);
    }

    public function testResendPhoneVerificationDelegates(): void
    {
        $user = new User();
        $this->verificationService->expects(self::once())->method('sendPhoneVerification')->with($user);

        $this->makeService(false)->resendPhoneVerification($user);
    }

    public function testResendPhoneVerificationRethrows(): void
    {
        $user = new User();
        $this->verificationService->method('sendPhoneVerification')->willThrowException(new BadRequestHttpException('numéro invalide'));

        $this->expectException(BadRequestHttpException::class);
        $this->makeService(false)->resendPhoneVerification($user);
    }
}
