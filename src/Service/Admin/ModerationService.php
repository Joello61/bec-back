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
    ) {}

    // ==================== GESTION DES UTILISATEURS ====================

    /**
     * Bannir un utilisateur
     */
    public function banUser(User $user, User $admin, string $reason): void
    {
        if ($user->getId() === $admin->getId()) {
            throw new \InvalidArgumentException('Un administrateur ne peut pas se bannir lui-même');
        }

        if (in_array('ROLE_ADMIN', $user->getRoles())) {
            throw new \InvalidArgumentException('Impossible de bannir un autre administrateur');
        }

        // Bannir l'utilisateur
        $user->ban($admin, $reason);

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
            ]
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

        // Logger AVANT de supprimer
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

        // Supprimer l'utilisateur (cascade supprimera les relations)
        $this->entityManager->remove($user);
        $this->entityManager->flush();
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
