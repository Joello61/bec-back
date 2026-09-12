<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Demande;
use App\Entity\User;
use App\Entity\Voyage;

/**
 * Service centralisé pour gérer la visibilité et les permissions basées sur les UserSettings
 */
readonly class VisibilityService
{
    public function __construct(
        private SubscriptionService $subscriptionService,
    ) {}

    /**
     * Vérifie si un utilisateur est visible dans les résultats de recherche
     */
    public function isUserVisibleInSearch(User $user): bool
    {
        $settings = $user->getSettings();

        if (!$settings) {
            return true; // Par défaut visible si pas de settings
        }

        return $settings->isShowInSearchResults();
    }

    /**
     * Vérifie si le profil d'un utilisateur est visible pour un viewer donné
     */
    public function isProfileVisibleFor(User $profileOwner, ?User $viewer): bool
    {
        $settings = $profileOwner->getSettings();

        if (!$settings) {
            return true; // Par défaut visible si pas de settings
        }

        return $settings->isProfileVisibleFor($viewer);
    }

    /**
     * Vérifie si un voyage est visible pour un viewer donné
     */
    public function isVoyageVisibleFor(Voyage $voyage, ?User $viewer): bool
    {
        $voyageur = $voyage->getVoyageur();

        // Le propriétaire voit toujours son propre voyage
        if ($viewer && $voyageur === $viewer) {
            return true;
        }

        // Vérifier si le voyageur est visible dans les recherches
        if (!$this->isUserVisibleInSearch($voyageur)) {
            return false;
        }

        // Vérifier la visibilité du profil
        return $this->isProfileVisibleFor($voyageur, $viewer);
    }

    /**
     * Vérifie si une demande est visible pour un viewer donné
     */
    public function isDemandeVisibleFor(Demande $demande, ?User $viewer): bool
    {
        $client = $demande->getClient();

        // Le propriétaire voit toujours sa propre demande
        if ($viewer && $client === $viewer) {
            return true;
        }

        // Vérifier si le client est visible dans les recherches
        if (!$this->isUserVisibleInSearch($client)) {
            return false;
        }

        // Vérifier la visibilité du profil
        return $this->isProfileVisibleFor($client, $viewer);
    }

    /**
     * Filtrer une liste de voyages selon la visibilité
     * @param \App\Entity\Voyage[] $voyages
     * @return \App\Entity\Voyage[]
     */
    public function filterVisibleVoyages(array $voyages, ?User $viewer): array
    {
        return array_filter(
            $voyages,
            fn(Voyage $voyage) => $this->isVoyageVisibleFor($voyage, $viewer)
        );
    }

    /**
     * Filtrer une liste de demandes selon la visibilité
     * @param \App\Entity\Demande[] $demandes
     * @return \App\Entity\Demande[]
     */
    public function filterVisibleDemandes(array $demandes, ?User $viewer): array
    {
        return array_filter(
            $demandes,
            fn(Demande $demande) => $this->isDemandeVisibleFor($demande, $viewer)
        );
    }

    /**
     * Vérifie si un utilisateur peut envoyer un message à un autre
     */
    public function canSendMessageTo(User $sender, User $recipient): bool
    {
        $settings = $recipient->getSettings();

        if (!$settings) {
            return true; // Par défaut autorisé si pas de settings
        }

        return $settings->canReceiveMessageFrom($sender);
    }

    /**
     * Vérifie si les stats d'un utilisateur sont visibles
     */
    public function areStatsVisibleFor(User $profileOwner, ?User $viewer): bool
    {
        $settings = $profileOwner->getSettings();

        if (!$settings) {
            return true; // Par défaut visible
        }

        // Le propriétaire voit toujours ses propres stats
        if ($viewer && $profileOwner === $viewer) {
            return true;
        }

        return $settings->isShowStats();
    }

    /**
     * Vérifie si le téléphone d'un utilisateur est visible
     */
    public function isPhoneVisibleFor(User $profileOwner, ?User $viewer): bool
    {
        $settings = $profileOwner->getSettings();

        if (!$settings) {
            return false; // Par défaut caché
        }

        // Le propriétaire voit toujours son propre téléphone
        if ($viewer && $profileOwner === $viewer) {
            return true;
        }

        return $settings->isShowPhone();
    }

    /**
     * Vérifie si l'email d'un utilisateur est visible
     */
    public function isEmailVisibleFor(User $profileOwner, ?User $viewer): bool
    {
        $settings = $profileOwner->getSettings();

        if (!$settings) {
            return false; // Par défaut caché
        }

        // Le propriétaire voit toujours son propre email
        if ($viewer && $profileOwner === $viewer) {
            return true;
        }

        return $settings->isShowEmail();
    }

    /**
     * Réinjecte email/téléphone dans un tableau déjà normalisé (ex. un voyage/une
     * demande sérialisés sans ces champs), uniquement si les préférences du
     * propriétaire l'autorisent pour ce viewer.
     * @param array<string, mixed> $normalized
     * @return array<string, mixed>
     */
    public function injectContactIfVisible(array $normalized, string $ownerKey, User $owner, ?User $viewer): array
    {
        if ($this->isEmailVisibleFor($owner, $viewer)) {
            $normalized[$ownerKey]['email'] = $owner->getEmail();
        }

        if ($this->isPhoneVisibleFor($owner, $viewer)) {
            $normalized[$ownerKey]['telephone'] = $owner->getTelephone();
        }

        return $normalized;
    }

    /**
     * Injecte le nombre de vues d'une annonce (Lot 6.2) dans un tableau déjà normalisé,
     * uniquement au propriétaire - jamais à un tiers, ce n'est pas une donnée publique.
     * Avantage différenciant des plans payants (SubscriptionPlan::hasViewStats, cf.
     * hasBadge existant) : si le propriétaire n'y a pas droit, injecte un indicateur
     * "verrouillé" (nombreVuesLocked) plutôt que le vrai chiffre, pour permettre un
     * encart d'upsell frontend sans jamais exposer la valeur réelle côté réseau.
     * @param array<string, mixed> $normalized
     * @return array<string, mixed>
     */
    public function injectViewsCountIfEntitled(array $normalized, User $owner, ?User $viewer, int $nombreVues): array
    {
        if ($viewer === null || $owner !== $viewer) {
            return $normalized;
        }

        if ($this->hasViewStatsEntitlement($owner)) {
            $normalized['nombreVues'] = $nombreVues;
        } else {
            $normalized['nombreVuesLocked'] = true;
        }

        return $normalized;
    }

    /**
     * Fail-closed (pas de statistique affichée) plutôt que fail-open : contrairement
     * au quota freemium (VoyageVoter/DemandeVoter::canCreate()), aucune action
     * utilisateur n'est bloquée par cette fonctionnalité d'affichage - une erreur de
     * configuration ne doit jamais faire planter la page de détail en 500.
     */
    private function hasViewStatsEntitlement(User $owner): bool
    {
        try {
            return $this->subscriptionService->getEffectivePlan($owner)->hasViewStats();
        } catch (\RuntimeException) {
            return false;
        }
    }
}
