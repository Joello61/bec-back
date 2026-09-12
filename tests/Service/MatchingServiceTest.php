<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\Demande;
use App\Entity\Voyage;
use App\Repository\DemandeRepository;
use App\Repository\VoyageRepository;
use App\Service\MatchingService;
use App\Service\SubscriptionService;
use App\Service\VisibilityService;
use PHPUnit\Framework\TestCase;

/**
 * Phase 4 du plan de correction (bec-docs/docs/plan-correction/plan-correction-cobage.md) :
 * calculateMatchScore() est une fonction pure (aucune dependance externe) - tests
 * unitaires par cas, derives des ponderations telles qu'implementees dans le code
 * (25+25 pts villes, paliers de dates 30/20/10/0, paliers de poids 20/10/0).
 *
 * findBestMatchesVoyages()/findBestMatchesDemandes() dependent en revanche de vraies
 * requetes Doctrine (VoyageRepository/DemandeRepository) - couverts separement par
 * MatchingServiceIntegrationTest (KernelTestCase, DB reelle).
 */
class MatchingServiceTest extends TestCase
{
    private MatchingService $service;

    protected function setUp(): void
    {
        $this->service = new MatchingService(
            $this->createStub(VoyageRepository::class),
            $this->createStub(DemandeRepository::class),
            new VisibilityService($this->createStub(SubscriptionService::class)),
        );
    }

    private function voyage(
        string $villeDepart = 'Douala',
        string $villeArrivee = 'Paris',
        string $poidsDisponible = '20',
        string $dateDepart = '+10 days',
    ): Voyage {
        $voyage = new Voyage();
        $voyage->setVilleDepart($villeDepart);
        $voyage->setVilleArrivee($villeArrivee);
        $voyage->setPoidsDisponible($poidsDisponible);
        $voyage->setDateDepart(new \DateTime($dateDepart));

        return $voyage;
    }

    private function demande(
        string $villeDepart = 'Douala',
        string $villeArrivee = 'Paris',
        string $poidsEstime = '20',
        ?string $dateLimite = null,
    ): Demande {
        $demande = new Demande();
        $demande->setVilleDepart($villeDepart);
        $demande->setVilleArrivee($villeArrivee);
        $demande->setPoidsEstime($poidsEstime);
        $demande->setDateLimite($dateLimite !== null ? new \DateTime($dateLimite) : null);

        return $demande;
    }

    public function testPerfectMatchScoresMaximum(): void
    {
        // villes identiques (+25+25), depart dans 7 jours pile = limite atteinte le meme
        // jour que le depart (diff 7 jours, +30), poids suffisant (+20) => 100
        $voyage = $this->voyage(dateDepart: '2026-01-08', poidsDisponible: '20');
        $demande = $this->demande(poidsEstime: '20', dateLimite: '2026-01-01');

        self::assertSame(100, $this->service->calculateMatchScore($voyage, $demande));
    }

    public function testCityMismatchScoresZeroForThatComponent(): void
    {
        $voyage = $this->voyage(villeDepart: 'Douala', villeArrivee: 'Paris');
        $demande = $this->demande(villeDepart: 'Yaounde', villeArrivee: 'Paris', dateLimite: '2026-06-01');
        $voyage->setDateDepart(new \DateTime('2026-06-01'));

        // seule la ville d'arrivee matche (+25), pas la ville de depart (+0),
        // date limite = date de depart (diff 0 jour, +30), poids suffisant (+20)
        self::assertSame(75, $this->service->calculateMatchScore($voyage, $demande));
    }

    public function testCityMatchIsCaseInsensitiveSubstring(): void
    {
        $voyage = $this->voyage(villeDepart: 'DOUALA-Bonapriso', villeArrivee: 'Paris-CDG');
        $demande = $this->demande(villeDepart: 'douala', villeArrivee: 'paris', dateLimite: null);
        $voyage->setDateDepart(new \DateTime('2026-06-01'));

        // stripos() : correspondance insensible a la casse, en sous-chaine
        self::assertSame(85, $this->service->calculateMatchScore($voyage, $demande), '25+25 villes, +15 pas de limite (flexible), +20 poids');
    }

    public function testDateBucketWithinSevenDaysScoresThirty(): void
    {
        $voyage = $this->voyage(dateDepart: '2026-06-01');
        $demande = $this->demande(dateLimite: '2026-06-08'); // diff = 7 jours

        self::assertSame(100, $this->service->calculateMatchScore($voyage, $demande));
    }

    public function testDateBucketBetweenEightAndFourteenDaysScoresTwenty(): void
    {
        $voyage = $this->voyage(dateDepart: '2026-06-01');
        $demande = $this->demande(dateLimite: '2026-06-14'); // diff = 13 jours

        self::assertSame(90, $this->service->calculateMatchScore($voyage, $demande));
    }

    public function testDateBucketBetweenFifteenAndThirtyDaysScoresTen(): void
    {
        $voyage = $this->voyage(dateDepart: '2026-06-01');
        $demande = $this->demande(dateLimite: '2026-06-25'); // diff = 24 jours

        self::assertSame(80, $this->service->calculateMatchScore($voyage, $demande));
    }

    public function testDateBucketBeyondThirtyDaysScoresZero(): void
    {
        $voyage = $this->voyage(dateDepart: '2026-06-01');
        $demande = $this->demande(dateLimite: '2026-08-01'); // diff = 61 jours

        self::assertSame(70, $this->service->calculateMatchScore($voyage, $demande));
    }

    public function testNoDateLimiteScoresFlexibleFifteen(): void
    {
        $voyage = $this->voyage(dateDepart: '2026-06-01');
        $demande = $this->demande(dateLimite: null);

        self::assertSame(85, $this->service->calculateMatchScore($voyage, $demande));
    }

    public function testWeightSufficientScoresTwenty(): void
    {
        $voyage = $this->voyage(poidsDisponible: '20', dateDepart: '2026-06-01');
        $demande = $this->demande(poidsEstime: '20', dateLimite: null);

        self::assertSame(85, $this->service->calculateMatchScore($voyage, $demande));
    }

    public function testWeightAtSeventyPercentThresholdScoresTen(): void
    {
        // poids disponible = exactement 70% du poids estime -> le seuil ">= *0.7" est inclusif
        $voyage = $this->voyage(poidsDisponible: '7', dateDepart: '2026-06-01');
        $demande = $this->demande(poidsEstime: '10', dateLimite: null);

        self::assertSame(75, $this->service->calculateMatchScore($voyage, $demande));
    }

    public function testWeightBelowSeventyPercentScoresZero(): void
    {
        $voyage = $this->voyage(poidsDisponible: '6', dateDepart: '2026-06-01');
        $demande = $this->demande(poidsEstime: '10', dateLimite: null);

        self::assertSame(65, $this->service->calculateMatchScore($voyage, $demande));
    }
}
