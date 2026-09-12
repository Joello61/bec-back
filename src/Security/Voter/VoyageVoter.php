<?php

declare(strict_types=1);

namespace App\Security\Voter;

use App\Entity\User;
use App\Entity\Voyage;
use App\Repository\VoyageRepository;
use App\Service\SubscriptionService;
use App\Service\VisibilityService;
use Psr\Log\LoggerInterface;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * @extends Voter<string, Voyage|int|null>
 */
class VoyageVoter extends Voter
{
    public const EDIT = 'VOYAGE_EDIT';
    public const DELETE = 'VOYAGE_DELETE';
    public const VIEW = 'VOYAGE_VIEW';
    public const CREATE = 'VOYAGE_CREATE'; // <- NOUVEAU

    public function __construct(
        private readonly VisibilityService $visibilityService,
        private readonly SubscriptionService $subscriptionService,
        private readonly VoyageRepository $voyageRepository,
        private readonly LoggerInterface $logger,
    ) {}

    protected function supports(string $attribute, mixed $subject): bool
    {
        // CREATE ne nécessite pas de subject
        if ($attribute === self::CREATE) {
            return true;
        }

        return in_array($attribute, [self::EDIT, self::DELETE, self::VIEW])
            && ($subject instanceof Voyage || is_int($subject));
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
     * Vérifie si l'utilisateur peut créer un voyage
     * Nécessite un profil complet
     */
    private function canCreate(User $user): bool
    {
        // Les admins peuvent toujours créer
        if (in_array('ROLE_ADMIN', $user->getRoles())) {
            return true;
        }

        // ==================== VÉRIFICATION PROFIL COMPLET ====================
        if (!$user->isProfileComplete()) {
            return false;
        }

        // ==================== QUOTA FREEMIUM (monétisation Lot 1) ====================
        // Fail-open : un catalogue d'abonnement mal configure/non seede (SubscriptionPlan
        // "free" absent) ne doit jamais bloquer la creation de voyages, fonctionnalite
        // preexistante a la monetisation - seul un quota effectivement determine peut
        // refuser la creation.
        try {
            $maxActiveVoyages = $this->subscriptionService->getEffectivePlan($user)->getMaxActiveVoyages();
        } catch (\Throwable $e) {
            $this->logger->error('Quota freemium indisponible (catalogue d\'abonnement non seede ?) - creation autorisee par defaut', [
                'exception' => $e->getMessage(),
            ]);
            return true;
        }

        if ($maxActiveVoyages !== null && $this->voyageRepository->countActiveByUser($user) >= $maxActiveVoyages) {
            return false;
        }

        return true;
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
