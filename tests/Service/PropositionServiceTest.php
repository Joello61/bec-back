<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\DTO\CreatePropositionDTO;
use App\DTO\RespondPropositionDTO;
use App\Entity\Demande;
use App\Entity\Proposition;
use App\Entity\User;
use App\Entity\UserSettings;
use App\Entity\Voyage;
use App\Repository\DemandeRepository;
use App\Repository\PropositionRepository;
use App\Repository\VoyageRepository;
use App\Service\CurrencyService;
use App\Service\NotificationService;
use App\Service\PropositionService;
use App\Service\RealtimeNotifier;
use App\Tests\Support\EntityIdTrait;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Phase 4b, Lot 3 (bec-docs/docs/plan-correction/plan-correction-cobage.md) : le service le
 * plus dense en regles metier du backend - 7 validations distinctes sur createProposition(),
 * transitions d'etat multi-entites (voyage/demande/propositions concurrentes) sur
 * respondToProposition(), et le bug de deleteProposition() corrige dans le commit
 * precedent (regression testee explicitement ici).
 */
class PropositionServiceTest extends TestCase
{
    use EntityIdTrait;

    private EntityManagerInterface&\PHPUnit\Framework\MockObject\MockObject $em;
    private PropositionRepository&\PHPUnit\Framework\MockObject\MockObject $propositionRepository;
    private VoyageRepository&\PHPUnit\Framework\MockObject\MockObject $voyageRepository;
    private DemandeRepository&\PHPUnit\Framework\MockObject\MockObject $demandeRepository;
    private NotificationService&\PHPUnit\Framework\MockObject\MockObject $notificationService;
    private CurrencyService&\PHPUnit\Framework\MockObject\MockObject $currencyService;
    private RealtimeNotifier&\PHPUnit\Framework\MockObject\MockObject $notifier;
    private PropositionService $service;

    protected function setUp(): void
    {
        $this->em = $this->createMock(EntityManagerInterface::class);
        $this->propositionRepository = $this->createMock(PropositionRepository::class);
        $this->voyageRepository = $this->createMock(VoyageRepository::class);
        $this->demandeRepository = $this->createMock(DemandeRepository::class);
        $this->notificationService = $this->createMock(NotificationService::class);
        $this->currencyService = $this->createMock(CurrencyService::class);
        $this->notifier = $this->createMock(RealtimeNotifier::class);

        $this->service = new PropositionService(
            $this->em,
            $this->propositionRepository,
            $this->voyageRepository,
            $this->demandeRepository,
            $this->notificationService,
            $this->currencyService,
            new NullLogger(),
            $this->notifier,
        );
    }

    private function user(int $id): User
    {
        $user = new User();
        $user->setEmail('user-' . $id . '-' . uniqid() . '@example.test');
        $user->setNom('Nom');
        $user->setPrenom('Prenom');
        $user->setPassword('irrelevant');
        $this->setEntityId($user, $id);

        return $user;
    }

    private function voyage(int $id, User $voyageur, string $statut = 'actif', string $poidsDisponible = '20', ?string $poidsRestant = null): Voyage
    {
        $voyage = new Voyage();
        $voyage->setVoyageur($voyageur);
        $voyage->setVilleDepart('Douala');
        $voyage->setVilleArrivee('Paris');
        $voyage->setDateDepart(new \DateTime('+5 days'));
        $voyage->setStatut($statut);
        $voyage->setPoidsDisponible($poidsDisponible);
        $voyage->setPoidsDisponibleRestant($poidsRestant ?? $poidsDisponible);
        $this->setEntityId($voyage, $id);

        return $voyage;
    }

    private function demande(int $id, User $client, string $statut = 'en_recherche', string $poidsEstime = '10', string $currency = 'XAF'): Demande
    {
        $demande = new Demande();
        $demande->setClient($client);
        $demande->setVilleDepart('Douala');
        $demande->setVilleArrivee('Paris');
        $demande->setStatut($statut);
        $demande->setPoidsEstime($poidsEstime);
        $demande->setCurrency($currency);
        $this->setEntityId($demande, $id);

        return $demande;
    }

    private function proposition(int $id, Voyage $voyage, Demande $demande, User $client, User $voyageur, string $statut = 'en_attente'): Proposition
    {
        $proposition = new Proposition();
        $proposition->setVoyage($voyage);
        $proposition->setDemande($demande);
        $proposition->setClient($client);
        $proposition->setVoyageur($voyageur);
        $proposition->setPrixParKilo('5');
        $proposition->setCommissionProposeePourUnBagage('1000');
        $proposition->setCurrency($demande->getCurrency());
        $proposition->setStatut($statut);
        $this->setEntityId($proposition, $id);

        return $proposition;
    }

