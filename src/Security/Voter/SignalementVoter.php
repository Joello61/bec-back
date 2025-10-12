<?php

declare(strict_types=1);

namespace App\Security\Voter;

use App\Entity\Signalement;
use App\Entity\User;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

class SignalementVoter extends Voter
{
    public const CREATE = 'SIGNALEMENT_CREATE';
    public const VIEW = 'SIGNALEMENT_VIEW';
    public const PROCESS = 'SIGNALEMENT_PROCESS';

    protected function supports(string $attribute, mixed $subject): bool
    {
        // CREATE ne nécessite pas de subject
        if ($attribute === self::CREATE) {
            return true;
        }

        return in_array($attribute, [self::VIEW, self::PROCESS])
            && $subject instanceof Signalement;
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token): bool
    {
        $user = $token->getUser();

        if (!$user instanceof User) {
            return false;
        }

        return match($attribute) {
            self::CREATE => $this->canCreate($user),
            self::VIEW => $this->canView($subject, $user),
            self::PROCESS => $this->canProcess($user),
            default => false,
        };
    }

    /**
     * ==================== SIGNALEMENT : PROFIL COMPLET REQUIS ====================
     * Un utilisateur ne peut signaler que si son profil est complet
     * Ceci empêche les abus et assure la traçabilité
     */
    private function canCreate(User $user): bool
    {
        // Les admins peuvent toujours créer des signalements
        if (in_array('ROLE_ADMIN', $user->getRoles())) {
            return true;
        }

        // Profil complet obligatoire pour signaler
        if (!$user->isProfileComplete()) {
            return false;
        }

        return true;
    }

    /**
     * Seul le signaleur ou un admin peut voir un signalement
     */
    private function canView(Signalement $signalement, User $user): bool
    {
        // Le signaleur peut voir ses propres signalements
        if ($signalement->getSignaleur() === $user) {
            return true;
        }

        // Les admins/modérateurs peuvent voir tous les signalements
        return in_array('ROLE_ADMIN', $user->getRoles())
            || in_array('ROLE_MODERATOR', $user->getRoles());
    }

    /**
     * Seuls les admins/modérateurs peuvent traiter les signalements
     */
    private function canProcess(User $user): bool
    {
        return in_array('ROLE_ADMIN', $user->getRoles())
            || in_array('ROLE_MODERATOR', $user->getRoles());
    }
}
