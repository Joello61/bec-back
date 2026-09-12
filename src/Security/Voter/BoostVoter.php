<?php

declare(strict_types=1);

namespace App\Security\Voter;

use App\Entity\Boost;
use App\Entity\Demande;
use App\Entity\User;
use App\Entity\Voyage;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * @extends Voter<string, Voyage|Demande|Boost|null>
 */
class BoostVoter extends Voter
{
    /** Subject = Voyage|Demande (la cible a booster), pas le Boost lui-meme. */
    public const CREATE = 'BOOST_CREATE';
    /** Subject = Boost. */
    public const VIEW = 'BOOST_VIEW';

    public function __construct(
        private readonly AuthorizationCheckerInterface $authorizationChecker,
    ) {}

    protected function supports(string $attribute, mixed $subject): bool
    {
        return match ($attribute) {
            self::CREATE => $subject instanceof Voyage || $subject instanceof Demande,
            self::VIEW => $subject instanceof Boost,
            default => false,
        };
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token): bool
    {
        $user = $token->getUser();

        if (!$user instanceof User) {
            return false;
        }

        return match ($attribute) {
            // Peut-on booster CETTE cible : delegue a l'autorisation d'edition deja en
            // place (VoyageVoter/DemandeVoter), plutot que dupliquer la logique de
            // propriete - un utilisateur ne peut booster que son propre voyage/demande.
            self::CREATE => match (true) {
                $subject instanceof Voyage => $this->authorizationChecker->isGranted(VoyageVoter::EDIT, $subject),
                $subject instanceof Demande => $this->authorizationChecker->isGranted(DemandeVoter::EDIT, $subject),
                default => false,
            },
            self::VIEW => $subject instanceof Boost && ($subject->getUser() === $user || in_array('ROLE_ADMIN', $user->getRoles())),
            default => false,
        };
    }
}
