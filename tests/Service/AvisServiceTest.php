<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\DTO\CreateAvisDTO;
use App\Entity\Avis;
use App\Entity\User;
use App\Entity\Voyage;
use App\Repository\AvisRepository;
use App\Repository\UserRepository;
use App\Repository\VoyageRepository;
use App\Service\AvisService;
use App\Service\NotificationService;
use App\Service\RealtimeNotifier;
use App\Tests\Support\EntityIdTrait;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Phase 4b, Lot 6 (bec-docs/docs/plan-correction/plan-correction-cobage.md) : le controle
 * d'appartenance sur update/delete est fait en amont par AvisVoter (deja teste en Phase 4,
 * AvisVoterTest) - ce service se concentre sur ses propres regles metier : interdiction
 * d'auto-avis, anti-doublon (un seul avis par paire auteur/cible).
 */
class AvisServiceTest extends TestCase
{
    use EntityIdTrait;

    private EntityManagerInterface&\PHPUnit\Framework\MockObject\MockObject $em;
    private AvisRepository&\PHPUnit\Framework\MockObject\MockObject $avisRepository;
    private UserRepository&\PHPUnit\Framework\MockObject\MockObject $userRepository;
    private VoyageRepository&\PHPUnit\Framework\MockObject\MockObject $voyageRepository;
    private NotificationService&\PHPUnit\Framework\MockObject\MockObject $notificationService;
    private RealtimeNotifier&\PHPUnit\Framework\MockObject\MockObject $notifier;
    private AvisService $service;

    protected function setUp(): void
    {
        $this->em = $this->createMock(EntityManagerInterface::class);
        $this->avisRepository = $this->createMock(AvisRepository::class);
        $this->userRepository = $this->createMock(UserRepository::class);
        $this->voyageRepository = $this->createMock(VoyageRepository::class);
        $this->notificationService = $this->createMock(NotificationService::class);
        $this->notifier = $this->createMock(RealtimeNotifier::class);

        $this->service = new AvisService(
            $this->em,
            $this->avisRepository,
            $this->userRepository,
            $this->voyageRepository,
            $this->notificationService,
            $this->notifier,
            new NullLogger(),
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

    private function dto(int $cibleId, ?int $voyageId = null, int $note = 4): CreateAvisDTO
    {
        $dto = new CreateAvisDTO();
        $dto->cibleId = $cibleId;
        $dto->voyageId = $voyageId;
        $dto->note = $note;
        $dto->commentaire = 'tres bien';

        return $dto;
    }

    // ==================== createAvis ====================

    public function testCreateAvisThrowsWhenTargetNotFound(): void
    {
        $this->userRepository->method('find')->willReturn(null);

        $this->expectException(NotFoundHttpException::class);
        $this->service->createAvis($this->dto(999), $this->user(1));
    }

    public function testCreateAvisRejectsSelfReview(): void
    {
        $user = $this->user(1);
        $this->userRepository->method('find')->willReturn($user);

        $this->expectException(BadRequestHttpException::class);
        $this->service->createAvis($this->dto(1), $user);
    }

    public function testCreateAvisThrowsWhenLinkedVoyageNotFound(): void
    {
        $auteur = $this->user(1);
        $cible = $this->user(2);
        $this->userRepository->method('find')->willReturn($cible);
        $this->voyageRepository->method('find')->willReturn(null);

        $this->expectException(NotFoundHttpException::class);
        $this->service->createAvis($this->dto(2, voyageId: 50), $auteur);
    }

    public function testCreateAvisRejectsADuplicateReview(): void
    {
        $auteur = $this->user(1);
        $cible = $this->user(2);
        $this->userRepository->method('find')->willReturn($cible);
        $this->avisRepository->method('findByAuteurAndCible')->willReturn(new Avis());

        $this->expectException(BadRequestHttpException::class);
        $this->service->createAvis($this->dto(2), $auteur);
    }

    public function testCreateAvisSucceedsWithoutALinkedVoyage(): void
    {
        $auteur = $this->user(1);
        $cible = $this->user(2);
        $this->userRepository->method('find')->willReturn($cible);
        $this->avisRepository->method('findByAuteurAndCible')->willReturn(null);
        $this->em->expects(self::once())->method('persist')->with(self::isInstanceOf(Avis::class));
        $this->notificationService->expects(self::once())->method('createNotification');

        $avis = $this->service->createAvis($this->dto(2, note: 5), $auteur);

        self::assertSame(5, $avis->getNote());
        self::assertNull($avis->getVoyage());
    }

    public function testCreateAvisSucceedsWithALinkedVoyage(): void
    {
        $auteur = $this->user(1);
        $cible = $this->user(2);
        $voyage = new Voyage();
        $this->userRepository->method('find')->willReturn($cible);
        $this->voyageRepository->method('find')->willReturn($voyage);
        $this->avisRepository->method('findByAuteurAndCible')->willReturn(null);

        $avis = $this->service->createAvis($this->dto(2, voyageId: 50), $auteur);

        self::assertSame($voyage, $avis->getVoyage());
    }

    // ==================== updateAvis ====================

    public function testUpdateAvisThrowsWhenNotFound(): void
    {
        $this->avisRepository->method('find')->willReturn(null);

        $this->expectException(NotFoundHttpException::class);
        $this->service->updateAvis(999, $this->dto(2));
    }

    public function testUpdateAvisAppliesTheNewNoteAndComment(): void
    {
        $avis = new Avis();
        $avis->setCible($this->user(2));
        $this->avisRepository->method('find')->willReturn($avis);
        $this->em->expects(self::once())->method('flush');

        $result = $this->service->updateAvis(1, $this->dto(2, note: 2));

        self::assertSame(2, $result->getNote());
        self::assertSame('tres bien', $result->getCommentaire());
    }

    // ==================== deleteAvis ====================

    public function testDeleteAvisThrowsWhenNotFound(): void
    {
        $this->avisRepository->method('find')->willReturn(null);

        $this->expectException(NotFoundHttpException::class);
        $this->service->deleteAvis(999);
    }

    public function testDeleteAvisRemovesIt(): void
    {
        $avis = new Avis();
        $avis->setCible($this->user(2));
        $this->avisRepository->method('find')->willReturn($avis);
        $this->em->expects(self::once())->method('remove')->with($avis);
        $this->em->expects(self::once())->method('flush');

        $this->service->deleteAvis(1);
    }
}
