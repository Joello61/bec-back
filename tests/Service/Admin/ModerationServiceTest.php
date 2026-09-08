<?php

declare(strict_types=1);

namespace App\Tests\Service\Admin;

use App\Entity\Avis;
use App\Entity\Demande;
use App\Entity\Message;
use App\Entity\User;
use App\Entity\Voyage;
use App\Repository\AvisRepository;
use App\Repository\DemandeRepository;
use App\Repository\MessageRepository;
use App\Repository\VoyageRepository;
use App\Service\Admin\AuditLogService;
use App\Service\Admin\ModerationService;
use App\Service\NotificationService;
use App\Service\UserService;
use App\Tests\Support\EntityIdTrait;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Phase 4b, Lot 1 (bec-docs/docs/plan-correction/plan-correction-cobage.md) : le service le
 * plus dense en garde-fous de securite/autorisation metier de tout le lot (interdiction
 * d'auto-cible sur ban/suppression/modification de roles, interdiction de cibler un autre
 * admin) - jamais teste jusqu'ici malgre l'usage direct par Admin/AdminUserController et
 * Admin/AdminModerationController (Lot 9).
 */
class ModerationServiceTest extends TestCase
{
    use EntityIdTrait;

    private EntityManagerInterface&\PHPUnit\Framework\MockObject\MockObject $em;
    private VoyageRepository&\PHPUnit\Framework\MockObject\MockObject $voyageRepository;
    private DemandeRepository&\PHPUnit\Framework\MockObject\MockObject $demandeRepository;
    private AvisRepository&\PHPUnit\Framework\MockObject\MockObject $avisRepository;
    private MessageRepository&\PHPUnit\Framework\MockObject\MockObject $messageRepository;
    private NotificationService&\PHPUnit\Framework\MockObject\MockObject $notificationService;
    private AuditLogService&\PHPUnit\Framework\MockObject\MockObject $auditLogService;
    private UserService&\PHPUnit\Framework\MockObject\MockObject $userService;
    private ModerationService $service;

    protected function setUp(): void
    {
        $this->em = $this->createMock(EntityManagerInterface::class);
        $this->voyageRepository = $this->createMock(VoyageRepository::class);
        $this->demandeRepository = $this->createMock(DemandeRepository::class);
        $this->avisRepository = $this->createMock(AvisRepository::class);
        $this->messageRepository = $this->createMock(MessageRepository::class);
        $this->notificationService = $this->createMock(NotificationService::class);
        $this->auditLogService = $this->createMock(AuditLogService::class);
        $this->userService = $this->createMock(UserService::class);

        $this->service = new ModerationService(
            $this->em,
            $this->voyageRepository,
            $this->demandeRepository,
            $this->avisRepository,
            $this->messageRepository,
            $this->notificationService,
            $this->auditLogService,
            $this->userService,
        );
    }

    private function user(int $id, array $roles = []): User
    {
        $user = new User();
        $user->setEmail('mod-' . $id . '-' . uniqid() . '@example.test');
        $user->setNom('Nom');
        $user->setPrenom('Prenom');
        $user->setPassword('irrelevant');
        $user->setRoles($roles);
        $this->setEntityId($user, $id);

        return $user;
    }

    // ==================== banUser ====================

    public function testBanUserBansAndNotifiesAndLogs(): void
    {
        $admin = $this->user(1, ['ROLE_ADMIN']);
        $target = $this->user(2);
        $this->notificationService->expects(self::once())->method('notifyUserBanned')->with($target, $admin, 'spam');
        $this->auditLogService->expects(self::once())->method('logAdminAction')->with($admin, 'ban_user', 'user', 2, self::isArray());
        $this->em->expects(self::once())->method('flush');

        $this->service->banUser($target, $admin, 'spam');

        self::assertTrue($target->isBanned());
    }

    public function testBanUserRejectsSelfTargeting(): void
    {
        $admin = $this->user(1, ['ROLE_ADMIN']);
        $this->em->expects(self::never())->method('flush');

        $this->expectException(\InvalidArgumentException::class);
        $this->service->banUser($admin, $admin, 'raison');
    }

    public function testBanUserRejectsAnotherAdmin(): void
    {
        $admin = $this->user(1, ['ROLE_ADMIN']);
        $otherAdmin = $this->user(2, ['ROLE_ADMIN']);
        $this->em->expects(self::never())->method('flush');

        $this->expectException(\InvalidArgumentException::class);
        $this->service->banUser($otherAdmin, $admin, 'raison');
    }

    // ==================== unbanUser ====================

    public function testUnbanUserUnbansAndNotifiesAndLogs(): void
    {
        $admin = $this->user(1, ['ROLE_ADMIN']);
        $target = $this->user(2);
        $target->ban($admin, 'ancienne raison');
        $this->notificationService->expects(self::once())->method('createNotification');
        $this->auditLogService->expects(self::once())->method('logAdminAction')->with($admin, 'unban_user', 'user', 2, self::isArray());

        $this->service->unbanUser($target, $admin);

        self::assertFalse($target->isBanned());
    }

