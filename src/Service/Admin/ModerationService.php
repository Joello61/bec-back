<?php

declare(strict_types=1);

namespace App\Service\Admin;

use App\Entity\User;
use App\Repository\AvisRepository;
use App\Repository\DemandeRepository;
use App\Repository\MessageRepository;
use App\Repository\UserRepository;
use App\Repository\VoyageRepository;
use App\Service\NotificationService;
use App\Service\UserService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

readonly class ModerationService
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private VoyageRepository $voyageRepository,
        private DemandeRepository $demandeRepository,
        private AvisRepository $avisRepository,
        private MessageRepository $messageRepository,
        private NotificationService $notificationService,
        private AuditLogService $auditLogService,
        private UserService $userService,
        private UserRepository $userRepository,
    ) {}

    // ==================== GESTION DES UTILISATEURS ====================

    /**
     * Bannir un utilisateur
     */
    public function banUser(User $user, User $admin, string $reason, ?\DateTimeInterface $bannedUntil = null): void
    {
        if ($user->getId() === $admin->getId()) {
            throw new \InvalidArgumentException('Un administrateur ne peut pas se bannir lui-même');
        }

        if (in_array('ROLE_ADMIN', $user->getRoles())) {
            throw new \InvalidArgumentException('Impossible de bannir un autre administrateur');
        }

        // Bannir l'utilisateur
        $user->ban($admin, $reason, $bannedUntil);

        $this->entityManager->flush();

        // Notifier l'utilisateur
        $this->notificationService->notifyUserBanned($user, $admin, $reason);

        // Logger l'action
        $this->auditLogService->logAdminAction(
            $admin,
            'ban_user',
            'user',
            $user->getId(),
            [
                'reason' => $reason,
                'email' => $user->getEmail(),
                'nom' => $user->getNom() . ' ' . $user->getPrenom(),
                'bannedUntil' => $bannedUntil?->format(\DateTimeInterface::ATOM),
            ]
        );
    }

    /**
     * Leve un bannissement temporaire arrive a echeance. Declenche par
     * ExpireBansHandler (planifie), jamais par un admin - BannedUserListener donne deja
     * l'effet immediat au niveau de la requete (User::isBanExpired()), cette methode ne
     * fait que nettoyer isBanned en base. Pas d'acteur admin distinct pour un evenement
     * systeme : l'utilisateur est journalise comme acteur de sa propre reactivation, sur
     * le meme patron que PromoteAdminCommand/RevokeAdminCommand.
     */
    public function expireBan(User $user): void
    {
        if (!$user->isBanned() || !$user->isBanExpired()) {
            return;
        }

        $user->unban();
        $this->entityManager->flush();

        $this->notificationService->createNotification(
            $user,
            'account_unbanned',
            'Compte réactivé',
            'Votre bannissement temporaire est arrivé à échéance. Vous pouvez à nouveau accéder à toutes les fonctionnalités.',
        );

        $this->auditLogService->logAdminAction(
            $user,
            'ban_expired',
            'user',
            $user->getId(),
            ['email' => $user->getEmail()]
        );
    }

    /**
     * Débannir un utilisateur
     */
    public function unbanUser(User $user, User $admin): void
    {
        if (!$user->isBanned()) {
            throw new \InvalidArgumentException('Cet utilisateur n\'est pas banni');
        }

        $oldReason = $user->getBanReason();

        // Débannir l'utilisateur
        $user->unban();

        $this->entityManager->flush();

        // Notifier l'utilisateur
        $this->notificationService->createNotification(
            $user,
            'account_unbanned',
            'Compte réactivé',
            'Votre compte a été réactivé. Vous pouvez à nouveau accéder à toutes les fonctionnalités.',
        );

        // Logger l'action
        $this->auditLogService->logAdminAction(
            $admin,
            'unban_user',
            'user',
            $user->getId(),
            [
                'previousReason' => $oldReason,
                'email' => $user->getEmail(),
                'nom' => $user->getNom() . ' ' . $user->getPrenom(),
            ]
        );
    }

    /**
     * Modifier les rôles d'un utilisateur
     * @param list<string> $roles
     */
    public function updateUserRoles(User $user, array $roles, User $admin): void
    {
        if ($user->getId() === $admin->getId()) {
            throw new \InvalidArgumentException('Un administrateur ne peut pas modifier ses propres rôles');
        }

        $oldRoles = $user->getRoles();

        // Validation des rôles
        $validRoles = ['ROLE_USER', 'ROLE_MODERATOR', 'ROLE_ADMIN'];
        foreach ($roles as $role) {
            if (!in_array($role, $validRoles)) {
                throw new \InvalidArgumentException("Rôle invalide : {$role}");
            }
        }

        // Garde-fou explicite : retirer ROLE_ADMIN au dernier administrateur du systeme est
        // deja impossible en pratique via cette methode (un admin ne peut pas modifier ses
        // propres roles, cf. plus haut - il faudrait donc qu'un admin retire ce role a un
        // AUTRE admin qui serait pourtant le dernier, ce qui suppose deja 2+ admins). Rendu
        // explicite plutot que de reposer sur cet effet de bord non intentionnel.
        if (in_array('ROLE_ADMIN', $oldRoles, true)
            && !in_array('ROLE_ADMIN', $roles, true)
            && $this->userRepository->countByRole('ROLE_ADMIN') <= 1
        ) {
            throw new \InvalidArgumentException('Impossible de retirer le rôle administrateur du dernier administrateur du système');
        }

        $user->setRoles($roles);

        $this->entityManager->flush();

        // Notifier l'utilisateur
        $this->notificationService->createNotification(
            $user,
            'roles_updated',
            'Rôles modifiés',
            'Vos rôles ont été modifiés par un administrateur.',
            ['newRoles' => $roles]
        );

        // Logger l'action
        $this->auditLogService->logAdminAction(
            $admin,
            'update_roles',
            'user',
            $user->getId(),
            [
                'oldRoles' => $oldRoles,
                'newRoles' => $roles,
                'email' => $user->getEmail(),
            ]
        );
    }

    /**
     * Supprimer un utilisateur (RGPD)
     *
     * Soft-delete/anonymisation (Phase 5 du plan de correction) : le compte n'est jamais
     * physiquement supprime, pour ne pas casser l'historique des messages/avis le concernant
     * du point de vue des tiers (audit Backend-Qualite #2).
     */
    public function deleteUser(User $user, User $admin, string $reason): void
    {
        if ($user->getId() === $admin->getId()) {
            throw new \InvalidArgumentException('Un administrateur ne peut pas se supprimer lui-même');
        }

        if (in_array('ROLE_ADMIN', $user->getRoles())) {
            throw new \InvalidArgumentException('Impossible de supprimer un autre administrateur');
        }

        $userId = $user->getId();
        $userEmail = $user->getEmail();
        $userName = $user->getNom() . ' ' . $user->getPrenom();

        // Logger AVANT l'anonymisation (email/nom reels encore disponibles)
        $this->auditLogService->logAdminAction(
            $admin,
            'delete_user',
            'user',
            $userId,
            [
                'reason' => $reason,
                'email' => $userEmail,
                'nom' => $userName,
                'voyagesCount' => $user->getVoyages()->count(),
                'demandesCount' => $user->getDemandes()->count(),
            ]
        );

        $this->userService->anonymizeAndSoftDelete($user);
    }

    // ==================== GESTION DES CONTENUS ====================

    /**
     * Supprimer un voyage
     */
    public function deleteVoyage(int $voyageId, User $admin, string $reason, bool $notifyUser = true): void
    {
        $voyage = $this->voyageRepository->find($voyageId);

        if (!$voyage) {
            throw new NotFoundHttpException('Voyage non trouvé');
        }

        $voyageur = $voyage->getVoyageur();
        $voyageData = [
            'id' => $voyage->getId(),
            'villeDepart' => $voyage->getVilleDepart(),
            'villeArrivee' => $voyage->getVilleArrivee(),
            'dateDepart' => $voyage->getDateDepart()->format('Y-m-d'),
        ];

        // Notifier le voyageur
        if ($notifyUser) {
            $this->notificationService->createNotification(
                $voyageur,
                'content_deleted',
                'Voyage supprimé',
                sprintf(
                    'Votre voyage %s vers %s a été supprimé par la modération. Raison : %s',
                    $voyage->getVilleDepart(),
                    $voyage->getVilleArrivee(),
                    $reason
                ),
                ['type' => 'voyage', 'voyageId' => $voyageId]
            );
        }

        // Logger l'action
        $this->auditLogService->logAdminAction(
            $admin,
            'delete_voyage',
            'voyage',
            $voyageId,
            [
                'reason' => $reason,
                'voyageur' => $voyageur->getEmail(),
                'voyage' => $voyageData,
            ]
        );

        // Supprimer le voyage
        $this->entityManager->remove($voyage);
        $this->entityManager->flush();
    }

    /**
     * Supprimer une demande
     */
    public function deleteDemande(int $demandeId, User $admin, string $reason, bool $notifyUser = true): void
    {
        $demande = $this->demandeRepository->find($demandeId);

        if (!$demande) {
            throw new NotFoundHttpException('Demande non trouvée');
        }

        $client = $demande->getClient();
        $demandeData = [
            'id' => $demande->getId(),
            'villeDepart' => $demande->getVilleDepart(),
            'villeArrivee' => $demande->getVilleArrivee(),
        ];

        // Notifier le client
        if ($notifyUser) {
            $this->notificationService->createNotification(
                $client,
                'content_deleted',
                'Demande supprimée',
                sprintf(
                    'Votre demande %s vers %s a été supprimée par la modération. Raison : %s',
                    $demande->getVilleDepart(),
                    $demande->getVilleArrivee(),
                    $reason
                ),
                ['type' => 'demande', 'demandeId' => $demandeId]
            );
        }

        // Logger l'action
        $this->auditLogService->logAdminAction(
            $admin,
            'delete_demande',
            'demande',
            $demandeId,
            [
                'reason' => $reason,
                'client' => $client->getEmail(),
                'demande' => $demandeData,
            ]
        );

        // Supprimer la demande
        $this->entityManager->remove($demande);
        $this->entityManager->flush();
    }

    /**
     * Supprimer un avis
     */
    public function deleteAvis(int $avisId, User $admin, string $reason, bool $notifyUser = true): void
    {
        $avis = $this->avisRepository->find($avisId);

        if (!$avis) {
            throw new NotFoundHttpException('Avis non trouvé');
        }

        $auteur = $avis->getAuteur();
        $cible = $avis->getCible();

        // Notifier l'auteur
        if ($notifyUser) {
            $this->notificationService->createNotification(
                $auteur,
                'content_deleted',
                'Avis supprimé',
                sprintf(
                    'Votre avis a été supprimé par la modération. Raison : %s',
                    $reason
                ),
                ['type' => 'avis', 'avisId' => $avisId]
            );
        }

        // Logger l'action
        $this->auditLogService->logAdminAction(
            $admin,
            'delete_avis',
            'avis',
            $avisId,
            [
                'reason' => $reason,
                'auteur' => $auteur->getEmail(),
                'cible' => $cible->getEmail(),
                'note' => $avis->getNote(),
                'commentaire' => mb_substr($avis->getCommentaire() ?? '', 0, 100),
            ]
        );

        // Supprimer l'avis
        $this->entityManager->remove($avis);
        $this->entityManager->flush();
    }

    /**
     * Supprimer un message
     */
    public function deleteMessage(int $messageId, User $admin, string $reason, bool $notifyUser = true): void
    {
        $message = $this->messageRepository->find($messageId);

        if (!$message) {
            throw new NotFoundHttpException('Message non trouvé');
        }

        $expediteur = $message->getExpediteur();
        $destinataire = $message->getDestinataire();

        // Notifier l'expéditeur
        if ($notifyUser) {
            $this->notificationService->createNotification(
                $expediteur,
                'content_deleted',
                'Message supprimé',
                sprintf(
                    'Un de vos messages a été supprimé par la modération. Raison : %s',
                    $reason
                ),
                ['type' => 'message', 'messageId' => $messageId]
            );
        }

        // Logger l'action
        $this->auditLogService->logAdminAction(
            $admin,
            'delete_message',
            'message',
            $messageId,
            [
                'reason' => $reason,
                'expediteur' => $expediteur->getEmail(),
                'destinataire' => $destinataire->getEmail(),
                'contenu' => mb_substr($message->getContenu(), 0, 100),
            ]
        );

        // Supprimer le message
        $this->entityManager->remove($message);
        $this->entityManager->flush();
    }

    // ==================== ACTIONS EN MASSE ====================

    /**
     * Supprimer tous les contenus d'un utilisateur
     * @return array<string, int>
     */
    public function deleteAllUserContent(User $user, User $admin, string $reason): array
    {
        $stats = [
            'voyages' => 0,
            'demandes' => 0,
            'avis' => 0,
            'messages' => 0,
        ];

        // Supprimer les voyages
        foreach ($user->getVoyages() as $voyage) {
            $this->deleteVoyage($voyage->getId(), $admin, $reason, false);
            $stats['voyages']++;
        }

        // Supprimer les demandes
        foreach ($user->getDemandes() as $demande) {
            $this->deleteDemande($demande->getId(), $admin, $reason, false);
            $stats['demandes']++;
        }

        // Supprimer les avis donnés
        foreach ($user->getAvisDonnes() as $avis) {
            $this->deleteAvis($avis->getId(), $admin, $reason, false);
            $stats['avis']++;
        }

        // Supprimer les messages envoyés
        foreach ($user->getMessagesEnvoyes() as $message) {
            $this->deleteMessage($message->getId(), $admin, $reason, false);
            $stats['messages']++;
        }

        // Logger l'action globale
        $this->auditLogService->logAdminAction(
            $admin,
            'delete_all_user_content',
            'user',
            $user->getId(),
            [
                'reason' => $reason,
                'email' => $user->getEmail(),
                'stats' => $stats,
            ]
        );

        return $stats;
    }
}
