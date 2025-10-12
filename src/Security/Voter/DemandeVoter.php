<?php

declare(strict_types=1);

namespace App\Security\Voter;

use App\Entity\Demande;
use App\Entity\User;
use App\Service\VisibilityService;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

class DemandeVoter extends Voter
{
    public const EDIT = 'DEMANDE_EDIT';
    public const DELETE = 'DEMANDE_DELETE';
    public const VIEW = 'DEMANDE_VIEW';
    public const CREATE = 'DEMANDE_CREATE'; // ⬅️ NOUVEAU

    public function __construct(
        private readonly VisibilityService $visibilityService
    ) {}

    protected function supports(string $attribute, mixed $subject): bool
    {
        // CREATE ne nécessite pas de subject (juste vérifier l'utilisateur)
        if ($attribute === self::CREATE) {
            return true;
        }

        return in_array($attribute, [self::EDIT, self::DELETE, self::VIEW])
            && ($subject instanceof Demande || is_int($subject));
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

    // ==================== NOUVELLE MÉTHODE : VÉRIFICATION PROFIL ====================

    /**
     * Vérifie si l'utilisateur peut créer une demande
     * Nécessite un profil complet
     */
    private function canCreate(User $user): bool
    {
        // Les admins peuvent toujours créer
        if (in_array('ROLE_ADMIN', $user->getRoles())) {
            return true;
        }

        if (!$user->isProfileComplete()) {
            return false;
        }

        return true;
    }

    private function canView(Demande $demande, User $user): bool
    {
        // Le propriétaire peut toujours voir sa propre demande
        if ($demande->getClient() === $user) {
            return true;
        }

        // Les admins peuvent tout voir
        if (in_array('ROLE_ADMIN', $user->getRoles())) {
            return true;
        }

        // Vérifier le statut ET la visibilité via VisibilityService
        if ($demande->getStatut() !== 'en_recherche') {
            return false;
        }

        return $this->visibilityService->isDemandeVisibleFor($demande, $user);
    }

    private function canEdit(Demande $demande, User $user): bool
    {
        // Seul le propriétaire peut modifier
        if ($demande->getClient() === $user) {
            return true;
        }

        // Les admins peuvent modifier
        return in_array('ROLE_ADMIN', $user->getRoles());
    }

    private function canDelete(Demande $demande, User $user): bool
    {
        // Seul le propriétaire peut supprimer
        if ($demande->getClient() === $user) {
            return true;
        }

        // Les admins peuvent supprimer
        return in_array('ROLE_ADMIN', $user->getRoles());
    }
}
