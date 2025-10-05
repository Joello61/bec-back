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
}
