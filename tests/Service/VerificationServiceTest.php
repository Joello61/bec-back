<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\PasswordResetToken;
use App\Entity\User;
use App\Entity\VerificationCode;
use App\Repository\PasswordResetTokenRepository;
use App\Repository\VerificationCodeRepository;
use App\Service\EmailService;
use App\Service\TwilioService;
use App\Service\VerificationService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

/**
 * Phase 4b, Lot 1 (bec-docs/docs/plan-correction/plan-correction-cobage.md) : rate limiting
 * (CLAUDE.md section 7) et codes/tokens temporaires - deja utilise indirectement par
 * AuthServiceTest (Lot 1) mais jamais teste directement en isolation jusqu'ici.
 */
class VerificationServiceTest extends TestCase
{
    private EntityManagerInterface&\PHPUnit\Framework\MockObject\MockObject $em;
    private VerificationCodeRepository&\PHPUnit\Framework\MockObject\MockObject $codeRepository;
    private PasswordResetTokenRepository&\PHPUnit\Framework\MockObject\MockObject $tokenRepository;
    private EmailService&\PHPUnit\Framework\MockObject\MockObject $emailService;
    private TwilioService&\PHPUnit\Framework\MockObject\MockObject $twilioService;
    private VerificationService $service;

    protected function setUp(): void
    {
        $this->em = $this->createMock(EntityManagerInterface::class);
        $this->codeRepository = $this->createMock(VerificationCodeRepository::class);
        $this->tokenRepository = $this->createMock(PasswordResetTokenRepository::class);
        $this->emailService = $this->createMock(EmailService::class);
        $this->twilioService = $this->createMock(TwilioService::class);

        $this->service = new VerificationService(
            $this->em,
            $this->codeRepository,
            $this->tokenRepository,
            $this->emailService,
            $this->twilioService,
            new NullLogger(),
        );
    }

    // ==================== sendEmailVerification ====================

    public function testSendEmailVerificationRejectsAnAlreadyVerifiedEmail(): void
    {
        $user = new User();
        $user->setEmailVerifie(true);

        $this->expectException(BadRequestHttpException::class);
        $this->service->sendEmailVerification($user);
    }

    public function testSendEmailVerificationClearsOldCodesAndSendsANewOne(): void
    {
        $user = new User();
        $user->setEmail('user@example.test');
        $this->codeRepository->expects(self::once())->method('deleteOldCodesForEmail')->with('user@example.test');
        $this->em->expects(self::once())->method('persist')->with(self::isInstanceOf(VerificationCode::class));
        $this->em->expects(self::once())->method('flush');
        $this->emailService->expects(self::once())->method('sendEmailVerificationCode')->with($user, self::matchesRegularExpression('/^\d{6}$/'));

        $this->service->sendEmailVerification($user);
    }

    public function testSendEmailVerificationThrowsWhenEmailSendingFails(): void
    {
        $user = new User();
        $user->setEmail('user@example.test');
        $this->emailService->method('sendEmailVerificationCode')->willThrowException(new \RuntimeException('SMTP down'));

        $this->expectException(BadRequestHttpException::class);
        $this->service->sendEmailVerification($user);
    }

    // ==================== verifyEmailCode ====================

    public function testVerifyEmailCodeRejectsAnInvalidCode(): void
    {
        $user = new User();
        $user->setEmail('user@example.test');
        $this->codeRepository->method('findValidCodeForEmail')->willReturn(null);

        $this->expectException(BadRequestHttpException::class);
        $this->service->verifyEmailCode($user, '000000');
    }

    public function testVerifyEmailCodeMarksCodeUsedAndVerifiesTheEmail(): void
    {
        $user = new User();
        $user->setEmail('user@example.test');
        $code = new VerificationCode();
        $this->codeRepository->method('findValidCodeForEmail')->willReturn($code);
        $this->em->expects(self::once())->method('flush');

        $this->service->verifyEmailCode($user, '123456');

        self::assertTrue($code->isUsed());
        self::assertTrue($user->isEmailVerifie());
    }

    // ==================== sendPhoneVerification ====================

    public function testSendPhoneVerificationRejectsAUserWithoutPhone(): void
    {
        $user = new User();

        $this->expectException(BadRequestHttpException::class);
        $this->service->sendPhoneVerification($user);
    }

    public function testSendPhoneVerificationRejectsAnAlreadyVerifiedPhone(): void
    {
        $user = new User();
        $user->setTelephone('+237600000000');
        $user->setTelephoneVerifie(true);

        $this->expectException(BadRequestHttpException::class);
        $this->service->sendPhoneVerification($user);
    }

    public function testSendPhoneVerificationRejectsAnInvalidPhoneNumber(): void
    {
        $user = new User();
        $user->setTelephone('not-a-phone');
        $this->twilioService->method('isValidPhoneNumber')->willReturn(false);

        $this->expectException(BadRequestHttpException::class);
        $this->service->sendPhoneVerification($user);
    }

