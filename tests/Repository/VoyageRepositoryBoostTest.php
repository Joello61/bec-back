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

        // "recent" et "expiredBoosted" tombent tous les deux dans le meme bucket CASE WHEN
        // (pas de boost actif) - lequel des deux passe en premier n'a rien a voir avec le
        // comportement teste ici (un boost expire ne compte pas comme actif) et dependait
        // auparavant d'un ordre non deterministe entre eux (Partie E point 12, plan-
        // complements-monetisation-cobage.md, corrige avec un tie-breaker v.id DESC). Seule
        // assertion pertinente ici : l'expired boost ne doit jamais etre lu comme actif.
        self::assertContains($recent->getId(), array_keys($byId));
        self::assertFalse($byId[$expiredBoosted->getId()]->isCurrentlyBoosted());
    }

    public function testIsCurrentlyBoostedIsFalseWhenNoBoostExists(): void
    {
        $owner = $this->createUser('boost-none-owner');
        $voyage = $this->voyage($owner, 'none');
        $this->em->flush();

        $result = $this->voyageRepository->findPublicPaginated(1, 50, ['villeDepart' => 'Douala-Boost-none']);

        self::assertFalse($result['data'][0]->isCurrentlyBoosted());
    }

    /**
     * Régression Partie E point 12 (plan-complements-monetisation-cobage.md) : sans
     * tie-breaker "v.id DESC", l'ordre entre deux voyages au createdAt strictement
     * identique n'est pas déterministe. Force cette égalité via une UPDATE DQL directe
     * (Voyage n'expose aucun setter public pour createdAt, positionné uniquement par le
     * callback #[ORM\PrePersist]) plutôt que de compter sur un flush assez rapide pour
     * produire la même seconde - non fiable d'une exécution à l'autre.
     */
    public function testBoostOrderingIsDeterministicWhenCreatedAtIsIdentical(): void
    {
        $owner = $this->createUser('boost-tie-owner');
        $first = $this->voyage($owner, 'tie-first');
        $second = $this->voyage($owner, 'tie-second');
        $this->em->flush();

        $sameInstant = new \DateTime('-1 hour');
        $this->em->createQuery('UPDATE App\Entity\Voyage v SET v.createdAt = :dt WHERE v.id IN (:ids)')
            ->setParameter('dt', $sameInstant)
            ->setParameter('ids', [$first->getId(), $second->getId()])
            ->execute();

        $result = $this->voyageRepository->findPublicPaginated(1, 50, ['villeDepart' => 'Douala-Boost-tie']);
        $ids = array_map(static fn (Voyage $v) => $v->getId(), $result['data']);

        self::assertSame(
            [$second->getId(), $first->getId()],
            $ids,
            'à createdAt identique, le tie-breaker v.id DESC doit rendre l\'ordre déterministe'
        );
    }
}
