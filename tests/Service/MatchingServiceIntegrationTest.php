<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\Demande;
use App\Entity\User;
use App\Entity\UserSettings;
use App\Entity\Voyage;
use App\Service\MatchingService;
use App\Tests\Support\UserFactoryTrait;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Phase 4 du plan de correction (bec-docs/docs/plan-correction/plan-correction-cobage.md) :
 * findBestMatchesVoyages()/findBestMatchesDemandes() dependent de vraies requetes
 * Doctrine (VoyageRepository/DemandeRepository) + VisibilityService - contrairement a
 * calculateMatchScore() (MatchingServiceTest, unitaire pur), ce comportement combine
 * ne peut etre valide qu'avec une vraie base (DAMA rollback la transaction a chaque test,
 * comme les autres tests fonctionnels de ce projet).
 */
class MatchingServiceIntegrationTest extends KernelTestCase
{
    use UserFactoryTrait;

    private EntityManagerInterface $em;
    private MatchingService $matchingService;

    protected function setUp(): void
    {
        self::ensureKernelShutdown();
        self::bootKernel();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->matchingService = static::getContainer()->get(MatchingService::class);
    }

    private function voyage(User $voyageur, string $poidsDisponible, string $dateDepart): Voyage
    {
        $voyage = new Voyage();
        $voyage->setVoyageur($voyageur);
        $voyage->setVilleDepart('Douala-Match-Unique');
        $voyage->setVilleArrivee('Paris-Match-Unique');
        $voyage->setDateDepart(new \DateTime($dateDepart));
        $voyage->setDateArrivee((new \DateTime($dateDepart))->modify('+1 day'));
        $voyage->setPoidsDisponible($poidsDisponible);
        $voyage->setPoidsDisponibleRestant($poidsDisponible);
        $voyage->setStatut('actif');
        $this->em->persist($voyage);

        return $voyage;
    }

    public function testFindBestMatchesVoyagesOrdersByScoreDescending(): void
    {
        $client = $this->createUser('int-client');
        $demande = new Demande();
        $demande->setClient($client);
        $demande->setVilleDepart('Douala-Match-Unique');
        $demande->setVilleArrivee('Paris-Match-Unique');
        $demande->setPoidsEstime('20');
        $demande->setDateLimite(new \DateTime('2026-06-01'));
        $demande->setDescription('integration matching test');
        $this->em->persist($demande);

        // VoyageRepository::findMatchingDemande() filtre v.dateDepart >= demande.dateLimite -
        // les deux voyages doivent donc partir a/apres cette date pour etre candidats.
        $bestOwner = $this->createUser('int-best-owner');
        $worstOwner = $this->createUser('int-worst-owner');
        // meilleur score : poids largement suffisant, depart le jour de la date limite (diff 0)
        $bestVoyage = $this->voyage($bestOwner, '30', '2026-06-01');
        // moins bon score : poids sous le seuil de 70%, depart loin de la date limite (diff > 30 jours)
        $worstVoyage = $this->voyage($worstOwner, '13', '2026-09-01');
        $this->em->flush();

        $matches = $this->matchingService->findBestMatchesVoyages($demande, $client, limit: 5);

        self::assertCount(2, $matches);
        self::assertSame($bestVoyage->getId(), $matches[0]['voyage']->getId());
        self::assertSame($worstVoyage->getId(), $matches[1]['voyage']->getId());
        self::assertGreaterThan($matches[1]['score'], $matches[0]['score']);
    }

    public function testFindBestMatchesVoyagesRespectsLimit(): void
    {
        $client = $this->createUser('int-client-limit');
        $demande = new Demande();
        $demande->setClient($client);
        $demande->setVilleDepart('Douala-Match-Limit');
        $demande->setVilleArrivee('Paris-Match-Limit');
        $demande->setPoidsEstime('10');
        $demande->setDescription('integration matching limit test');
        $this->em->persist($demande);

        for ($i = 0; $i < 3; $i++) {
            $owner = $this->createUser('int-limit-owner-' . $i);
            $voyage = new Voyage();
            $voyage->setVoyageur($owner);
            $voyage->setVilleDepart('Douala-Match-Limit');
            $voyage->setVilleArrivee('Paris-Match-Limit');
            $voyage->setDateDepart(new \DateTime('+5 days'));
            $voyage->setDateArrivee(new \DateTime('+6 days'));
            $voyage->setPoidsDisponible('10');
            $voyage->setPoidsDisponibleRestant('10');
            $voyage->setStatut('actif');
            $this->em->persist($voyage);
        }
        $this->em->flush();

        $matches = $this->matchingService->findBestMatchesVoyages($demande, $client, limit: 2);

        self::assertCount(2, $matches);
    }

    public function testFindBestMatchesVoyagesExcludesVoyageWithPrivateProfile(): void
    {
        $client = $this->createUser('int-client-private');
        $demande = new Demande();
        $demande->setClient($client);
        $demande->setVilleDepart('Douala-Match-Private');
        $demande->setVilleArrivee('Paris-Match-Private');
        $demande->setPoidsEstime('10');
        $demande->setDescription('integration matching visibility test');
        $this->em->persist($demande);

        // showInSearchResults=true (donc pas filtre par la requete SQL elle-meme, qui ne
        // verifie que ce seul champ) mais profileVisibility=private (filtre uniquement
        // par VisibilityService::filterVisibleVoyages, en memoire, apres la requete).
        $privateOwner = $this->createUser('int-private-owner');
        $settings = new UserSettings();
        $settings->setUser($privateOwner);
        $settings->setShowInSearchResults(true);
        $settings->setProfileVisibility('private');
        $privateOwner->setSettings($settings);
        $this->em->persist($settings);

        $voyage = new Voyage();
        $voyage->setVoyageur($privateOwner);
        $voyage->setVilleDepart('Douala-Match-Private');
        $voyage->setVilleArrivee('Paris-Match-Private');
        $voyage->setDateDepart(new \DateTime('+5 days'));
        $voyage->setDateArrivee(new \DateTime('+6 days'));
        $voyage->setPoidsDisponible('10');
        $voyage->setPoidsDisponibleRestant('10');
        $voyage->setStatut('actif');
        $this->em->persist($voyage);
        $this->em->flush();

        $matches = $this->matchingService->findBestMatchesVoyages($demande, $client, limit: 5);

        self::assertCount(0, $matches, 'un voyageur avec profileVisibility=private ne doit jamais apparaitre dans les correspondances d\'un tiers, meme si showInSearchResults est vrai');
    }
}
