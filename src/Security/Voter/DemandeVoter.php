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

    public function __construct(
        private readonly VisibilityService $visibilityService
    ) {}

    protected function supports(string $attribute, mixed $subject): bool
    {
        return in_array($attribute, [self::EDIT, self::DELETE, self::VIEW])
            && ($subject instanceof Demande || is_int($subject));
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token): bool
    {
        $user = $token->getUser();

        if (!$user instanceof User) {
            return false;
        }

        // Si c'est un ID, on vérifie juste les rôles admin
        if (is_int($subject)) {
            return in_array('ROLE_ADMIN', $user->getRoles());
        }

        /** @var Demande $demande */
        $demande = $subject;

        return match($attribute) {
            self::VIEW => $this->canView($demande, $user),
            self::EDIT => $this->canEdit($demande, $user),
            self::DELETE => $this->canDelete($demande, $user),
            default => false,
        };
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

        // ==================== UTILISATION DU VISIBILITYSERVICE ====================
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
