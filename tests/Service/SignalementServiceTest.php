<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\DTO\CreateSignalementDTO;
use App\Entity\Demande;
use App\Entity\Message;
use App\Entity\Signalement;
use App\Entity\User;
use App\Entity\Voyage;
use App\Repository\DemandeRepository;
use App\Repository\MessageRepository;
use App\Repository\SignalementRepository;
use App\Repository\UserRepository;
use App\Repository\VoyageRepository;
use App\Service\Admin\AuditLogService;
use App\Service\RealtimeNotifier;
use App\Service\SignalementService;
use App\Tests\Support\EntityIdTrait;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Phase 4b, Lot 6 (bec-docs/docs/plan-correction/plan-correction-cobage.md) : validation
 * "au moins une cible parmi 4 types" (presence disjonctive), interdiction d'auto-
 * signalement, whitelist des statuts de traitement.
 */
class SignalementServiceTest extends TestCase
{
    use EntityIdTrait;

    private EntityManagerInterface&\PHPUnit\Framework\MockObject\MockObject $em;
    private SignalementRepository&\PHPUnit\Framework\MockObject\MockObject $signalementRepository;
    private VoyageRepository&\PHPUnit\Framework\MockObject\MockObject $voyageRepository;
    private DemandeRepository&\PHPUnit\Framework\MockObject\MockObject $demandeRepository;
    private MessageRepository&\PHPUnit\Framework\MockObject\MockObject $messageRepository;
    private UserRepository&\PHPUnit\Framework\MockObject\MockObject $userRepository;
    private RealtimeNotifier&\PHPUnit\Framework\MockObject\MockObject $notifier;
    private AuditLogService&\PHPUnit\Framework\MockObject\MockObject $auditLogService;
    private SignalementService $service;

    protected function setUp(): void
    {
        $this->em = $this->createMock(EntityManagerInterface::class);
        $this->signalementRepository = $this->createMock(SignalementRepository::class);
        $this->voyageRepository = $this->createMock(VoyageRepository::class);
        $this->demandeRepository = $this->createMock(DemandeRepository::class);
        $this->messageRepository = $this->createMock(MessageRepository::class);
        $this->userRepository = $this->createMock(UserRepository::class);
        $this->notifier = $this->createMock(RealtimeNotifier::class);
        $this->auditLogService = $this->createMock(AuditLogService::class);

        $this->service = new SignalementService(
            $this->em,
            $this->signalementRepository,
            $this->voyageRepository,
            $this->demandeRepository,
            $this->messageRepository,
            $this->userRepository,
            $this->notifier,
            new NullLogger(),
            $this->auditLogService,
        );
    }

    private function user(int $id): User
    {
        $user = new User();
        $user->setEmail('user-' . $id . '-' . uniqid() . '@example.test');
        $user->setNom('Nom');
        $user->setPrenom('Prenom');
        $user->setPassword('irrelevant');
        $this->setEntityId($user, $id);

        return $user;
    }

    private function dto(): CreateSignalementDTO
    {
        $dto = new CreateSignalementDTO();
        $dto->motif = 'spam';
        $dto->description = 'description du signalement, assez longue';

        return $dto;
    }

    // ==================== createSignalement ====================

    public function testCreateSignalementRequiresAtLeastOneTarget(): void
    {
        $this->expectException(BadRequestHttpException::class);
        $this->service->createSignalement($this->dto(), $this->user(1));
    }

    public function testCreateSignalementRejectsSelfReporting(): void
    {
        $signaleur = $this->user(1);
        $this->userRepository->method('find')->willReturn($signaleur);
        $dto = $this->dto();
        $dto->utilisateurSignaleId = 1;

        $this->expectException(BadRequestHttpException::class);
        $this->service->createSignalement($dto, $signaleur);
    }

    public function testCreateSignalementTargetingAVoyageSucceeds(): void
    {
        $signaleur = $this->user(1);
        $voyage = new Voyage();
        $this->voyageRepository->method('find')->willReturn($voyage);
        $dto = $this->dto();
        $dto->voyageId = 10;
        $this->em->expects(self::once())->method('persist')->with(self::isInstanceOf(Signalement::class));

        $result = $this->service->createSignalement($dto, $signaleur);

        self::assertSame($voyage, $result->getVoyage());
        self::assertSame('en_attente', $result->getStatut());
    }