    private function createDto(float $prix = 5.0, float $commission = 1000.0): CreatePropositionDTO
    {
        $dto = new CreatePropositionDTO();
        $dto->demandeId = 1;
        $dto->prixParKilo = $prix;
        $dto->commissionProposeePourUnBagage = $commission;
        $dto->message = null;

        return $dto;
    }

    // ==================== createProposition ====================

    public function testCreatePropositionThrowsWhenVoyageNotFound(): void
    {
        $this->voyageRepository->method('find')->willReturn(null);

        $this->expectException(NotFoundHttpException::class);
        $this->service->createProposition(1, $this->createDto(), $this->user(1));
    }

    public function testCreatePropositionThrowsWhenDemandeNotFound(): void
    {
        $voyageur = $this->user(2);
        $this->voyageRepository->method('find')->willReturn($this->voyage(1, $voyageur));
        $this->demandeRepository->method('find')->willReturn(null);

        $this->expectException(NotFoundHttpException::class);
        $this->service->createProposition(1, $this->createDto(), $this->user(1));
    }

    public function testCreatePropositionRejectsWhenClientIsNotTheDemandeOwner(): void
    {
        $voyageur = $this->user(2);
        $demandeOwner = $this->user(3);
        $stranger = $this->user(4);
        $this->voyageRepository->method('find')->willReturn($this->voyage(1, $voyageur));
        $this->demandeRepository->method('find')->willReturn($this->demande(1, $demandeOwner));

        $this->expectException(BadRequestHttpException::class);
        $this->service->createProposition(1, $this->createDto(), $stranger);
    }

    public function testCreatePropositionRejectsProposingOnOwnVoyage(): void
    {
        $client = $this->user(2);
        $this->voyageRepository->method('find')->willReturn($this->voyage(1, $client));
        $this->demandeRepository->method('find')->willReturn($this->demande(1, $client));

        $this->expectException(BadRequestHttpException::class);
        $this->service->createProposition(1, $this->createDto(), $client);
    }

    public function testCreatePropositionRejectsAnInactiveVoyage(): void
    {
        $voyageur = $this->user(2);
        $client = $this->user(3);
        $this->voyageRepository->method('find')->willReturn($this->voyage(1, $voyageur, statut: 'complete'));
        $this->demandeRepository->method('find')->willReturn($this->demande(1, $client));

        $this->expectException(BadRequestHttpException::class);
        $this->service->createProposition(1, $this->createDto(), $client);
    }

    public function testCreatePropositionRejectsADemandeNotSearching(): void
    {
        $voyageur = $this->user(2);
        $client = $this->user(3);
        $this->voyageRepository->method('find')->willReturn($this->voyage(1, $voyageur));
        $this->demandeRepository->method('find')->willReturn($this->demande(1, $client, statut: 'voyageur_trouve'));

        $this->expectException(BadRequestHttpException::class);
        $this->service->createProposition(1, $this->createDto(), $client);
    }

    public function testCreatePropositionRejectsADuplicateProposition(): void
    {
        $voyageur = $this->user(2);
        $client = $this->user(3);
        $this->voyageRepository->method('find')->willReturn($this->voyage(1, $voyageur));
        $this->demandeRepository->method('find')->willReturn($this->demande(1, $client));
        $existing = $this->proposition(2, $this->voyage(1, $voyageur), $this->demande(1, $client), $client, $voyageur);
        $this->propositionRepository->method('existsByVoyageAndDemande')->willReturn($existing);

        $this->expectException(BadRequestHttpException::class);
        $this->service->createProposition(1, $this->createDto(), $client);
    }

    public function testCreatePropositionRejectsWhenNotEnoughWeightAvailable(): void
    {
        $voyageur = $this->user(2);
        $client = $this->user(3);
        $this->voyageRepository->method('find')->willReturn($this->voyage(1, $voyageur, poidsDisponible: '5'));
        $this->demandeRepository->method('find')->willReturn($this->demande(1, $client, poidsEstime: '10'));
        $this->propositionRepository->method('existsByVoyageAndDemande')->willReturn(null);

        $this->expectException(BadRequestHttpException::class);
        $this->service->createProposition(1, $this->createDto(), $client);
    }

