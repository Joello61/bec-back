<?php

declare(strict_types=1);

namespace App\Security\Voter;

use App\Entity\Message;
use App\Entity\User;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

class MessageVoter extends Voter
{
    public const CREATE = 'MESSAGE_CREATE';
    public const SEND = 'MESSAGE_SEND';
    public const DELETE = 'MESSAGE_DELETE';
    public const VIEW = 'MESSAGE_VIEW';

    protected function supports(string $attribute, mixed $subject): bool
    {
        // CREATE et SEND ne nécessitent pas de subject
        if (in_array($attribute, [self::CREATE, self::SEND])) {
            return true;
        }

        return in_array($attribute, [self::DELETE, self::VIEW])
            && $subject instanceof Message;
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token): bool
    {
        $user = $token->getUser();

        if (!$user instanceof User) {
            return false;
        }

        return match($attribute) {
            self::CREATE, self::SEND => $this->canSend($user),
            self::VIEW => $this->canView($subject, $user),
            self::DELETE => $this->canDelete($subject, $user),
            default => false,
        };
    }

    /**
     * ==================== ENVOI MESSAGE : PROFIL COMPLET REQUIS ====================
     * Un utilisateur ne peut envoyer un message que si son profil est complet
     * Ceci empêche le spam et assure que tous les utilisateurs sont vérifiés
     */
    private function canSend(User $user): bool
    {
        // Les admins peuvent toujours envoyer des messages
        if (in_array('ROLE_ADMIN', $user->getRoles())) {
            return true;
        }

        // Profil complet obligatoire pour envoyer des messages
        if (!$user->isProfileComplete()) {
            return false;
        }

        return true;
    }

    /**
     * Seuls l'expéditeur et le destinataire peuvent voir un message
     */
    private function canView(Message $message, User $user): bool
    {
        // L'expéditeur peut voir ses messages envoyés
        if ($message->getExpediteur() === $user) {
            return true;
        }

        // Le destinataire peut voir ses messages reçus
        if ($message->getDestinataire() === $user) {
            return true;
        }

        // Les admins peuvent voir tous les messages (modération)
        return in_array('ROLE_ADMIN', $user->getRoles());
    }

    /**
     * Seul l'expéditeur ou un admin peut supprimer un message
     */
    private function canDelete(Message $message, User $user): bool
    {
        // L'expéditeur peut supprimer son propre message
        if ($message->getExpediteur() === $user) {
            return true;
        }

        // Les admins peuvent supprimer (modération)
        return in_array('ROLE_ADMIN', $user->getRoles());
    }
}
