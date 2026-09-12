<?php

declare(strict_types=1);

namespace App\Tests\Repository;

use App\Entity\Boost;
use App\Entity\BoostOffer;
use App\Entity\Demande;
use App\Entity\User;
use App\Repository\DemandeRepository;
use App\Tests\Support\UserFactoryTrait;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Monétisation Lot 2 : symétrique de VoyageRepositoryBoostTest.
 */
class DemandeRepositoryBoostTest extends KernelTestCase
{
    use UserFactoryTrait;

    private EntityManagerInterface $em;
    private DemandeRepository $demandeRepository;

    protected function setUp(): void
    {
        self::ensureKernelShutdown();
        self::bootKernel();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->demandeRepository = static::getContainer()->get(DemandeRepository::class);
    }

    private function demande(User $client, string $suffix): Demande
    {
        $demande = new Demande();
        $demande->setClient($client);
        $demande->setVilleDepart('Douala-Boost-' . $suffix);
        $demande->setVilleArrivee('Paris-Boost-' . $suffix);
        $demande->setDateLimite(new \DateTime('+10 days'));
        $demande->setPoidsEstime('5.00');
        $demande->setDescription('Test');
        $demande->setStatut('en_recherche');
        $this->em->persist($demande);

        return $demande;
    }

    private function offer(): BoostOffer
    {
        $offer = new BoostOffer();
        $offer->setName('Test')->setDurationDays(7)->setPriceAmountEur('2.99');
        $this->em->persist($offer);

        return $offer;
    }

    private function boost(User $user, Demande $demande, BoostOffer $offer, \DateTimeInterface $endAt): Boost
    {
        $boost = new Boost();
        $boost->setUser($user)
            ->setDemande($demande)
            ->setOffer($offer)
            ->setStatus(Boost::STATUS_ACTIVE)
            ->setStartAt(new \DateTime('-1 day'))
            ->setEndAt($endAt)
            ->setAmount('2.99')
            ->setCurrency('EUR');
        $this->em->persist($boost);

        return $boost;
    }

    public function testAnActiveBoostBringsTheDemandeToTheTopOfThePublicListing(): void
    {
        $client = $this->createUser('demande-boost-owner');
        $older = $this->demande($client, 'older');
        $this->em->flush();
        $boosted = $this->demande($client, 'boosted');
        $this->em->flush();

        $offer = $this->offer();
        $this->boost($client, $boosted, $offer, new \DateTime('+7 days'));
        $this->em->flush();

        $result = $this->demandeRepository->findPublicPaginated(1, 50, ['villeDepart' => 'Douala-Boost-']);
        $ids = array_map(static fn (Demande $d) => $d->getId(), $result['data']);

        self::assertSame($boosted->getId(), $ids[0], 'la demande avec un boost actif doit remonter en tete du listing');
        self::assertContains($older->getId(), $ids);
    }

    public function testAnExpiredBoostDoesNotBringTheDemandeToTheTop(): void
    {
        $client = $this->createUser('demande-boost-expired-owner');
        $recent = $this->demande($client, 'recent');
        $this->em->flush();
        $expiredBoosted = $this->demande($client, 'expired');
        $this->em->flush();

        $offer = $this->offer();
        $this->boost($client, $expiredBoosted, $offer, new \DateTime('-1 hour'));
        $this->em->flush();

        $result = $this->demandeRepository->findPublicPaginated(1, 50, ['villeDepart' => 'Douala-Boost-']);
        $byId = [];
        foreach ($result['data'] as $demande) {
            $byId[$demande->getId()] = $demande;
        }

        self::assertFalse($byId[$expiredBoosted->getId()]->isCurrentlyBoosted());
        self::assertSame($recent->getId(), $result['data'][0]->getId(), 'un boost expire ne doit pas faire remonter la demande');
    }
}
