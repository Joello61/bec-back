<?php

declare(strict_types=1);

namespace App\Tests\Repository;

use App\Entity\Address;
use App\Entity\User;
use App\Repository\AddressRepository;
use App\Tests\Support\UserFactoryTrait;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Phase 4b, Lot 11 (bec-docs/docs/plan-correction/plan-correction-cobage.md) : couverture
 * repository dediee de AddressRepository - countModifiable encode la regle des 6 mois
 * (deja centrale dans AddressService, Lot 6), verifiee ici cote requete.
 */
class AddressRepositoryTest extends KernelTestCase
{
    use UserFactoryTrait;

    private EntityManagerInterface $em;
    private AddressRepository $repository;

    protected function setUp(): void
    {
        self::ensureKernelShutdown();
        self::bootKernel();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->repository = static::getContainer()->get(AddressRepository::class);
    }

    private function address(User $user, ?\DateTimeInterface $lastModifiedAt, string $ville = 'Douala', string $pays = 'Cameroun'): Address
    {
        // createUser() (UserFactoryTrait) cree deja une adresse par utilisateur (relation
        // OneToOne) : on reutilise celle-ci plutot que d'en persister une seconde.
        $address = $user->getAddress();
        $address->setPays($pays);
        $address->setVille($ville);
        $address->setLastModifiedAt($lastModifiedAt);
        $this->em->flush();

        return $address;
    }

    // ==================== findByUser ====================

    public function testFindByUserFindsTheUsersAddress(): void
    {
        $user = $this->createUser('addrrepo-finduser');

        $found = $this->repository->findByUser($user);

        self::assertNotNull($found);
        self::assertSame($user->getAddress()->getId(), $found->getId());
    }

    // ==================== countModifiable : regle des 6 mois ====================

    public function testCountModifiableIncludesAddressesNeverModified(): void
    {
        $user = $this->createUser('addrrepo-nevermodified');
        $this->address($user, null);

        self::assertGreaterThanOrEqual(1, $this->repository->countModifiable());
    }

    public function testCountModifiableIncludesAddressesModifiedOverSixMonthsAgo(): void
    {
        $before = $this->repository->countModifiable();
        $user = $this->createUser('addrrepo-oldmodif');
        $this->address($user, new \DateTime('-7 months'));

        self::assertSame($before + 1, $this->repository->countModifiable());
    }

    public function testCountModifiableExcludesRecentlyModifiedAddresses(): void
    {
        $before = $this->repository->countModifiable();
        $user = $this->createUser('addrrepo-recentmodif');
        $this->address($user, new \DateTime('-1 month'));

        self::assertSame($before, $this->repository->countModifiable());
    }

    // ==================== findCreatedBetween ====================

    public function testFindCreatedBetweenReturnsAddressesWithinTheRange(): void
    {
        $user = $this->createUser('addrrepo-createdbetween');
        $address = $this->address($user, null);

        $result = $this->repository->findCreatedBetween(new \DateTime('-1 hour'), new \DateTime('+1 hour'));

        $ids = array_map(fn (Address $a) => $a->getId(), $result);
        self::assertContains($address->getId(), $ids);
    }

    // ==================== findByVille / findByPays ====================

    public function testFindByVilleMatchesPartially(): void
    {
        $user = $this->createUser('addrrepo-ville');
        $this->address($user, null, ville: 'Douala-Unique-Repo');

        $result = $this->repository->findByVille('Douala-Unique');

        self::assertNotEmpty($result);
    }

    public function testFindByPaysMatchesPartially(): void
    {
        $user = $this->createUser('addrrepo-pays');
        $this->address($user, null, pays: 'Cameroun-Unique-Repo');

        $result = $this->repository->findByPays('Cameroun-Unique');

        self::assertNotEmpty($result);
    }
}
