<?php

declare(strict_types=1);

namespace App\Security\Voter;

use App\Entity\User;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

class AdminVoter extends Voter
{
    // Permissions admin
    public const VIEW_DASHBOARD = 'ADMIN_VIEW_DASHBOARD';
    public const VIEW_STATS = 'ADMIN_VIEW_STATS';
    public const VIEW_LOGS = 'ADMIN_VIEW_LOGS';
    public const EXPORT_LOGS = 'ADMIN_EXPORT_LOGS';

    // Permissions utilisateurs
    public const VIEW_ALL_USERS = 'ADMIN_VIEW_ALL_USERS';
    public const BAN_USER = 'ADMIN_BAN_USER';
    public const UNBAN_USER = 'ADMIN_UNBAN_USER';
    public const DELETE_USER = 'ADMIN_DELETE_USER';
    public const MANAGE_ROLES = 'ADMIN_MANAGE_ROLES';

    // Permissions modération
    public const DELETE_CONTENT = 'ADMIN_DELETE_CONTENT';
    public const DELETE_VOYAGE = 'ADMIN_DELETE_VOYAGE';
    public const DELETE_DEMANDE = 'ADMIN_DELETE_DEMANDE';
    public const DELETE_AVIS = 'ADMIN_DELETE_AVIS';
    public const DELETE_MESSAGE = 'ADMIN_DELETE_MESSAGE';

    // Permissions signalements
    public const MANAGE_SIGNALEMENTS = 'ADMIN_MANAGE_SIGNALEMENTS';

    protected function supports(string $attribute, mixed $subject): bool
    {
        return in_array($attribute, [
            self::VIEW_DASHBOARD,
            self::VIEW_STATS,
            self::VIEW_LOGS,
            self::EXPORT_LOGS,
            self::VIEW_ALL_USERS,
            self::BAN_USER,
            self::UNBAN_USER,
            self::DELETE_USER,
            self::MANAGE_ROLES,
            self::DELETE_CONTENT,
            self::DELETE_VOYAGE,
            self::DELETE_DEMANDE,
            self::DELETE_AVIS,
            self::DELETE_MESSAGE,
            self::MANAGE_SIGNALEMENTS,
        ]);
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token): bool
    {
        $user = $token->getUser();

        // L'utilisateur doit être connecté
        if (!$user instanceof User) {
            return false;
        }

        // Vérifier que l'utilisateur n'est pas banni
        if ($user->isBanned()) {
            return false;
        }

        // Les permissions admin
        return match($attribute) {
            // Dashboard et statistiques : ROLE_ADMIN ou ROLE_MODERATOR
            self::VIEW_DASHBOARD,
            self::VIEW_STATS => $this->canViewDashboard($user),

            // Logs : ROLE_ADMIN uniquement
            self::VIEW_LOGS,
            self::EXPORT_LOGS => $this->canViewLogs($user),

            // Gestion utilisateurs : ROLE_ADMIN uniquement
            self::VIEW_ALL_USERS,
            self::BAN_USER,
            self::UNBAN_USER,
            self::DELETE_USER,
            self::MANAGE_ROLES => $this->canManageUsers($user),

            // Modération contenus : ROLE_ADMIN ou ROLE_MODERATOR
            self::DELETE_CONTENT,
            self::DELETE_VOYAGE,
            self::DELETE_DEMANDE,
            self::DELETE_AVIS,
            self::DELETE_MESSAGE => $this->canModerateContent($user),

            // Signalements : ROLE_ADMIN ou ROLE_MODERATOR
            self::MANAGE_SIGNALEMENTS => $this->canManageSignalements($user),

            default => false,
        };
    }

    /**
     * Peut voir le dashboard (admin + moderator)
     */
    private function canViewDashboard(User $user): bool
    {
        return in_array('ROLE_ADMIN', $user->getRoles())
            || in_array('ROLE_MODERATOR', $user->getRoles());
    }

    /**
     * Peut voir les logs (admin uniquement)
     */
    private function canViewLogs(User $user): bool
    {
        return in_array('ROLE_ADMIN', $user->getRoles());
    }

    /**
     * Peut gérer les utilisateurs (admin uniquement)
     */
    private function canManageUsers(User $user): bool
    {
        return in_array('ROLE_ADMIN', $user->getRoles());
    }

    /**
     * Peut modérer les contenus (admin + moderator)
     */
    private function canModerateContent(User $user): bool
    {
        return in_array('ROLE_ADMIN', $user->getRoles())
            || in_array('ROLE_MODERATOR', $user->getRoles());
    }

    /**
     * Peut gérer les signalements (admin + moderator)
     */
    private function canManageSignalements(User $user): bool
    {
        return in_array('ROLE_ADMIN', $user->getRoles())
            || in_array('ROLE_MODERATOR', $user->getRoles());
    }

    /**
     * Vérifie si un utilisateur peut bannir un autre utilisateur
     * Un admin ne peut pas bannir un autre admin
     */
    public function canBanSpecificUser(User $admin, User $targetUser): bool
    {
        // Ne peut pas se bannir soi-même
        if ($admin->getId() === $targetUser->getId()) {
            return false;
        }

        // Un admin ne peut pas bannir un autre admin
        if (in_array('ROLE_ADMIN', $targetUser->getRoles())) {
            return false;
        }

        // Doit être admin pour bannir
        return in_array('ROLE_ADMIN', $admin->getRoles());
    }

    /**
     * Vérifie si un utilisateur peut modifier les rôles d'un autre utilisateur
     */
    public function canEditRolesOfUser(User $admin, User $targetUser): bool
    {
        // Ne peut pas modifier ses propres rôles
        if ($admin->getId() === $targetUser->getId()) {
            return false;
        }

        // Doit être admin
        return in_array('ROLE_ADMIN', $admin->getRoles());
    }

    /**
     * Vérifie si un utilisateur peut supprimer un autre utilisateur
     */
    public function canDeleteSpecificUser(User $admin, User $targetUser): bool
    {
        // Ne peut pas se supprimer soi-même
        if ($admin->getId() === $targetUser->getId()) {
            return false;
        }

        // Un admin ne peut pas supprimer un autre admin
        if (in_array('ROLE_ADMIN', $targetUser->getRoles())) {
            return false;
        }

        // Doit être admin pour supprimer
        return in_array('ROLE_ADMIN', $admin->getRoles());
    }
}
