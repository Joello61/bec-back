<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\User;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;

class EmailService
{
    public function __construct(
        private readonly MailerInterface $mailer,
        private readonly string $frontendUrl
    ) {}

    public function sendWelcomeEmail(User $user): void
    {
        $email = (new Email())
            ->from('noreply@bagage-express.cm')
            ->to($user->getEmail())
            ->subject('Bienvenue sur Bagage Express Cameroun')
            ->html($this->getWelcomeEmailContent($user));

        $this->mailer->send($email);
    }

    public function sendPasswordResetEmail(User $user, string $token): void
    {
        $resetUrl = sprintf('%s/reset-password?token=%s', $this->frontendUrl, $token);

        $email = (new Email())
            ->from('noreply@bagage-express.cm')
            ->to($user->getEmail())
            ->subject('Réinitialisation de votre mot de passe')
            ->html($this->getPasswordResetEmailContent($user, $resetUrl));

        $this->mailer->send($email);
    }

    public function sendEmailVerification(User $user, string $token): void
    {
        $verifyUrl = sprintf('%s/verify-email?token=%s', $this->frontendUrl, $token);

        $email = (new Email())
            ->from('noreply@bagage-express.cm')
            ->to($user->getEmail())
            ->subject('Vérifiez votre adresse email')
            ->html($this->getEmailVerificationContent($user, $verifyUrl));

        $this->mailer->send($email);
    }

    private function getWelcomeEmailContent(User $user): string
    {
        return sprintf(
            '<h1>Bienvenue %s %s !</h1>
            <p>Nous sommes ravis de vous accueillir sur Bagage Express Cameroun.</p>
            <p>Vous pouvez maintenant :</p>
            <ul>
                <li>Publier vos annonces de voyage</li>
                <li>Créer des demandes de transport</li>
                <li>Échanger avec la communauté</li>
            </ul>
            <p><a href="%s">Accéder à la plateforme</a></p>',
            $user->getPrenom(),
            $user->getNom(),
            $this->frontendUrl
        );
    }

    private function getPasswordResetEmailContent(User $user, string $resetUrl): string
    {
        return sprintf(
            '<h1>Réinitialisation de mot de passe</h1>
            <p>Bonjour %s,</p>
            <p>Vous avez demandé à réinitialiser votre mot de passe.</p>
            <p><a href="%s">Cliquez ici pour réinitialiser votre mot de passe</a></p>
            <p>Ce lien expire dans 1 heure.</p>
            <p>Si vous n\'avez pas fait cette demande, ignorez cet email.</p>',
            $user->getPrenom(),
            $resetUrl
        );
    }

    private function getEmailVerificationContent(User $user, string $verifyUrl): string
    {
        return sprintf(
            '<h1>Vérifiez votre adresse email</h1>
            <p>Bonjour %s,</p>
            <p>Pour activer votre compte, veuillez vérifier votre adresse email.</p>
            <p><a href="%s">Cliquez ici pour vérifier votre email</a></p>',
            $user->getPrenom(),
            $verifyUrl
        );
    }
}