    public function testSendPhoneVerificationClearsOldCodesAndSendsSms(): void
    {
        $user = new User();
        $user->setTelephone('+237600000000');
        $this->twilioService->method('isValidPhoneNumber')->willReturn(true);
        $this->codeRepository->expects(self::once())->method('deleteOldCodesForPhone')->with('+237600000000');
        $this->twilioService->expects(self::once())->method('sendVerificationCode')->with('+237600000000', self::matchesRegularExpression('/^\d{6}$/'));

        $this->service->sendPhoneVerification($user);
    }

    public function testSendPhoneVerificationThrowsWhenSmsSendingFails(): void
    {
        $user = new User();
        $user->setTelephone('+237600000000');
        $this->twilioService->method('isValidPhoneNumber')->willReturn(true);
        $this->twilioService->method('sendVerificationCode')->willThrowException(new \RuntimeException('Twilio down'));

        $this->expectException(BadRequestHttpException::class);
        $this->service->sendPhoneVerification($user);
    }

    // ==================== verifyPhoneCode ====================

    public function testVerifyPhoneCodeRejectsAUserWithoutPhone(): void
    {
        $this->expectException(BadRequestHttpException::class);
        $this->service->verifyPhoneCode(new User(), '123456');
    }

    public function testVerifyPhoneCodeRejectsAnInvalidCode(): void
    {
        $user = new User();
        $user->setTelephone('+237600000000');
        $this->codeRepository->method('findValidCodeForPhone')->willReturn(null);

        $this->expectException(BadRequestHttpException::class);
        $this->service->verifyPhoneCode($user, '000000');
    }

    public function testVerifyPhoneCodeMarksCodeUsedAndVerifiesThePhone(): void
    {
        $user = new User();
        $user->setTelephone('+237600000000');
        $code = new VerificationCode();
        $this->codeRepository->method('findValidCodeForPhone')->willReturn($code);

        $this->service->verifyPhoneCode($user, '123456');

        self::assertTrue($code->isUsed());
        self::assertTrue($user->isTelephoneVerifie());
    }

    // ==================== createPasswordResetToken ====================

    public function testCreatePasswordResetTokenEnforcesTheHourlyRateLimit(): void
    {
        $this->tokenRepository->method('countRecentTokensForUser')->willReturn(3);
        $this->em->expects(self::never())->method('persist');

        $this->expectException(BadRequestHttpException::class);
        $this->service->createPasswordResetToken(new User());
    }

    public function testCreatePasswordResetTokenAllowsExactlyThreeThenBlocksTheFourth(): void
    {
        $this->tokenRepository->method('countRecentTokensForUser')->willReturn(2);
        $this->tokenRepository->expects(self::once())->method('deleteOldTokensForUser');

        $token = $this->service->createPasswordResetToken(new User());

        self::assertSame(64, strlen($token), '32 octets en hexadecimal = 64 caracteres');
    }

    public function testCreatePasswordResetTokenThrowsWhenEmailSendingFails(): void
    {
        $this->tokenRepository->method('countRecentTokensForUser')->willReturn(0);
        $this->emailService->method('sendPasswordResetEmail')->willThrowException(new \RuntimeException('SMTP down'));

        $this->expectException(BadRequestHttpException::class);
        $this->service->createPasswordResetToken(new User());
    }

    // ==================== validateResetToken ====================

    public function testValidateResetTokenRejectsAnInvalidToken(): void
    {
        $this->tokenRepository->method('findValidToken')->willReturn(null);

        $this->expectException(BadRequestHttpException::class);
        $this->service->validateResetToken('inconnu');
    }

    public function testValidateResetTokenReturnsTheTokenWhenValid(): void
    {
        $resetToken = new PasswordResetToken();
        $this->tokenRepository->method('findValidToken')->with('un-token')->willReturn($resetToken);

        self::assertSame($resetToken, $this->service->validateResetToken('un-token'));
    }

    // ==================== markTokenAsUsed / cleanupExpired ====================

    public function testMarkTokenAsUsedFlagsAndFlushes(): void
    {
        $resetToken = new PasswordResetToken();
        $this->em->expects(self::once())->method('flush');

        $this->service->markTokenAsUsed($resetToken);

        self::assertTrue($resetToken->isUsed());
        self::assertNotNull($resetToken->getUsedAt());
    }

    public function testCleanupExpiredDelegatesToBothRepositories(): void
    {
        $this->codeRepository->expects(self::once())->method('deleteExpiredCodes')->willReturn(3);
        $this->tokenRepository->expects(self::once())->method('deleteExpiredTokens')->willReturn(2);

        $this->service->cleanupExpired();
    }
}
