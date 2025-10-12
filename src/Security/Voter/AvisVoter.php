<?php

declare(strict_types=1);

namespace App\Security\Voter;

use App\Entity\Avis;
use App\Entity\User;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

class AvisVoter extends Voter
{
    public const CREATE = 'AVIS_CREATE';
    public const EDIT = 'AVIS_EDIT';
    public const DELETE = 'AVIS_DELETE';
    public const VIEW = 'AVIS_VIEW';

    protected function supports(string $attribute, mixed $subject): bool
    {
        // CREATE ne nécessite pas de subject
        if ($attribute === self::CREATE) {
            return true;
        }

        return in_array($attribute, [self::EDIT, self::DELETE, self::VIEW])
            && $subject instanceof Avis;
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
            self::EDIT => $this->canEdit($subject, $user),
            self::DELETE => $this->canDelete($subject, $user),
            default => false,
        };
    }

    /**
     * ==================== CRÉATION AVIS : PROFIL COMPLET REQUIS ====================
     * Un utilisateur ne peut donner un avis que si son profil est complet
     */
    private function canCreate(User $user): bool
    {
        // Les admins peuvent toujours créer
        if (in_array('ROLE_ADMIN', $user->getRoles())) {
            return true;
        }

        // Profil complet obligatoire
        if (!$user->isProfileComplete()) {
            return false;
        }

        return true;
    }

    /**
     * Tout le monde peut voir les avis (ils sont publics)
     */
    private function canView(Avis $avis, User $user): bool
    {
        return true;
    }

    /**
     * Seul l'auteur de l'avis peut le modifier
     */
    private function canEdit(Avis $avis, User $user): bool
    {
        // L'auteur peut modifier son propre avis
        if ($avis->getAuteur() === $user) {
            return true;
        }

        // Les admins peuvent modifier
        return in_array('ROLE_ADMIN', $user->getRoles());
    }

    /**
     * L'auteur ou un admin peut supprimer un avis
     */
    private function canDelete(Avis $avis, User $user): bool
    {
        // L'auteur peut supprimer son propre avis
        if ($avis->getAuteur() === $user) {
            return true;
        }

        // Les admins peuvent supprimer
        return in_array('ROLE_ADMIN', $user->getRoles());
    }
}
