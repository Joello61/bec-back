<?php

declare(strict_types=1);

namespace App\Tests\Repository;

use App\Entity\Demande;
use App\Entity\Proposition;
use App\Entity\User;
use App\Entity\Voyage;
use App\Repository\PropositionRepository;
use App\Tests\Support\UserFactoryTrait;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Phase 4b, Lot 11 (bec-docs/docs/plan-correction/plan-correction-cobage.md) : couverture
 * repository dediee de PropositionRepository - filtres par statut (pending/accepted)
 * et existsByVoyageAndDemande (anti-doublon, deja mocke dans PropositionServiceTest
 * au Lot 3, teste ici contre une vraie base).
 */
class PropositionRepositoryTest extends KernelTestCase
{
    use UserFactoryTrait;

    private EntityManagerInterface $em;
    private PropositionRepository $repository;

    protected function setUp(): void
    {
        self::ensureKernelShutdown();
        self::bootKernel();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->repository = static::getContainer()->get(PropositionRepository::class);
    }

    private function voyage(User $voyageur): Voyage
    {
        $voyage = new Voyage();
        $voyage->setVoyageur($voyageur);
        $voyage->setVilleDepart('Douala');
        $voyage->setVilleArrivee('Paris');
        $voyage->setDateDepart(new \DateTime('+5 days'));
        $voyage->setDateArrivee(new \DateTime('+6 days'));
        $voyage->setPoidsDisponible('20');
        $voyage->setPoidsDisponibleRestant('20');
        $this->em->persist($voyage);
        $this->em->flush();

        return $voyage;
    }

    private function demande(User $client): Demande
    {
        $demande = new Demande();
        $demande->setClient($client);
        $demande->setVilleDepart('Douala');
        $demande->setVilleArrivee('Paris');
        $demande->setPoidsEstime('5');
        $demande->setDescription('Demande de test');
        $this->em->persist($demande);
        $this->em->flush();

        return $demande;
    }

    private function proposition(Voyage $voyage, Demande $demande, string $statut = 'en_attente'): Proposition
    {
        $proposition = new Proposition();
        $proposition->setVoyage($voyage);
        $proposition->setDemande($demande);
        $proposition->setClient($demande->getClient());
        $proposition->setVoyageur($voyage->getVoyageur());
        $proposition->setPrixParKilo('10');
        $proposition->setCommissionProposeePourUnBagage('5');
        $proposition->setStatut($statut);
        $this->em->persist($proposition);
        $this->em->flush();

        return $proposition;
    }

    // ==================== findPendingByVoyageur / countPendingByVoyageur ====================

    public function testFindPendingByVoyageurExcludesNonPendingStatuses(): void
    {
        $voyageur = $this->createUser('proprepo-pending-voyageur');
        $voyage = $this->voyage($voyageur);
        $client1 = $this->createUser('proprepo-pending-client1');
        $client2 = $this->createUser('proprepo-pending-client2');
        $pending = $this->proposition($voyage, $this->demande($client1), 'en_attente');
        $this->proposition($voyage, $this->demande($client2), 'acceptee');

        $result = $this->repository->findPendingByVoyageur($voyageur->getId());

        self::assertCount(1, $result);
        self::assertSame($pending->getId(), $result[0]->getId());
    }

    public function testCountPendingByVoyageurMatchesFindPendingByVoyageur(): void
    {
        $voyageur = $this->createUser('proprepo-countpending-voyageur');
        $voyage = $this->voyage($voyageur);
        $client1 = $this->createUser('proprepo-countpending-client1');
        $client2 = $this->createUser('proprepo-countpending-client2');
        $this->proposition($voyage, $this->demande($client1), 'en_attente');
        $this->proposition($voyage, $this->demande($client2), 'en_attente');

        self::assertSame(2, $this->repository->countPendingByVoyageur($voyageur->getId()));
    }

    // ==================== existsByVoyageAndDemande ====================

    public function testExistsByVoyageAndDemandeReturnsNullWhenNoneExists(): void
    {
        self::assertNull($this->repository->existsByVoyageAndDemande(999999, 999999));
    }

    public function testExistsByVoyageAndDemandeFindsTheExistingProposition(): void
    {
        $voyageur = $this->createUser('proprepo-exists-voyageur');
        $voyage = $this->voyage($voyageur);
        $client = $this->createUser('proprepo-exists-client');
        $demande = $this->demande($client);
        $proposition = $this->proposition($voyage, $demande);

        $found = $this->repository->existsByVoyageAndDemande($voyage->getId(), $demande->getId());

        self::assertNotNull($found);
        self::assertSame($proposition->getId(), $found->getId());
    }

    // ==================== findAcceptedByVoyage ====================

    public function testFindAcceptedByVoyageExcludesPendingAndRefusedOnes(): void
    {
        $voyageur = $this->createUser('proprepo-accepted-voyageur');
        $voyage = $this->voyage($voyageur);
        $client1 = $this->createUser('proprepo-accepted-client1');
        $client2 = $this->createUser('proprepo-accepted-client2');
        $accepted = $this->proposition($voyage, $this->demande($client1), 'acceptee');
        $this->proposition($voyage, $this->demande($client2), 'en_attente');

        $result = $this->repository->findAcceptedByVoyage($voyage->getId());

        self::assertCount(1, $result);
        self::assertSame($accepted->getId(), $result[0]->getId());
    }

    // ==================== findByClient / findByVoyageur ====================

    public function testFindByClientOnlyReturnsThatClientsPropositions(): void
    {
        $voyageur = $this->createUser('proprepo-byclient-voyageur');
        $voyage = $this->voyage($voyageur);
        $client = $this->createUser('proprepo-byclient-client');
        $other = $this->createUser('proprepo-byclient-other');
        $this->proposition($voyage, $this->demande($client));
        $this->proposition($voyage, $this->demande($other));

        $result = $this->repository->findByClient($client->getId());

        self::assertCount(1, $result);
    }
}
