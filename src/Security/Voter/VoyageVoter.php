<?php

declare(strict_types=1);

namespace App\Security\Voter;

use App\Entity\User;
use App\Entity\Voyage;
use App\Service\VisibilityService;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

class VoyageVoter extends Voter
{
    public const EDIT = 'VOYAGE_EDIT';
    public const DELETE = 'VOYAGE_DELETE';
    public const VIEW = 'VOYAGE_VIEW';

    public function __construct(
        private readonly VisibilityService $visibilityService
    ) {}

    protected function supports(string $attribute, mixed $subject): bool
    {
        return in_array($attribute, [self::EDIT, self::DELETE, self::VIEW])
            && ($subject instanceof Voyage || is_int($subject));
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

        /** @var Voyage $voyage */
        $voyage = $subject;

        return match($attribute) {
            self::VIEW => $this->canView($voyage, $user),
            self::EDIT => $this->canEdit($voyage, $user),
            self::DELETE => $this->canDelete($voyage, $user),
            default => false,
        };
    }

    private function canView(Voyage $voyage, User $user): bool
    {
        // Le propriétaire peut toujours voir son propre voyage
        if ($voyage->getVoyageur() === $user) {
            return true;
        }

        // Les admins peuvent tout voir
        if (in_array('ROLE_ADMIN', $user->getRoles())) {
            return true;
        }

        // Vérifier le statut ET la visibilité via VisibilityService
        if ($voyage->getStatut() !== 'actif') {
            return false;
        }

        // ==================== UTILISATION DU VISIBILITYSERVICE ====================
        return $this->visibilityService->isVoyageVisibleFor($voyage, $user);
    }

    private function canEdit(Voyage $voyage, User $user): bool
    {
        // Seul le propriétaire peut modifier
        if ($voyage->getVoyageur() === $user) {
            return true;
        }

        // Les admins peuvent modifier
        return in_array('ROLE_ADMIN', $user->getRoles());
    }

    private function canDelete(Voyage $voyage, User $user): bool
    {
        // Seul le propriétaire peut supprimer
        if ($voyage->getVoyageur() === $user) {
            return true;
        }

        // Les admins peuvent supprimer
        return in_array('ROLE_ADMIN', $user->getRoles());
    }
}
