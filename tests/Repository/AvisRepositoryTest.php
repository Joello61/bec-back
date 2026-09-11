<?php

declare(strict_types=1);

namespace App\Tests\Repository;

use App\Entity\Avis;
use App\Entity\User;
use App\Repository\AvisRepository;
use App\Tests\Support\UserFactoryTrait;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Phase 4b, Lot 11 (bec-docs/docs/plan-correction/plan-correction-cobage.md) : couverture
 * repository dediee des agregations d'AvisRepository (moyennes, batch, distribution).
 */
class AvisRepositoryTest extends KernelTestCase
{
    use UserFactoryTrait;

    private EntityManagerInterface $em;
    private AvisRepository $repository;

    protected function setUp(): void
    {
        self::ensureKernelShutdown();
        self::bootKernel();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->repository = static::getContainer()->get(AvisRepository::class);
    }

    private function avis(User $auteur, User $cible, int $note): Avis
    {
        $avis = new Avis();
        $avis->setAuteur($auteur);
        $avis->setCible($cible);
        $avis->setNote($note);
        $avis->setCommentaire('Commentaire de test');
        $this->em->persist($avis);
        $this->em->flush();

        return $avis;
    }

    // ==================== getAverageNotesForUsers ====================

    public function testGetAverageNotesForUsersReturnsAnEmptyArrayForNoIds(): void
    {
        self::assertSame([], $this->repository->getAverageNotesForUsers([]));
    }

    public function testGetAverageNotesForUsersComputesPerUserAverages(): void
    {
        $auteur = $this->createUser('avisrepo-avg-auteur');
        $cible1 = $this->createUser('avisrepo-avg-cible1');
        $cible2 = $this->createUser('avisrepo-avg-cible2');
        $this->avis($auteur, $cible1, 5);
        $this->avis($auteur, $cible1, 3);
        $this->avis($auteur, $cible2, 4);

        $averages = $this->repository->getAverageNotesForUsers([$cible1->getId(), $cible2->getId()]);

        self::assertSame(4.0, $averages[$cible1->getId()]);
        self::assertSame(4.0, $averages[$cible2->getId()]);
    }

    // ==================== getAverageNote ====================

    public function testGetAverageNoteReturnsNullWhenNoReview(): void
    {
        $user = $this->createUser('avisrepo-noavg');

        self::assertNull($this->repository->getAverageNote($user->getId()));
    }

    public function testGetAverageNoteComputesTheAverage(): void
    {
        $auteur = $this->createUser('avisrepo-getavg-auteur');
        $cible = $this->createUser('avisrepo-getavg-cible');
        $this->avis($auteur, $cible, 2);
        $this->avis($auteur, $cible, 4);

        self::assertSame(3.0, $this->repository->getAverageNote($cible->getId()));
    }

    // ==================== findByAuteurAndCible ====================

    public function testFindByAuteurAndCibleReturnsNullWhenNoMatch(): void
    {
        $auteur = $this->createUser('avisrepo-noavis-auteur');
        $cible = $this->createUser('avisrepo-noavis-cible');

        self::assertNull($this->repository->findByAuteurAndCible($auteur->getId(), $cible->getId()));
    }

    public function testFindByAuteurAndCibleFindsTheExistingReview(): void
    {
        $auteur = $this->createUser('avisrepo-findpair-auteur');
        $cible = $this->createUser('avisrepo-findpair-cible');
        $avis = $this->avis($auteur, $cible, 5);

        $found = $this->repository->findByAuteurAndCible($auteur->getId(), $cible->getId());

        self::assertSame($avis->getId(), $found->getId());
    }

    // ==================== getStatsByUser ====================

    public function testGetStatsByUserReturnsTheFullDistributionAndAverage(): void
    {
        $auteur = $this->createUser('avisrepo-stats-auteur');
        $cible = $this->createUser('avisrepo-stats-cible');
        $this->avis($auteur, $cible, 5);
        $this->avis($auteur, $cible, 5);
        $this->avis($auteur, $cible, 3);

        $stats = $this->repository->getStatsByUser($cible->getId());

        self::assertSame(3, $stats['total']);
        self::assertSame(4.3, $stats['average']);
        self::assertSame(2, $stats['distribution'][5]);
        self::assertSame(1, $stats['distribution'][3]);
        self::assertSame(0, $stats['distribution'][1]);
    }

    // ==================== findAllPaginated ====================

    public function testFindAllPaginatedFiltersByMaxNote(): void
    {
        $auteur = $this->createUser('avisrepo-paginated-auteur');
        $cible = $this->createUser('avisrepo-paginated-cible');
        $lowNote = $this->avis($auteur, $cible, 1);
        $this->avis($auteur, $cible, 5);

        $result = $this->repository->findAllPaginated(1, 20, ['maxNote' => 2]);

        $ids = array_map(fn (Avis $avis) => $avis->getId(), $result['data']);
        self::assertContains($lowNote->getId(), $ids);
        self::assertCount(1, array_filter($ids, fn ($id) => $id === $lowNote->getId()));
    }

    public function testFindAllPaginatedComputesPaginationMetadata(): void
    {
        $auteur = $this->createUser('avisrepo-paginated-meta-auteur');
        $cible = $this->createUser('avisrepo-paginated-meta-cible');
        $this->avis($auteur, $cible, 4);
        $this->avis($auteur, $cible, 4);
        $this->avis($auteur, $cible, 4);

        $result = $this->repository->findAllPaginated(1, 2);

        self::assertCount(2, $result['data']);
        self::assertSame(1, $result['pagination']['page']);
        self::assertSame(2, $result['pagination']['limit']);
        self::assertGreaterThanOrEqual(3, $result['pagination']['total']);
    }
}
