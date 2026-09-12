<?php

declare(strict_types=1);

namespace App\Security\Voter;

use App\Entity\Demande;
use App\Entity\User;
use App\Repository\DemandeRepository;
use App\Service\SubscriptionService;
use App\Service\VisibilityService;
use Psr\Log\LoggerInterface;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * @extends Voter<string, Demande|int|null>
 */
class DemandeVoter extends Voter
{
    public const EDIT = 'DEMANDE_EDIT';
    public const DELETE = 'DEMANDE_DELETE';
    public const VIEW = 'DEMANDE_VIEW';
    public const CREATE = 'DEMANDE_CREATE'; // <- NOUVEAU

    public function __construct(
        private readonly VisibilityService $visibilityService,
        private readonly SubscriptionService $subscriptionService,
        private readonly DemandeRepository $demandeRepository,
        private readonly LoggerInterface $logger,
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

        // ==================== QUOTA FREEMIUM (monétisation Lot 1) ====================
        // Fail-open : cf. VoyageVoter::canCreate() - un catalogue non seede ne doit jamais
        // bloquer la creation de demandes, fonctionnalite preexistante a la monetisation.
        try {
            $maxActiveDemandes = $this->subscriptionService->getEffectivePlan($user)->getMaxActiveDemandes();
        } catch (\Throwable $e) {
            $this->logger->error('Quota freemium indisponible (catalogue d\'abonnement non seede ?) - creation autorisee par defaut', [
                'exception' => $e->getMessage(),
            ]);
            return true;
        }

        if ($maxActiveDemandes !== null && $this->demandeRepository->countActiveByUser($user) >= $maxActiveDemandes) {
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
