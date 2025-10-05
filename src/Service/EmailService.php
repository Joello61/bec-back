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

    public function sendEmailVerificationCode(User $user, string $code): void
    {
        $email = (new Email())
            ->from('noreply@bagage-express.cm')
            ->to($user->getEmail())
            ->subject('Code de vérification de votre email')
            ->html($this->getEmailVerificationCodeContent($user, $code));

        $this->mailer->send($email);
    }

    public function sendPasswordResetEmail(User $user, string $token): void
    {
        $resetUrl = sprintf('%s/auth/reset-password?token=%s', $this->frontendUrl, $token);

        $email = (new Email())
            ->from('noreply@bagage-express.cm')
            ->to($user->getEmail())
            ->subject('Réinitialisation de votre mot de passe')
            ->html($this->getPasswordResetEmailContent($user, $resetUrl));

        $this->mailer->send($email);
    }

    public function sendPasswordChangedEmail(User $user): void
    {
        $email = (new Email())
            ->from('noreply@bagage-express.cm')
            ->to($user->getEmail())
            ->subject('Votre mot de passe a été modifié')
            ->html($this->getPasswordChangedContent($user));

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
            <p><strong>Important :</strong> N\'oubliez pas de vérifier votre adresse email pour accéder à toutes les fonctionnalités.</p>
            <p><a href="%s" style="display:inline-block;padding:12px 24px;background-color:#00695c;color:white;text-decoration:none;border-radius:6px;">Accéder à la plateforme</a></p>
            <hr>
            <p style="font-size:12px;color:#666;">Si vous n\'avez pas créé ce compte, veuillez ignorer cet email.</p>',
            $user->getPrenom(),
            $user->getNom(),
            $this->frontendUrl
        );
    }

    private function getEmailVerificationCodeContent(User $user, string $code): string
    {
        return sprintf(
            '<div style="font-family:Arial,sans-serif;max-width:600px;margin:0 auto;">
                <h1 style="color:#00695c;">Vérification de votre email</h1>
                <p>Bonjour %s,</p>
                <p>Pour vérifier votre adresse email, veuillez utiliser le code suivant :</p>
                <div style="background-color:#f5f5f5;padding:20px;text-align:center;border-radius:8px;margin:24px 0;">
                    <span style="font-size:32px;font-weight:bold;letter-spacing:8px;color:#00695c;">%s</span>
                </div>
                <p>Ce code est valable pendant <strong>15 minutes</strong>.</p>
                <p>Si vous n\'avez pas demandé cette vérification, veuillez ignorer cet email.</p>
                <hr style="margin-top:32px;border:none;border-top:1px solid #e0e0e0;">
                <p style="font-size:12px;color:#666;">
                    Cet email a été envoyé par Bagage Express Cameroun<br>
                    Ne partagez jamais ce code avec qui que ce soit.
                </p>
            </div>',
            $user->getPrenom(),
            $code
        );
    }

    private function getPasswordResetEmailContent(User $user, string $resetUrl): string
    {
        return sprintf(
            '<div style="font-family:Arial,sans-serif;max-width:600px;margin:0 auto;">
                <h1 style="color:#00695c;">Réinitialisation de mot de passe</h1>
                <p>Bonjour %s,</p>
                <p>Vous avez demandé à réinitialiser votre mot de passe.</p>
                <p>Cliquez sur le bouton ci-dessous pour créer un nouveau mot de passe :</p>
                <div style="text-align:center;margin:32px 0;">
                    <a href="%s" style="display:inline-block;padding:14px 28px;background-color:#00695c;color:white;text-decoration:none;border-radius:6px;font-weight:bold;">
                        Réinitialiser mon mot de passe
                    </a>
                </div>
                <p>Ce lien expire dans <strong>1 heure</strong>.</p>
                <p>Si vous n\'avez pas fait cette demande, ignorez cet email. Votre mot de passe restera inchangé.</p>
                <hr style="margin-top:32px;border:none;border-top:1px solid #e0e0e0;">
                <p style="font-size:12px;color:#666;">
                    Si le bouton ne fonctionne pas, copiez-collez ce lien dans votre navigateur :<br>
                    <a href="%s" style="color:#00695c;word-break:break-all;">%s</a>
                </p>
            </div>',
            $user->getPrenom(),
            $resetUrl,
            $resetUrl,
            $resetUrl
        );
    }

    private function getPasswordChangedContent(User $user): string
    {
        return sprintf(
            '<div style="font-family:Arial,sans-serif;max-width:600px;margin:0 auto;">
                <h1 style="color:#00695c;">Mot de passe modifié</h1>
                <p>Bonjour %s,</p>
                <p>Votre mot de passe a été modifié avec succès.</p>
                <p>Si vous n\'êtes pas à l\'origine de cette modification, veuillez immédiatement :</p>
                <ul>
                    <li>Réinitialiser votre mot de passe</li>
                    <li>Contacter notre support</li>
                </ul>
                <p>Date de modification : <strong>%s</strong></p>
                <hr style="margin-top:32px;border:none;border-top:1px solid #e0e0e0;">
                <p style="font-size:12px;color:#666;">
                    Pour votre sécurité, ne partagez jamais votre mot de passe.
                </p>
            </div>',
            $user->getPrenom(),
            (new \DateTime())->format('d/m/Y à H:i')
        );
    }
}