    public function testCreateSignalementTargetingADemandeSucceeds(): void
    {
        $signaleur = $this->user(1);
        $demande = new Demande();
        $this->demandeRepository->method('find')->willReturn($demande);
        $dto = $this->dto();
        $dto->demandeId = 10;

        $result = $this->service->createSignalement($dto, $signaleur);

        self::assertSame($demande, $result->getDemande());
    }

    public function testCreateSignalementTargetingAMessageSucceeds(): void
    {
        $signaleur = $this->user(1);
        $message = new Message();
        $this->messageRepository->method('find')->willReturn($message);
        $dto = $this->dto();
        $dto->messageId = 10;

        $result = $this->service->createSignalement($dto, $signaleur);

        self::assertSame($message, $result->getMessage());
    }

    public function testCreateSignalementTargetingAUserSucceeds(): void
    {
        $signaleur = $this->user(1);
        $target = $this->user(2);
        $this->userRepository->method('find')->willReturn($target);
        $dto = $this->dto();
        $dto->utilisateurSignaleId = 2;

        $result = $this->service->createSignalement($dto, $signaleur);

        self::assertSame($target, $result->getUtilisateurSignale());
    }

    // ==================== processSignalement ====================

    public function testProcessSignalementThrowsWhenNotFound(): void
    {
        $this->signalementRepository->method('find')->willReturn(null);

        $this->expectException(NotFoundHttpException::class);
        $this->service->processSignalement(999, 'traite', null, $this->user(99));
    }

    public function testProcessSignalementRejectsAnInvalidStatus(): void
    {
        $signalement = new Signalement();
        $signalement->setSignaleur($this->user(1));
        $this->signalementRepository->method('find')->willReturn($signalement);

        $this->expectException(BadRequestHttpException::class);
        $this->service->processSignalement(1, 'statut-invalide', null, $this->user(99));
    }

    public function testProcessSignalementMarksAsHandled(): void
    {
        $signalement = new Signalement();
        $signalement->setSignaleur($this->user(1));
        $this->setEntityId($signalement, 10);
        $this->signalementRepository->method('find')->willReturn($signalement);
        $this->em->expects(self::once())->method('flush');

        $result = $this->service->processSignalement(1, 'traite', 'resolu', $this->user(99));

        self::assertSame('traite', $result->getStatut());
        self::assertSame('resolu', $result->getReponseAdmin());
    }

    public function testProcessSignalementMarksAsRejected(): void
    {
        $signalement = new Signalement();
        $signalement->setSignaleur($this->user(1));
        $this->setEntityId($signalement, 11);
        $this->signalementRepository->method('find')->willReturn($signalement);

        $result = $this->service->processSignalement(1, 'rejete', 'non fonde', $this->user(99));

        self::assertSame('rejete', $result->getStatut());
    }

    /**
     * Bug de production : traiter un signalement n'ecrivait aucune entree AdminLog,
     * contrairement a ban/unban/delete_* - approve_signalement/reject_signalement
     * n'existaient que comme libelles morts dans AdminLog::getActionLabel() (cf.
     * plan-correction-cobage.md, Phase 13/Lot B2).
     */
    public function testProcessSignalementLogsAnApproveAction(): void
    {
        $signalement = new Signalement();
        $signalement->setSignaleur($this->user(1));
        $signalement->setMotif('spam');
        $this->setEntityId($signalement, 42);
        $this->signalementRepository->method('find')->willReturn($signalement);
        $admin = $this->user(99);

        $this->auditLogService->expects(self::once())
            ->method('logAdminAction')
            ->with($admin, 'approve_signalement', 'signalement', 42, self::isArray());

        $this->service->processSignalement(1, 'traite', 'resolu', $admin);
    }

    public function testProcessSignalementLogsARejectAction(): void
    {
        $signalement = new Signalement();
        $signalement->setSignaleur($this->user(1));
        $this->setEntityId($signalement, 43);
        $this->signalementRepository->method('find')->willReturn($signalement);
        $admin = $this->user(99);

        $this->auditLogService->expects(self::once())
            ->method('logAdminAction')
            ->with($admin, 'reject_signalement', 'signalement', 43, self::isArray());

        $this->service->processSignalement(1, 'rejete', 'non fonde', $admin);
    }

    // ==================== countPending ====================

    public function testCountPendingDelegatesToTheRepository(): void
    {
        $this->signalementRepository->method('countEnAttente')->willReturn(7);

        self::assertSame(7, $this->service->countPending());
    }
}
