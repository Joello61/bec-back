<?php

namespace App\Constant;

final class EventType
{
    // ============================================================
    // UTILISATEUR / AUTHENTIFICATION
    // ============================================================
    public const USER_REGISTERED = 'user.registered';
    public const USER_VERIFIED_PHONE = 'user.verified_phone';
    public const USER_PROFILE_UPDATED = 'user.updated';
    public const USER_SETTINGS_UPDATED = 'user.settings.updated';
    public const USER_DELETED = 'user.deleted';
    public const USER_BANNED = 'user.banned';
    public const USER_UNBANNED = 'user.unbanned';
    public const USER_CONNECTED = 'user.connected';
    public const USER_DISCONNECTED = 'user.disconnected';
    public const USER_ROLE_CHANGED = 'user.role.changed';
    public const USER_PASSWORD_RESET = 'user.password.reset';
    public const USER_PASSWORD_FORGOT = 'user.password.forgot';

    // ============================================================
    // DEMANDES D’ENVOI
    // ============================================================
    public const DEMANDE_CREATED = 'demande.created';
    public const DEMANDE_UPDATED = 'demande.updated';
    public const DEMANDE_CANCELLED = 'demande.cancelled';
    public const DEMANDE_EXPIRED = 'demande.expired';
    public const DEMANDE_STATUT_UPDATED = 'demande.statut.updated';
    public const DEMANDE_MATCHED = 'demande.matched';
    public const DEMANDE_FAVORITED = 'demande.favorited';
    public const DEMANDE_UNFAVORITED = 'demande.unfavorited';

    // ============================================================
    // VOYAGES
    // ============================================================
    public const VOYAGE_CREATED = 'voyage.created';
    public const VOYAGE_UPDATED = 'voyage.updated';
    public const VOYAGE_CANCELLED = 'voyage.cancelled';
    public const VOYAGE_COMPLETED = 'voyage.completed';
    public const VOYAGE_EXPIRED = 'voyage.expired';
    public const VOYAGE_FAVORITED = 'voyage.favorited';
    public const VOYAGE_UNFAVORITED = 'voyage.unfavorited';

    // ============================================================
    // PROPOSITIONS
    // ============================================================
    public const PROPOSITION_CREATED = 'proposition.created';
    public const PROPOSITION_ACCEPTED = 'proposition.accepted';
    public const PROPOSITION_REJECTED = 'proposition.rejected';
    public const PROPOSITION_CANCELLED = 'proposition.cancelled';

    // ============================================================
    // MESSAGERIE / CONVERSATIONS
    // ============================================================
    public const CONVERSATION_CREATED = 'conversation.created';
    public const CONVERSATION_DELETED = 'conversation.deleted';
    public const MESSAGE_SENT = 'message.sent';
    public const MESSAGE_READ = 'message.read';
    public const MESSAGE_DELETED = 'message.deleted';

    // ============================================================
    // NOTIFICATIONS
    // ============================================================
    public const NOTIFICATION_NEW = 'notification.new';
    public const NOTIFICATION_READ = 'notification.read';
    public const NOTIFICATION_DELETED = 'notification.deleted';

    // ============================================================
    // FAVORIS
    // ============================================================
    public const FAVORI_ADDED = 'favori.added';
    public const FAVORI_REMOVED = 'favori.removed';

    // ============================================================
    // AVIS / EVALUATIONS
    // ============================================================
    public const AVIS_CREATED = 'avis.created';
    public const AVIS_UPDATED = 'avis.updated';
    public const AVIS_DELETED = 'avis.deleted';

    // ============================================================
    // SIGNALEMENTS / MODÉRATION
    // ============================================================
    public const SIGNALEMENT_CREATED = 'signalement.created';
    public const SIGNALEMENT_HANDLED = 'signalement.handled';
    public const SIGNALEMENT_REJECTED = 'signalement.rejected';
    public const ADMIN_WARNING_SENT = 'admin.warning.sent';

    // ============================================================
    // PARAMÈTRES / SETTINGS
    // ============================================================
    public const SETTINGS_UPDATED = 'settings.updated';
    public const SETTINGS_NOTIFICATIONS_CHANGED = 'settings.notifications.changed';

    // ============================================================
    // CONTACT / SUPPORT
    // ============================================================
    public const CONTACT_FORM_SUBMITTED = 'contact.submitted';
    public const CONTACT_MESSAGE_RESPONDED = 'contact.responded';
    public const CONTACT_MESSAGE_DELETED = 'contact.deleted';

    // ============================================================
    // ADMINISTRATION / DASHBOARD
    // ============================================================
    public const ADMIN_LOGIN = 'admin.login';
    public const ADMIN_USER_BANNED = 'admin.user.banned';
    public const ADMIN_USER_DELETED = 'admin.user.deleted';
    public const ADMIN_ACTION_LOGGED = 'admin.action.logged';
    public const ADMIN_AUDIT_CREATED = 'admin.audit.created';
    public const ADMIN_STATS_UPDATED = 'admin.stats.updated';
    public const ADMIN_NOTIFICATION_SENT = 'admin.notification.sent';
}