    public function testUnbanUserRejectsANonBannedUser(): void
    {
        $admin = $this->user(1, ['ROLE_ADMIN']);
        $target = $this->user(2);

        $this->expectException(\InvalidArgumentException::class);
        $this->service->unbanUser($target, $admin);
    }

    // ==================== updateUserRoles ====================

    public function testUpdateUserRolesAppliesNotifiesAndLogs(): void
    {
        $admin = $this->user(1, ['ROLE_ADMIN']);
        $target = $this->user(2);
        $this->notificationService->expects(self::once())->method('createNotification');
        $this->auditLogService->expects(self::once())->method('logAdminAction')->with($admin, 'update_roles', 'user', 2, self::isArray());

        $this->service->updateUserRoles($target, ['ROLE_USER', 'ROLE_MODERATOR'], $admin);

        self::assertSame(['ROLE_USER', 'ROLE_MODERATOR'], $target->getRoles());
    }

    public function testUpdateUserRolesRejectsSelfModification(): void
    {
        $admin = $this->user(1, ['ROLE_ADMIN']);

        $this->expectException(\InvalidArgumentException::class);
        $this->service->updateUserRoles($admin, ['ROLE_USER'], $admin);
    }

    public function testUpdateUserRolesRejectsAnInvalidRole(): void
    {
        $admin = $this->user(1, ['ROLE_ADMIN']);
        $target = $this->user(2);

        $this->expectException(\InvalidArgumentException::class);
        $this->service->updateUserRoles($target, ['ROLE_SUPERUSER'], $admin);
    }

    // ==================== deleteUser ====================

    public function testDeleteUserLogsThenAnonymizesInsteadOfRemoving(): void
    {
        $admin = $this->user(1, ['ROLE_ADMIN']);
        $target = $this->user(2);
        $this->auditLogService->expects(self::once())->method('logAdminAction')->with($admin, 'delete_user', 'user', 2, self::isArray());
        $this->userService->expects(self::once())->method('anonymizeAndSoftDelete')->with($target);
        $this->em->expects(self::never())->method('remove');

        $this->service->deleteUser($target, $admin, 'RGPD');
    }

    public function testDeleteUserRejectsSelfDeletion(): void
    {
        $admin = $this->user(1, ['ROLE_ADMIN']);
        $this->userService->expects(self::never())->method('anonymizeAndSoftDelete');

        $this->expectException(\InvalidArgumentException::class);
        $this->service->deleteUser($admin, $admin, 'raison');
    }

    public function testDeleteUserRejectsAnotherAdmin(): void
    {
        $admin = $this->user(1, ['ROLE_ADMIN']);
        $otherAdmin = $this->user(2, ['ROLE_ADMIN']);
        $this->userService->expects(self::never())->method('anonymizeAndSoftDelete');

        $this->expectException(\InvalidArgumentException::class);
        $this->service->deleteUser($otherAdmin, $admin, 'raison');
    }

    // ==================== deleteVoyage ====================

    public function testDeleteVoyageNotifiesLogsAndRemoves(): void
    {
        $admin = $this->user(1, ['ROLE_ADMIN']);
        $voyageur = $this->user(2);
        $voyage = new Voyage();
        $voyage->setVoyageur($voyageur);
        $voyage->setVilleDepart('Douala');
        $voyage->setVilleArrivee('Paris');
        $voyage->setDateDepart(new \DateTime('+5 days'));
        $this->setEntityId($voyage, 10);
        $this->voyageRepository->method('find')->with(10)->willReturn($voyage);
        $this->notificationService->expects(self::once())->method('createNotification');
        $this->auditLogService->expects(self::once())->method('logAdminAction');
        $this->em->expects(self::once())->method('remove')->with($voyage);

        $this->service->deleteVoyage(10, $admin, 'contenu inapproprie');
    }

    public function testDeleteVoyageThrowsWhenNotFound(): void
    {
        $this->voyageRepository->method('find')->willReturn(null);

        $this->expectException(NotFoundHttpException::class);
        $this->service->deleteVoyage(999, $this->user(1, ['ROLE_ADMIN']), 'raison');
    }

    public function testDeleteVoyageSkipsNotificationWhenNotifyUserIsFalse(): void
    {
        $voyageur = $this->user(2);
        $voyage = new Voyage();
        $voyage->setVoyageur($voyageur);
        $voyage->setVilleDepart('Douala');
        $voyage->setVilleArrivee('Paris');
        $voyage->setDateDepart(new \DateTime('+5 days'));
        $this->setEntityId($voyage, 11);
        $this->voyageRepository->method('find')->with(11)->willReturn($voyage);
        $this->notificationService->expects(self::never())->method('createNotification');

        $this->service->deleteVoyage(11, $this->user(1, ['ROLE_ADMIN']), 'raison', notifyUser: false);
    }

    // ==================== deleteDemande ====================

    public function testDeleteDemandeNotifiesLogsAndRemoves(): void
    {
        $admin = $this->user(1, ['ROLE_ADMIN']);
        $client = $this->user(2);
        $demande = new Demande();
        $demande->setClient($client);
        $demande->setVilleDepart('Douala');
        $demande->setVilleArrivee('Paris');
        $this->setEntityId($demande, 20);
        $this->demandeRepository->method('find')->with(20)->willReturn($demande);
        $this->notificationService->expects(self::once())->method('createNotification');
        $this->em->expects(self::once())->method('remove')->with($demande);

        $this->service->deleteDemande(20, $admin, 'raison');
    }

