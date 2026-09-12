<?php

declare(strict_types=1);

namespace App\Tests\Repository;

use App\Entity\Boost;
use App\Entity\BoostOffer;
use App\Entity\User;
use App\Entity\Voyage;
use App\Repository\VoyageRepository;
use App\Tests\Support\UserFactoryTrait;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Monétisation Lot 2 : le tri par boost dépend de vraies requêtes Doctrine
 * (leftJoin + ORDER BY CASE) - ne peut être validé qu'avec une vraie base, comme
 * MatchingServiceIntegrationTest (DAMA rollback la transaction à chaque test).
 */
class VoyageRepositoryBoostTest extends KernelTestCase
{
    use UserFactoryTrait;

    private EntityManagerInterface $em;
    private VoyageRepository $voyageRepository;

    protected function setUp(): void
    {
        self::ensureKernelShutdown();
        self::bootKernel();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->voyageRepository = static::getContainer()->get(VoyageRepository::class);
    }

    private function voyage(User $voyageur, string $suffix): Voyage
    {
        $voyage = new Voyage();
        $voyage->setVoyageur($voyageur);
        $voyage->setVilleDepart('Douala-Boost-' . $suffix);
        $voyage->setVilleArrivee('Paris-Boost-' . $suffix);
        $voyage->setDateDepart(new \DateTime('+10 days'));
        $voyage->setDateArrivee(new \DateTime('+11 days'));
        $voyage->setPoidsDisponible('10.00');
        $voyage->setPoidsDisponibleRestant('10.00');
        $voyage->setStatut('actif');
        $this->em->persist($voyage);

        return $voyage;
    }

    private function offer(): BoostOffer
    {
        $offer = new BoostOffer();
        $offer->setName('Test')->setDurationDays(7)->setPriceAmountEur('2.99');
        $this->em->persist($offer);

        return $offer;
    }

    private function boost(User $user, Voyage $voyage, BoostOffer $offer, string $status, \DateTimeInterface $endAt): Boost
    {
        $boost = new Boost();
        $boost->setUser($user)
            ->setVoyage($voyage)
            ->setOffer($offer)
            ->setStatus($status)
            ->setStartAt(new \DateTime('-1 day'))
            ->setEndAt($endAt)
            ->setAmount('2.99')
            ->setCurrency('EUR');
        $this->em->persist($boost);

        return $boost;
    }

    public function testAnActiveBoostBringsTheVoyageToTheTopOfThePublicListing(): void
    {
        $owner = $this->createUser('boost-owner');
        $older = $this->voyage($owner, 'older'); // cree en premier, donc plus ancien par createdAt
        $this->em->flush();
        $boosted = $this->voyage($owner, 'boosted');
        $this->em->flush();

        $offer = $this->offer();
        $this->boost($owner, $boosted, $offer, Boost::STATUS_ACTIVE, new \DateTime('+7 days'));
        $this->em->flush();

        $result = $this->voyageRepository->findPublicPaginated(1, 50, ['villeDepart' => 'Douala-Boost-']);
        $ids = array_map(static fn (Voyage $v) => $v->getId(), $result['data']);

        self::assertSame($boosted->getId(), $ids[0], 'le voyage avec un boost actif doit remonter en tete du listing');
        self::assertContains($older->getId(), $ids);
    }

    public function testAnExpiredBoostDoesNotBringTheVoyageToTheTop(): void
    {
        $owner = $this->createUser('boost-expired-owner');
        $recent = $this->voyage($owner, 'recent');
        $this->em->flush();
        $expiredBoosted = $this->voyage($owner, 'expired');
        $this->em->flush();

        $offer = $this->offer();
        // endAt deja passe : ne doit pas compter comme "actuellement boosté"
        $this->boost($owner, $expiredBoosted, $offer, Boost::STATUS_ACTIVE, new \DateTime('-1 hour'));
        $this->em->flush();

        $result = $this->voyageRepository->findPublicPaginated(1, 50, ['villeDepart' => 'Douala-Boost-']);
        $byId = [];
        foreach ($result['data'] as $voyage) {
            $byId[$voyage->getId()] = $voyage;
        }

        self::assertFalse($byId[$expiredBoosted->getId()]->isCurrentlyBoosted());
        self::assertSame($recent->getId(), $result['data'][0]->getId(), 'un boost expire ne doit pas faire remonter le voyage');
    }

    public function testIsCurrentlyBoostedIsFalseWhenNoBoostExists(): void
    {
        $owner = $this->createUser('boost-none-owner');
        $voyage = $this->voyage($owner, 'none');
        $this->em->flush();

        $result = $this->voyageRepository->findPublicPaginated(1, 50, ['villeDepart' => 'Douala-Boost-none']);

        self::assertFalse($result['data'][0]->isCurrentlyBoosted());
    }
}
