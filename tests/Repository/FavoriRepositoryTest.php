<?php

declare(strict_types=1);

namespace App\Tests\Repository;

use App\Entity\Demande;
use App\Entity\Favori;
use App\Entity\User;
use App\Entity\Voyage;
use App\Repository\FavoriRepository;
use App\Tests\Support\UserFactoryTrait;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Phase 4b, Lot 11 (bec-docs/docs/plan-correction/plan-correction-cobage.md) : couverture
 * repository dediee de FavoriRepository - findVoyagesByUser/findDemandesByUser doivent
 * bien discriminer via IS NOT NULL, sans quoi un favori-demande apparaitrait dans la
 * liste des favoris-voyages (et inversement).
 */
class FavoriRepositoryTest extends KernelTestCase
{
    use UserFactoryTrait;

    private EntityManagerInterface $em;
    private FavoriRepository $repository;

    protected function setUp(): void
    {
        self::ensureKernelShutdown();
        self::bootKernel();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->repository = static::getContainer()->get(FavoriRepository::class);
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

    private function favoriVoyage(User $user, Voyage $voyage): Favori
    {
        $favori = new Favori();
        $favori->setUser($user);
        $favori->setVoyage($voyage);
        $this->em->persist($favori);
        $this->em->flush();

        return $favori;
    }

    private function favoriDemande(User $user, Demande $demande): Favori
    {
        $favori = new Favori();
        $favori->setUser($user);
        $favori->setDemande($demande);
        $this->em->persist($favori);
        $this->em->flush();

        return $favori;
    }

    // ==================== findVoyagesByUser / findDemandesByUser ====================

    public function testFindVoyagesByUserExcludesDemandeFavoris(): void
    {
        $user = $this->createUser('favrepo-voyages-user');
        $voyageur = $this->createUser('favrepo-voyages-voyageur');
        $client = $this->createUser('favrepo-voyages-client');
        $this->favoriVoyage($user, $this->voyage($voyageur));
        $this->favoriDemande($user, $this->demande($client));

        $result = $this->repository->findVoyagesByUser($user->getId());

        self::assertCount(1, $result);
        self::assertNotNull($result[0]->getVoyage());
    }

    public function testFindDemandesByUserExcludesVoyageFavoris(): void
    {
        $user = $this->createUser('favrepo-demandes-user');
        $voyageur = $this->createUser('favrepo-demandes-voyageur');
        $client = $this->createUser('favrepo-demandes-client');
        $this->favoriVoyage($user, $this->voyage($voyageur));
        $this->favoriDemande($user, $this->demande($client));

        $result = $this->repository->findDemandesByUser($user->getId());

        self::assertCount(1, $result);
        self::assertNotNull($result[0]->getDemande());
    }

    // ==================== findByUser ====================

    public function testFindByUserReturnsBothTypesCombined(): void
    {
        $user = $this->createUser('favrepo-all-user');
        $voyageur = $this->createUser('favrepo-all-voyageur');
        $client = $this->createUser('favrepo-all-client');
        $this->favoriVoyage($user, $this->voyage($voyageur));
        $this->favoriDemande($user, $this->demande($client));

        $result = $this->repository->findByUser($user->getId());

        self::assertCount(2, $result);
    }

    // ==================== findByUserAndVoyage / findByUserAndDemande ====================

    public function testFindByUserAndVoyageReturnsNullWhenNotFavorited(): void
    {
        $user = $this->createUser('favrepo-notfound-user');

        self::assertNull($this->repository->findByUserAndVoyage($user->getId(), 999999));
    }

    // ==================== countByVoyage ====================

    public function testCountByVoyageCountsAllUsersWhoFavoritedIt(): void
    {
        $voyageur = $this->createUser('favrepo-count-voyageur');
        $voyage = $this->voyage($voyageur);
        $user1 = $this->createUser('favrepo-count-user1');
        $user2 = $this->createUser('favrepo-count-user2');
        $this->favoriVoyage($user1, $voyage);
        $this->favoriVoyage($user2, $voyage);

        self::assertSame(2, $this->repository->countByVoyage($voyage->getId()));
    }
}