    public function testCreatePropositionSucceedsAndUsesTheDemandeCurrency(): void
    {
        $voyageur = $this->user(2);
        $client = $this->user(3);
        $this->voyageRepository->method('find')->willReturn($this->voyage(1, $voyageur, poidsDisponible: '20'));
        $this->demandeRepository->method('find')->willReturn($this->demande(1, $client, poidsEstime: '10', currency: 'EUR'));
        $this->propositionRepository->method('existsByVoyageAndDemande')->willReturn(null);
        $this->em->expects(self::once())->method('persist')->with(self::isInstanceOf(Proposition::class));
        $this->em->expects(self::once())->method('flush');
        $this->notificationService->expects(self::once())->method('createNotification');

        $result = $this->service->createProposition(1, $this->createDto(), $client);

        self::assertSame('EUR', $result->getCurrency(), 'la proposition doit toujours utiliser la devise de la demande, jamais celle du DTO (qui n\'en porte pas)');
        self::assertSame('en_attente', $result->getStatut());
    }

    // ==================== respondToProposition ====================

    public function testRespondToPropositionThrowsWhenNotFound(): void
    {
        $this->propositionRepository->method('find')->willReturn(null);

        $this->expectException(NotFoundHttpException::class);
        $this->service->respondToProposition(1, $this->respondDto('accepter'), $this->user(1));
    }

    public function testRespondToPropositionRejectsTheWrongVoyageur(): void
    {
        $voyageur = $this->user(2);
        $stranger = $this->user(99);
        $client = $this->user(3);
        $voyage = $this->voyage(1, $voyageur);
        $demande = $this->demande(1, $client);
        $this->propositionRepository->method('find')->willReturn($this->proposition(1, $voyage, $demande, $client, $voyageur));

        $this->expectException(BadRequestHttpException::class);
        $this->service->respondToProposition(1, $this->respondDto('accepter'), $stranger);
    }

    public function testRespondToPropositionRejectsAnAlreadyAnsweredProposition(): void
    {
        $voyageur = $this->user(2);
        $client = $this->user(3);
        $voyage = $this->voyage(1, $voyageur);
        $demande = $this->demande(1, $client);
        $this->propositionRepository->method('find')->willReturn($this->proposition(1, $voyage, $demande, $client, $voyageur, statut: 'acceptee'));

        $this->expectException(BadRequestHttpException::class);
        $this->service->respondToProposition(1, $this->respondDto('accepter'), $voyageur);
    }

    public function testRespondToPropositionAcceptDecrementsWeightAndUpdatesStatuses(): void
    {
        $voyageur = $this->user(2);
        $client = $this->user(3);
        $voyage = $this->voyage(1, $voyageur, poidsDisponible: '20', poidsRestant: '20');
        $demande = $this->demande(1, $client, poidsEstime: '12');
        $proposition = $this->proposition(1, $voyage, $demande, $client, $voyageur);
        $this->propositionRepository->method('find')->willReturn($proposition);

        $this->service->respondToProposition(1, $this->respondDto('accepter'), $voyageur);

        self::assertSame('acceptee', $proposition->getStatut());
        self::assertSame('8.00', $voyage->getPoidsDisponibleRestant());
        self::assertSame('actif', $voyage->getStatut(), 'il reste de la place, le voyage ne doit pas passer complet');
        self::assertSame('voyageur_trouve', $demande->getStatut());
    }

    public function testRespondToPropositionAcceptMarksVoyageCompleteWhenWeightReachesZero(): void
    {
        $voyageur = $this->user(2);
        $client = $this->user(3);
        $voyage = $this->voyage(1, $voyageur, poidsDisponible: '10', poidsRestant: '10');
        $demande = $this->demande(1, $client, poidsEstime: '10');
        $proposition = $this->proposition(1, $voyage, $demande, $client, $voyageur);
        $this->propositionRepository->method('find')->willReturn($proposition);

        $this->service->respondToProposition(1, $this->respondDto('accepter'), $voyageur);

        self::assertSame('0.00', $voyage->getPoidsDisponibleRestant());
        self::assertSame('complete', $voyage->getStatut());
    }

    public function testRespondToPropositionAcceptNeverLetsWeightGoNegative(): void
    {
        $voyageur = $this->user(2);
        $client = $this->user(3);
        $voyage = $this->voyage(1, $voyageur, poidsDisponible: '5', poidsRestant: '5');
        $demande = $this->demande(1, $client, poidsEstime: '999');
        $proposition = $this->proposition(1, $voyage, $demande, $client, $voyageur);
        $this->propositionRepository->method('find')->willReturn($proposition);

        $this->service->respondToProposition(1, $this->respondDto('accepter'), $voyageur);

        self::assertSame('0.00', $voyage->getPoidsDisponibleRestant());
    }

