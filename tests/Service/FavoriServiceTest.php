<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\Demande;
use App\Entity\Favori;
use App\Entity\User;
use App\Entity\Voyage;
use App\Repository\DemandeRepository;
use App\Repository\FavoriRepository;
use App\Repository\VoyageRepository;
use App\Service\FavoriService;
use App\Service\RealtimeNotifier;
use App\Tests\Support\EntityIdTrait;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

/**
 * Phase 4b, Lot 6 (bec-docs/docs/plan-correction/plan-correction-cobage.md) : anti-doublon
 * (favori deja existant) et controle d'appartenance explicite dans removeFromFavoris
 * (verifie a la main, pas de Voter dedie - IDOR sur une action de suppression).
 */
class FavoriServiceTest extends TestCase
{
    use EntityIdTrait;

    private EntityManagerInterface&\PHPUnit\Framework\MockObject\MockObject $em;
    private FavoriRepository&\PHPUnit\Framework\MockObject\MockObject $favoriRepository;
    private VoyageRepository&\PHPUnit\Framework\MockObject\MockObject $voyageRepository;
    private DemandeRepository&\PHPUnit\Framework\MockObject\MockObject $demandeRepository;
    private RealtimeNotifier&\PHPUnit\Framework\MockObject\MockObject $notifier;
    private FavoriService $service;

    protected function setUp(): void
    {
        $this->em = $this->createMock(EntityManagerInterface::class);
        $this->favoriRepository = $this->createMock(FavoriRepository::class);
        $this->voyageRepository = $this->createMock(VoyageRepository::class);
        $this->demandeRepository = $this->createMock(DemandeRepository::class);
        $this->notifier = $this->createMock(RealtimeNotifier::class);

        $this->service = new FavoriService(
            $this->em,
            $this->favoriRepository,
            $this->voyageRepository,
            $this->demandeRepository,
            $this->notifier,
            new NullLogger(),
        );
    }

    private function user(int $id): User
    {
        $user = new User();
        $user->setEmail('user-' . $id . '-' . uniqid() . '@example.test');
        $user->setPassword('irrelevant');
        $this->setEntityId($user, $id);

        return $user;
    }

    // ==================== addVoyageToFavoris ====================

    public function testAddVoyageToFavorisThrowsWhenVoyageNotFound(): void
    {
        $this->voyageRepository->method('find')->willReturn(null);

        $this->expectException(NotFoundHttpException::class);
        $this->service->addVoyageToFavoris($this->user(1), 999);
    }

    public function testAddVoyageToFavorisRejectsADuplicate(): void
    {
        $this->voyageRepository->method('find')->willReturn(new Voyage());
        $this->favoriRepository->method('findByUserAndVoyage')->willReturn(new Favori());

        $this->expectException(BadRequestHttpException::class);
        $this->service->addVoyageToFavoris($this->user(1), 10);
    }

    public function testAddVoyageToFavorisSucceeds(): void
    {
        $user = $this->user(1);
        $voyage = new Voyage();
        $this->voyageRepository->method('find')->willReturn($voyage);
        $this->favoriRepository->method('findByUserAndVoyage')->willReturn(null);
        $this->em->expects(self::once())->method('persist')->with(self::isInstanceOf(Favori::class));

        $favori = $this->service->addVoyageToFavoris($user, 10);

        self::assertSame($voyage, $favori->getVoyage());
        self::assertSame($user, $favori->getUser());
    }

    // ==================== addDemandeToFavoris ====================

    public function testAddDemandeToFavorisThrowsWhenDemandeNotFound(): void
    {
        $this->demandeRepository->method('find')->willReturn(null);

        $this->expectException(NotFoundHttpException::class);
        $this->service->addDemandeToFavoris($this->user(1), 999);
    }

    public function testAddDemandeToFavorisRejectsADuplicate(): void
    {
        $this->demandeRepository->method('find')->willReturn(new Demande());
        $this->favoriRepository->method('findByUserAndDemande')->willReturn(new Favori());

        $this->expectException(BadRequestHttpException::class);
        $this->service->addDemandeToFavoris($this->user(1), 10);
    }

    public function testAddDemandeToFavorisSucceeds(): void
    {
        $user = $this->user(1);
        $demande = new Demande();
        $this->demandeRepository->method('find')->willReturn($demande);
        $this->favoriRepository->method('findByUserAndDemande')->willReturn(null);

        $favori = $this->service->addDemandeToFavoris($user, 10);

        self::assertSame($demande, $favori->getDemande());
    }

    // ==================== removeFromFavoris ====================

    public function testRemoveFromFavorisRejectsAnInvalidType(): void
    {
        $this->expectException(BadRequestHttpException::class);
        $this->service->removeFromFavoris(1, 'invalide', $this->user(1));
    }

    public function testRemoveFromFavorisThrowsWhenNotFound(): void
    {
        $this->favoriRepository->method('findByUserAndVoyage')->willReturn(null);

        $this->expectException(NotFoundHttpException::class);
        $this->service->removeFromFavoris(1, 'voyage', $this->user(1));
    }

    public function testRemoveFromFavorisRejectsANonOwningUser(): void
    {
        $owner = $this->user(1);
        $stranger = $this->user(2);
        $favori = new Favori();
        $favori->setUser($owner);
        $this->favoriRepository->method('findByUserAndVoyage')->willReturn($favori);
        $this->em->expects(self::never())->method('remove');

        $this->expectException(AccessDeniedException::class);
        $this->service->removeFromFavoris(1, 'voyage', $stranger);
    }

    public function testRemoveFromFavorisRemovesAVoyageFavori(): void
    {
        $user = $this->user(1);
        $favori = new Favori();
        $favori->setUser($user);
        $this->favoriRepository->method('findByUserAndVoyage')->willReturn($favori);
        $this->em->expects(self::once())->method('remove')->with($favori);

        $this->service->removeFromFavoris(1, 'voyage', $user);
    }

    public function testRemoveFromFavorisRemovesADemandeFavori(): void
    {
        $user = $this->user(1);
        $favori = new Favori();
        $favori->setUser($user);
        $this->favoriRepository->method('findByUserAndDemande')->willReturn($favori);
        $this->em->expects(self::once())->method('remove')->with($favori);

        $this->service->removeFromFavoris(1, 'demande', $user);
    }
}