    public function testDeleteDemandeThrowsWhenNotFound(): void
    {
        $this->demandeRepository->method('find')->willReturn(null);

        $this->expectException(NotFoundHttpException::class);
        $this->service->deleteDemande(999, $this->user(1, ['ROLE_ADMIN']), 'raison');
    }

    // ==================== deleteAvis ====================

    public function testDeleteAvisNotifiesLogsAndRemoves(): void
    {
        $admin = $this->user(1, ['ROLE_ADMIN']);
        $auteur = $this->user(2);
        $cible = $this->user(3);
        $avis = new Avis();
        $avis->setAuteur($auteur);
        $avis->setCible($cible);
        $avis->setNote(2);
        $this->setEntityId($avis, 30);
        $this->avisRepository->method('find')->with(30)->willReturn($avis);
        $this->notificationService->expects(self::once())->method('createNotification');
        $this->em->expects(self::once())->method('remove')->with($avis);

        $this->service->deleteAvis(30, $admin, 'contenu offensant');
    }

    public function testDeleteAvisThrowsWhenNotFound(): void
    {
        $this->avisRepository->method('find')->willReturn(null);

        $this->expectException(NotFoundHttpException::class);
        $this->service->deleteAvis(999, $this->user(1, ['ROLE_ADMIN']), 'raison');
    }

    // ==================== deleteMessage ====================

    public function testDeleteMessageNotifiesLogsAndRemoves(): void
    {
        $admin = $this->user(1, ['ROLE_ADMIN']);
        $sender = $this->user(2);
        $recipient = $this->user(3);
        $message = new Message();
        $message->setExpediteur($sender);
        $message->setDestinataire($recipient);
        $message->setContenu('contenu du message');
        $this->setEntityId($message, 40);
        $this->messageRepository->method('find')->with(40)->willReturn($message);
        $this->notificationService->expects(self::once())->method('createNotification');
        $this->em->expects(self::once())->method('remove')->with($message);

        $this->service->deleteMessage(40, $admin, 'contenu inapproprie');
    }

    public function testDeleteMessageThrowsWhenNotFound(): void
    {
        $this->messageRepository->method('find')->willReturn(null);

        $this->expectException(NotFoundHttpException::class);
        $this->service->deleteMessage(999, $this->user(1, ['ROLE_ADMIN']), 'raison');
    }

    // ==================== deleteAllUserContent ====================

    public function testDeleteAllUserContentCascadesAndCountsStats(): void
    {
        $admin = $this->user(1, ['ROLE_ADMIN']);
        $target = $this->user(2);

        $voyage = new Voyage();
        $voyage->setVoyageur($target);
        $voyage->setVilleDepart('Douala');
        $voyage->setVilleArrivee('Paris');
        $voyage->setDateDepart(new \DateTime('+5 days'));
        $this->setEntityId($voyage, 50);
        $target->getVoyages()->add($voyage);
        $this->voyageRepository->method('find')->with(50)->willReturn($voyage);

        $demande = new Demande();
        $demande->setClient($target);
        $demande->setVilleDepart('Douala');
        $demande->setVilleArrivee('Paris');
        $this->setEntityId($demande, 51);
        $target->getDemandes()->add($demande);
        $this->demandeRepository->method('find')->with(51)->willReturn($demande);

        $avis = new Avis();
        $avis->setAuteur($target);
        $avis->setCible($admin);
        $avis->setNote(3);
        $this->setEntityId($avis, 52);
        $target->getAvisDonnes()->add($avis);
        $this->avisRepository->method('find')->with(52)->willReturn($avis);

        $message = new Message();
        $message->setExpediteur($target);
        $message->setDestinataire($admin);
        $message->setContenu('salut');
        $this->setEntityId($message, 53);
        $target->getMessagesEnvoyes()->add($message);
        $this->messageRepository->method('find')->with(53)->willReturn($message);

        // notifyUser=false pour chaque suppression individuelle en cascade
        $this->notificationService->expects(self::never())->method('createNotification');
        $this->auditLogService->expects(self::exactly(5))->method('logAdminAction'); // 4 suppressions + 1 log global

        $stats = $this->service->deleteAllUserContent($target, $admin, 'RGPD - suppression de compte');

        self::assertSame(['voyages' => 1, 'demandes' => 1, 'avis' => 1, 'messages' => 1], $stats);
    }

    public function testDeleteAllUserContentIsANoopWithoutAnyContent(): void
    {
        $admin = $this->user(1, ['ROLE_ADMIN']);
        $target = $this->user(2);
        $this->em->expects(self::never())->method('remove');
        $this->auditLogService->expects(self::once())->method('logAdminAction');

        $stats = $this->service->deleteAllUserContent($target, $admin, 'raison');

        self::assertSame(['voyages' => 0, 'demandes' => 0, 'avis' => 0, 'messages' => 0], $stats);
    }
}