    public function testRespondToPropositionAcceptCancelsOtherPendingPropositionsOfTheSameDemande(): void
    {
        $voyageur = $this->user(2);
        $client = $this->user(3);
        $otherVoyageur = $this->user(4);
        $otherVoyage = $this->voyage(2, $otherVoyageur);
        $voyage = $this->voyage(1, $voyageur, poidsDisponible: '20', poidsRestant: '20');
        $demande = $this->demande(1, $client, poidsEstime: '5');
        $accepted = $this->proposition(1, $voyage, $demande, $client, $voyageur);
        $otherPending = $this->proposition(2, $otherVoyage, $demande, $client, $otherVoyageur);
        $alreadyRefused = $this->proposition(3, $otherVoyage, $demande, $client, $otherVoyageur, statut: 'refusee');
        $demande->getPropositions()->add($accepted);
        $demande->getPropositions()->add($otherPending);
        $demande->getPropositions()->add($alreadyRefused);
        $this->propositionRepository->method('find')->willReturn($accepted);

        $this->service->respondToProposition(1, $this->respondDto('accepter'), $voyageur);

        self::assertSame('annulee', $otherPending->getStatut());
        self::assertSame('refusee', $alreadyRefused->getStatut(), 'une proposition deja refusee ne doit pas etre touchee par la cascade');
    }

    public function testRespondToPropositionRefuseSetsStatusAndReason(): void
    {
        $voyageur = $this->user(2);
        $client = $this->user(3);
        $voyage = $this->voyage(1, $voyageur);
        $demande = $this->demande(1, $client);
        $proposition = $this->proposition(1, $voyage, $demande, $client, $voyageur);
        $this->propositionRepository->method('find')->willReturn($proposition);
        $this->notificationService->expects(self::once())->method('createNotification');

        $this->service->respondToProposition(1, $this->respondDto('refuser', 'poids insuffisant'), $voyageur);

        self::assertSame('refusee', $proposition->getStatut());
        self::assertSame('poids insuffisant', $proposition->getMessageRefus());
        self::assertSame('en_recherche', $demande->getStatut(), 'un refus ne doit jamais changer le statut de la demande');
    }

    // ==================== deleteProposition ====================

    public function testDeletePropositionThrowsWhenNotFound(): void
    {
        $this->propositionRepository->method('find')->willReturn(null);

        $this->expectException(NotFoundHttpException::class);
        $this->service->deleteProposition(1, $this->user(1));
    }

    public function testDeletePropositionRejectsANonOwningClient(): void
    {
        $voyageur = $this->user(2);
        $client = $this->user(3);
        $stranger = $this->user(4);
        $voyage = $this->voyage(1, $voyageur);
        $demande = $this->demande(1, $client);
        $this->propositionRepository->method('find')->willReturn($this->proposition(1, $voyage, $demande, $client, $voyageur));

        $this->expectException(BadRequestHttpException::class);
        $this->service->deleteProposition(1, $stranger);
    }

    public function testDeletePropositionRejectsANonPendingProposition(): void
    {
        $voyageur = $this->user(2);
        $client = $this->user(3);
        $voyage = $this->voyage(1, $voyageur);
        $demande = $this->demande(1, $client);
        $this->propositionRepository->method('find')->willReturn($this->proposition(1, $voyage, $demande, $client, $voyageur, statut: 'acceptee'));

        $this->expectException(BadRequestHttpException::class);
        $this->service->deleteProposition(1, $client);
    }

    public function testDeletePropositionCancelsAndPutsTheDemandeBackToSearching(): void
    {
        $voyageur = $this->user(2);
        $client = $this->user(3);
        $voyage = $this->voyage(1, $voyageur);
        $demande = $this->demande(1, $client, statut: 'en_recherche');
        $proposition = $this->proposition(1, $voyage, $demande, $client, $voyageur);
        $this->propositionRepository->method('find')->willReturn($proposition);

        $this->service->deleteProposition(1, $client);

        self::assertSame('annulee', $proposition->getStatut());
        self::assertSame('en_recherche', $demande->getStatut());
    }

    public function testDeletePropositionNeverReopensAnAlreadyCancelledDemande(): void
    {
        $voyageur = $this->user(2);
        $client = $this->user(3);
        $voyage = $this->voyage(1, $voyageur);
        $demande = $this->demande(1, $client, statut: 'annulee');
        $proposition = $this->proposition(1, $voyage, $demande, $client, $voyageur);
        $this->propositionRepository->method('find')->willReturn($proposition);

        $this->service->deleteProposition(1, $client);

        self::assertSame('annulee', $demande->getStatut(), 'regression test du bug de condition corrige (Lot 3) - une demande deja annulee ne doit jamais etre rouverte');
    }

