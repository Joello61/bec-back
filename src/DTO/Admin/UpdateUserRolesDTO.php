<?php

declare(strict_types=1);

namespace App\DTO\Admin;

use Symfony\Component\Validator\Constraints as Assert;

class UpdateUserRolesDTO
{
    /**
     * Liste des rôles à assigner à l'utilisateur
     *
     * Rôles disponibles :
     * - ROLE_USER : Utilisateur standard (obligatoire, ajouté automatiquement)
     * - ROLE_MODERATOR : Modérateur avec accès limité à l'admin
     * - ROLE_ADMIN : Administrateur avec tous les droits
     */
    #[Assert\NotBlank(message: 'Au moins un rôle doit être spécifié')]
    #[Assert\Type(
        type: 'array',
        message: 'Les rôles doivent être fournis sous forme de tableau'
    )]
    #[Assert\Count(
        min: 1,
        max: 10,
        minMessage: 'Au moins {{ limit }} rôle doit être spécifié',
        maxMessage: 'Vous ne pouvez pas attribuer plus de {{ limit }} rôles'
    )]
    #[Assert\Choice(
        choices: ['ROLE_USER', 'ROLE_MODERATOR', 'ROLE_ADMIN'],
        multiple: true,
        message: 'Le rôle "{{ value }}" n\'est pas valide. Rôles acceptés : {{ choices }}'
    )]
    public array $roles;

    /**
     * Raison du changement de rôles (optionnel mais recommandé)
     */
    #[Assert\Length(
        max: 500,
        maxMessage: 'La raison ne peut pas dépasser {{ limit }} caractères'
    )]
    public ?string $reason = null;

    /**
     * Notifier l'utilisateur du changement de rôles
     */
    #[Assert\Type(type: 'bool')]
    public bool $notifyUser = true;

    /**
     * Vérifie si le rôle ADMIN est présent
     */
    public function hasAdminRole(): bool
    {
        return in_array('ROLE_ADMIN', $this->roles, true);
    }

    /**
     * Vérifie si le rôle MODERATOR est présent
     */
    public function hasModeratorRole(): bool
    {
        return in_array('ROLE_MODERATOR', $this->roles, true);
    }

    /**
     * Vérifie si seulement ROLE_USER est présent (utilisateur standard)
     */
    public function isStandardUser(): bool
    {
        return count($this->roles) === 1 && $this->roles[0] === 'ROLE_USER';
    }

    /**
     * Ajoute ROLE_USER automatiquement si absent
     */
    public function ensureUserRole(): void
    {
        if (!in_array('ROLE_USER', $this->roles, true)) {
            $this->roles[] = 'ROLE_USER';
        }
    }

    /**
     * Supprime les doublons et trie les rôles
     */
    public function normalizeRoles(): void
    {
        $this->roles = array_values(array_unique($this->roles));
        sort($this->roles);
    }
}
