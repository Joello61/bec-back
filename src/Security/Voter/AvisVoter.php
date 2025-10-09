<?php

declare(strict_types=1);

namespace App\Security\Voter;

use App\Entity\Avis;
use App\Entity\User;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

class AvisVoter extends Voter
{
    public const EDIT = 'AVIS_EDIT';
    public const DELETE = 'AVIS_DELETE';

    protected function supports(string $attribute, mixed $subject): bool
    {
        return in_array($attribute, [self::EDIT, self::DELETE])
            && $subject instanceof Avis;
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token): bool
    {
        $user = $token->getUser();

        if (!$user instanceof User) {
            return false;
        }

        /** @var Avis $avis */
        $avis = $subject;

        return match($attribute) {
            self::EDIT => $this->canEdit($avis, $user),
            self::DELETE => $this->canDelete($avis, $user),
            default => false,
        };
    }

    private function canEdit(Avis $avis, User $user): bool
    {
        // Seul l'auteur peut modifier son avis
        return $avis->getAuteur() === $user
            || in_array('ROLE_ADMIN', $user->getRoles());
    }

    private function canDelete(Avis $avis, User $user): bool
    {
        // L'auteur peut supprimer son avis
        if ($avis->getAuteur() === $user) {
            return true;
        }

        // La personne évaluée peut supprimer un avis la concernant
        if ($avis->getCible() === $user) {
            return true;
        }

        // Les admins peuvent supprimer
        return in_array('ROLE_ADMIN', $user->getRoles());
    }
}