    public function testDeletePropositionNeverReopensAnExpiredDemande(): void
    {
        $voyageur = $this->user(2);
        $client = $this->user(3);
        $voyage = $this->voyage(1, $voyageur);
        $demande = $this->demande(1, $client, statut: 'expiree');
        $proposition = $this->proposition(1, $voyage, $demande, $client, $voyageur);
        $this->propositionRepository->method('find')->willReturn($proposition);

        $this->service->deleteProposition(1, $client);

        self::assertSame('expiree', $demande->getStatut());
    }

    // ==================== convertPropositionAmounts / getPropositionSummaryWithConversion ====================

    public function testConvertPropositionAmountsDelegatesToCurrencyService(): void
    {
        $voyageur = $this->user(2);
        $client = $this->user(3);
        $voyage = $this->voyage(1, $voyageur);
        $demande = $this->demande(1, $client, currency: 'XAF');
        $proposition = $this->proposition(1, $voyage, $demande, $client, $voyageur);
        $this->currencyService->method('convert')->willReturnMap([
            [5.0, 'XAF', 'EUR', 0.0076],
            [1000.0, 'XAF', 'EUR', 1.52],
        ]);
        $this->currencyService->method('formatAmount')->willReturn('formatted');

        $result = $this->service->convertPropositionAmounts($proposition, 'EUR');

        self::assertSame('XAF', $result['originalCurrency']);
        self::assertSame('EUR', $result['targetCurrency']);
        self::assertSame(0.0076, $result['prixParKilo']);
        self::assertSame(1.52, $result['commission']);
    }

    public function testGetPropositionSummaryOmitsConvertedBlockWhenSameCurrency(): void
    {
        $voyageur = $this->user(2);
        $client = $this->user(3);
        $voyage = $this->voyage(1, $voyageur);
        $demande = $this->demande(1, $client, currency: 'XAF');
        $proposition = $this->proposition(1, $voyage, $demande, $client, $voyageur);
        $viewer = $this->user(4);
        $settings = new UserSettings();
        $settings->setUser($viewer);
        $settings->setDevise('XAF');
        $viewer->setSettings($settings);

        $summary = $this->service->getPropositionSummaryWithConversion($proposition, $viewer);

        self::assertArrayNotHasKey('converted', $summary);
    }

    public function testGetPropositionSummaryIncludesConvertedBlockWhenCurrenciesDiffer(): void
    {
        $voyageur = $this->user(2);
        $client = $this->user(3);
        $voyage = $this->voyage(1, $voyageur);
        $demande = $this->demande(1, $client, currency: 'XAF');
        $proposition = $this->proposition(1, $voyage, $demande, $client, $voyageur);
        $viewer = $this->user(4);
        $settings = new UserSettings();
        $settings->setUser($viewer);
        $settings->setDevise('EUR');
        $viewer->setSettings($settings);
        $this->currencyService->method('convert')->willReturn(1.5);
        $this->currencyService->method('formatAmount')->willReturn('1.50 €');

        $summary = $this->service->getPropositionSummaryWithConversion($proposition, $viewer);

        self::assertArrayHasKey('converted', $summary);
        self::assertSame('XAF', $summary['converted']['originalCurrency']);
        self::assertSame('EUR', $summary['converted']['targetCurrency']);
    }

    public function testGetPropositionSummaryFallsBackToDefaultCurrencyWithoutViewerSettings(): void
    {
        $voyageur = $this->user(2);
        $client = $this->user(3);
        $voyage = $this->voyage(1, $voyageur);
        $demande = $this->demande(1, $client, currency: 'XAF');
        $proposition = $this->proposition(1, $voyage, $demande, $client, $voyageur);
        $viewer = $this->user(4);
        $this->currencyService->method('getDefaultCurrency')->willReturn('USD');

        $summary = $this->service->getPropositionSummaryWithConversion($proposition, $viewer);

        self::assertSame('USD', $summary['viewerCurrency']);
    }

    // ==================== getPropositionById ====================

    public function testGetPropositionByIdDelegatesToTheRepository(): void
    {
        $voyageur = $this->user(2);
        $client = $this->user(3);
        $voyage = $this->voyage(1, $voyageur);
        $demande = $this->demande(1, $client);
        $expected = $this->proposition(1, $voyage, $demande, $client, $voyageur);
        $this->propositionRepository->method('find')->with(1)->willReturn($expected);

        self::assertSame($expected, $this->service->getPropositionById(1));
    }

    private function respondDto(string $action, ?string $messageRefus = null): RespondPropositionDTO
    {
        $dto = new RespondPropositionDTO();
        $dto->action = $action;
        $dto->messageRefus = $messageRefus;

        return $dto;
    }
}
