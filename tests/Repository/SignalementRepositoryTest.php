<?php

declare(strict_types=1);

namespace App\Tests\Repository;

use App\Entity\Signalement;
use App\Entity\User;
use App\Repository\SignalementRepository;
use App\Tests\Support\UserFactoryTrait;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Phase 4b, Lot 11 (bec-docs/docs/plan-correction/plan-correction-cobage.md) : regression
 * d'un bug deja corrige une fois et signale en commentaire dans le code
 * (SignalementRepository.php:88, "// <- AJOUT") : le COUNT de
 * findUserSignalementPaginated() a d'abord ete ecrit SANS le filtre par signaleur,
 * ce qui aurait fait apparaitre le nombre total de pages calcule sur TOUS les
 * signalements de la base plutot que ceux de l'utilisateur - jamais verifie par un
 * test jusqu'ici.
 */
class SignalementRepositoryTest extends KernelTestCase
{
    use UserFactoryTrait;

    private EntityManagerInterface $em;
    private SignalementRepository $repository;

    protected function setUp(): void
    {
        self::ensureKernelShutdown();
        self::bootKernel();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->repository = static::getContainer()->get(SignalementRepository::class);
    }

    private function signalement(User $signaleur, User $signale, string $statut = 'en_attente'): Signalement
    {
        $signalement = new Signalement();
        $signalement->setSignaleur($signaleur);
        $signalement->setUtilisateurSignale($signale);
        $signalement->setMotif('spam');
        $signalement->setDescription('Description assez longue du signalement');
        $signalement->setStatut($statut);
        $this->em->persist($signalement);
        $this->em->flush();

        return $signalement;
    }

    // ==================== findUserSignalementPaginated : regression AJOUT ====================

    public function testFindUserSignalementPaginatedCountsOnlyTheUsersOwnSignalements(): void
    {
        $signaleur = $this->createUser('sigrepo-count-signaleur');
        $other = $this->createUser('sigrepo-count-other');
        $cible = $this->createUser('sigrepo-count-cible');
        $this->signalement($signaleur, $cible);
        // Signalements d'un AUTRE utilisateur : ne doivent compter ni apparaitre dans le
        // total de $signaleur - c'est precisement le bug que "// <- AJOUT" a corrige.
        $this->signalement($other, $cible);
        $this->signalement($other, $cible);

        $result = $this->repository->findUserSignalementPaginated($signaleur, 1, 10);

        self::assertCount(1, $result['data']);
        self::assertSame(1, $result['pagination']['total']);
        self::assertSame(1, $result['pagination']['pages']);
    }

    public function testFindUserSignalementPaginatedRespectsTheStatutFilter(): void
    {
        $signaleur = $this->createUser('sigrepo-statut-signaleur');
        $cible = $this->createUser('sigrepo-statut-cible');
        $this->signalement($signaleur, $cible, statut: 'en_attente');
        $this->signalement($signaleur, $cible, statut: 'traite');

        $result = $this->repository->findUserSignalementPaginated($signaleur, 1, 10, 'traite');

        self::assertCount(1, $result['data']);
        self::assertSame('traite', $result['data'][0]->getStatut());
    }

    // ==================== findPaginated ====================

    public function testFindPaginatedComputesTheRightPageCount(): void
    {
        $signaleur = $this->createUser('sigrepo-paginated-signaleur');
        $cible = $this->createUser('sigrepo-paginated-cible');
        for ($i = 0; $i < 3; $i++) {
            $this->signalement($signaleur, $cible);
        }

        $result = $this->repository->findPaginated(1, 2);

        self::assertCount(2, $result['data']);
        self::assertGreaterThanOrEqual(3, $result['pagination']['total']);
    }

    // ==================== countEnAttente ====================

    public function testCountEnAttenteOnlyCountsPendingOnes(): void
    {
        $signaleur = $this->createUser('sigrepo-enattente-signaleur');
        $cible = $this->createUser('sigrepo-enattente-cible');
        $before = $this->repository->countEnAttente();
        $this->signalement($signaleur, $cible, statut: 'en_attente');
        $this->signalement($signaleur, $cible, statut: 'traite');

        self::assertSame($before + 1, $this->repository->countEnAttente());
    }
}
