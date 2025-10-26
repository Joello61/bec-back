<?php

namespace App\Service;

use App\Entity\User;

class TopicBuilder
{
    /**
     * Base commune de tous les topics Mercure.
     */
    private string $baseUrl;

    public function __construct(string $baseDomain = 'https://cobage.local')
    {
        $this->baseUrl = rtrim($baseDomain, '/');
    }

    /** Canal privé pour un utilisateur spécifique */
    public function forUser(User $user): string
    {
        return sprintf('%s/users/%d', $this->baseUrl, $user->getId());
    }

    /** Canal de groupe (ex: admin, moderator, etc.) */
    public function forGroup(string $group): string
    {
        return sprintf('%s/groups/%s', $this->baseUrl, strtolower($group));
    }

    public function forPublic(): string
    {
        return "{$this->baseUrl}/public";
    }

    public function forDemandes(): string
    {
        return "{$this->baseUrl}/topics/demandes";
    }

    public function forVoyages(): string
    {
        return "{$this->baseUrl}/topics/voyages";
    }

    /** Canal système global (maintenance, annonces, etc.) */
    public function forSystem(): string
    {
        return sprintf('%s/system', $this->baseUrl);
    }
}
