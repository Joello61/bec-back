<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\SubscriptionPlan;
use App\Entity\User;
use App\Entity\UserSettings;
use App\Entity\UserSubscription;
use App\Service\EmailService;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;

/**
 * Phase 4b, Lot 4 (bec-docs/docs/plan-correction/plan-correction-cobage.md) : la seule
 * regle metier de ce service, mais critique - un email transactionnel (verification, reset
 * password, confirmation de changement de mot de passe) ne doit jamais etre bloque par les
 * preferences de l'utilisateur, contrairement a un email de notification generique.
 */
class EmailServiceTest extends TestCase
{
    private MailerInterface&\PHPUnit\Framework\MockObject\MockObject $mailer;
    private EmailService $service;

    protected function setUp(): void
    {
        $this->mailer = $this->createMock(MailerInterface::class);
        $this->service = new EmailService($this->mailer, 'https://cobage.test', new NullLogger());
    }

    private function user(bool $canReceiveEmails = true, bool $withSettings = true): User
    {
        $user = new User();
        $user->setEmail('user@example.test');
        $user->setPrenom('Alice');
        $user->setNom('Martin');
        $user->setPassword('irrelevant');

        if ($withSettings) {
            $settings = new UserSettings();
            $settings->setUser($user);
            $settings->setEmailNotificationsEnabled($canReceiveEmails);
            $user->setSettings($settings);
        }

        return $user;
    }

    public function testTransactionalEmailIsSentEvenWhenPreferencesRefuseEmails(): void
    {
        $user = $this->user(canReceiveEmails: false);
        $this->mailer->expects(self::once())->method('send');

        $this->service->sendWelcomeEmail($user);
    }

    public function testTransactionalEmailIsSentWithoutAnySettings(): void
    {
        $user = $this->user(withSettings: false);
        $this->mailer->expects(self::once())->method('send');

        $this->service->sendPasswordChangedEmail($user);
    }

    public function testNonTransactionalEmailIsSentWhenPreferencesAllow(): void
    {
        $user = $this->user(canReceiveEmails: true);
        $this->mailer->expects(self::once())->method('send');

        $this->service->sendNotificationEmail($user, 'Sujet', 'Contenu');
    }

    public function testNonTransactionalEmailIsSkippedWhenPreferencesRefuse(): void
    {
        $user = $this->user(canReceiveEmails: false);
        $this->mailer->expects(self::never())->method('send');

        $this->service->sendNotificationEmail($user, 'Sujet', 'Contenu');
    }

    public function testSendWelcomeEmailTargetsTheUserAddress(): void
    {
        $user = $this->user();
        $captured = null;
        $this->mailer->method('send')->willReturnCallback(function (Email $email) use (&$captured) {
            $captured = $email;
        });

        $this->service->sendWelcomeEmail($user);

        self::assertSame(['user@example.test'], array_map(fn ($a) => $a->getAddress(), $captured->getTo()));
    }

    public function testSendEmailVerificationCodeEmbedsTheCodeInTheBody(): void
    {
        $user = $this->user();
        $captured = null;
        $this->mailer->method('send')->willReturnCallback(function (Email $email) use (&$captured) {
            $captured = $email;
        });

        $this->service->sendEmailVerificationCode($user, '123456');

        self::assertStringContainsString('123456', $captured->getHtmlBody());
    }

    public function testSendPasswordResetEmailBuildsTheResetUrlFromFrontendUrl(): void
    {
        $user = $this->user();
        $captured = null;
        $this->mailer->method('send')->willReturnCallback(function (Email $email) use (&$captured) {
            $captured = $email;
        });

        $this->service->sendPasswordResetEmail($user, 'raw-token-abc');

        self::assertStringContainsString('https://cobage.test/auth/reset-password?token=raw-token-abc', $captured->getHtmlBody());
    }

    public function testSendPasswordChangedEmailHasTheRightSubject(): void
    {
        $user = $this->user();
        $captured = null;
        $this->mailer->method('send')->willReturnCallback(function (Email $email) use (&$captured) {
            $captured = $email;
        });

        $this->service->sendPasswordChangedEmail($user);

        self::assertSame('Votre mot de passe a été modifié', $captured->getSubject());
    }

    public function testSendRenewalReminderEmailIsAlwaysSentAndLinksToTheSubscriptionSettings(): void
    {
        // Lot 3 : email transactionnel (toujours envoyé, indépendant des préférences) - un
        // renouvellement manqué fait perdre le service, ce n'est pas une gêne marketing.
        $user = $this->user(canReceiveEmails: false);
        $plan = (new SubscriptionPlan())->setCode('plus')->setName('Plus');
        $subscription = (new UserSubscription())->setPlan($plan)->setCurrentPeriodEnd(new \DateTime('2026-09-20'));

        $captured = null;
        $this->mailer->expects(self::once())->method('send')->willReturnCallback(function (Email $email) use (&$captured) {
            $captured = $email;
        });

        $this->service->sendRenewalReminderEmail($user, $subscription);

        self::assertStringContainsString('https://cobage.test/dashboard/settings/subscription', $captured->getHtmlBody());
        self::assertStringContainsString('20/09/2026', $captured->getHtmlBody());
    }

    public function testTransportExceptionPropagates(): void
    {
        $user = $this->user();
        $this->mailer->method('send')->willThrowException(new TransportException('SMTP down'));

        $this->expectException(TransportException::class);
        $this->service->sendWelcomeEmail($user);
    }
}
