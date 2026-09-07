<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\DTO\CreateDemandeDTO;
use App\DTO\UpdateDemandeDTO;
use App\Entity\Demande;
use App\Entity\Proposition;
use App\Entity\User;
use App\Entity\UserSettings;
use App\Entity\Voyage;
use App\Repository\DemandeRepository;
use App\Service\CurrencyService;
use App\Service\DemandeService;
use App\Service\MatchingService;
use App\Service\NotificationService;
use App\Service\RealtimeNotifier;
use App\Tests\Support\EntityIdTrait;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Phase 4b, Lot 3 (bec-docs/docs/plan-correction/plan-correction-cobage.md) : symetrique de
 * VoyageServiceTest - la cascade de deleteDemande() est le miroir de deleteVoyage(), avec en
 * plus la liberation du poids reserve sur le voyage lie a une proposition acceptee.
 */
class DemandeServiceTest extends TestCase
{
    use EntityIdTrait;

    private EntityManagerInterface&\PHPUnit\Framework\MockObject\MockObject $em;
    private DemandeRepository&\PHPUnit\Framework\MockObject\MockObject $demandeRepository;
    private NotificationService&\PHPUnit\Framework\MockObject\MockObject $notificationService;
    private MatchingService&\PHPUnit\Framework\MockObject\MockObject $matchingService;
    private CurrencyService&\PHPUnit\Framework\MockObject\MockObject $currencyService;
    private RealtimeNotifier&\PHPUnit\Framework\MockObject\MockObject $notifier;
    private DemandeService $service;

    protected function setUp(): void
    {
        $this->em = $this->createMock(EntityManagerInterface::class);
        $this->demandeRepository = $this->createMock(DemandeRepository::class);
        $this->notificationService = $this->createMock(NotificationService::class);
        $this->matchingService = $this->createMock(MatchingService::class);
        $this->currencyService = $this->createMock(CurrencyService::class);
        $this->notifier = $this->createMock(RealtimeNotifier::class);

        $this->service = new DemandeService(
            $this->em,
            $this->demandeRepository,
            $this->notificationService,
            $this->matchingService,
            $this->currencyService,
            $this->notifier,
            new NullLogger(),
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

    private function demande(int $id, User $client, string $statut = 'en_recherche', string $poidsEstime = '10'): Demande
    {
        $demande = new Demande();
        $demande->setClient($client);
        $demande->setVilleDepart('Douala');
        $demande->setVilleArrivee('Paris');
        $demande->setStatut($statut);
        $demande->setPoidsEstime($poidsEstime);
        $demande->setCurrency('XAF');
        $this->setEntityId($demande, $id);

        return $demande;
    }

    private function voyage(int $id, User $voyageur, string $statut = 'actif', string $poidsRestant = '0'): Voyage
    {
        $voyage = new Voyage();
        $voyage->setVoyageur($voyageur);
        $voyage->setVilleDepart('Douala');
        $voyage->setVilleArrivee('Paris');
        $voyage->setDateDepart(new \DateTime('+5 days'));
        $voyage->setDateArrivee(new \DateTime('+6 days'));
        $voyage->setStatut($statut);
        $voyage->setPoidsDisponible('20');
        $voyage->setPoidsDisponibleRestant($poidsRestant);
        $this->setEntityId($voyage, $id);

        return $voyage;
    }

    private function proposition(Demande $demande, Voyage $voyage, string $statut): Proposition
    {
        $proposition = new Proposition();
        $proposition->setDemande($demande);
        $proposition->setVoyage($voyage);
        $proposition->setClient($demande->getClient());
        $proposition->setVoyageur($voyage->getVoyageur());
        $proposition->setPrixParKilo('5');
        $proposition->setCommissionProposeePourUnBagage('100');
        $proposition->setCurrency('XAF');
        $proposition->setStatut($statut);
        $demande->getPropositions()->add($proposition);

        return $proposition;
    }

    // ==================== createDemande ====================

    private function createDto(): CreateDemandeDTO
    {
        $dto = new CreateDemandeDTO();
        $dto->villeDepart = 'Douala';
        $dto->villeArrivee = 'Paris';
        $dto->dateLimite = null;
        $dto->poidsEstime = 10.0;
        $dto->prixParKilo = null;
        $dto->commissionProposeePourUnBagage = null;
        $dto->description = 'description de test';

        return $dto;
    }

    public function testCreateDemandeUsesTheUserSettingsCurrency(): void
    {
        $this->currencyService->method('isSupported')->willReturn(true);
        $user = $this->user(1);
        $settings = new UserSettings();
        $settings->setUser($user);
        $settings->setDevise('EUR');
        $user->setSettings($settings);

        $result = $this->service->createDemande($this->createDto(), $user);

        self::assertSame('EUR', $result->getCurrency());
        self::assertSame('en_recherche', $result->getStatut());
    }

    public function testCreateDemandeRejectsAnUnsupportedCurrency(): void
    {
        $this->currencyService->method('isSupported')->willReturn(false);
        $user = $this->user(1);
        $settings = new UserSettings();
        $settings->setUser($user);
        $settings->setDevise('XYZ');
        $user->setSettings($settings);

        $this->expectException(BadRequestHttpException::class);
        $this->service->createDemande($this->createDto(), $user);
    }

    public function testCreateDemandeFallsBackToDefaultCurrencyWithoutSettings(): void
    {
        $this->currencyService->method('isSupported')->willReturn(true);
        $this->currencyService->method('getDefaultCurrency')->willReturn('USD');
        $user = $this->user(1);

        $result = $this->service->createDemande($this->createDto(), $user);

        self::assertSame('USD', $result->getCurrency());
    }

    // ==================== updateDemande ====================

    public function testUpdateDemandeThrowsWhenNotFound(): void
    {
        $this->demandeRepository->method('find')->willReturn(null);

        $this->expectException(NotFoundHttpException::class);
        $this->service->updateDemande(999, new UpdateDemandeDTO());
    }

    public function testUpdateDemandeAppliesSimpleFieldChangesButNeverTheCurrency(): void
    {
        $demande = $this->demande(1, $this->user(1));
        $this->demandeRepository->method('find')->willReturn($demande);
        $dto = new UpdateDemandeDTO();
        $dto->description = 'nouvelle description';

        $result = $this->service->updateDemande(1, $dto);

        self::assertSame('nouvelle description', $result->getDescription());
        self::assertSame('XAF', $result->getCurrency(), 'la devise d\'une demande ne doit jamais etre modifiable apres creation');
    }

    // ==================== updateStatut ====================

    public function testUpdateStatutSetsTheNewStatus(): void
    {
        $demande = $this->demande(1, $this->user(1));
        $this->demandeRepository->method('find')->willReturn($demande);

        $result = $this->service->updateStatut(1, 'voyageur_trouve');

        self::assertSame('voyageur_trouve', $result->getStatut());
    }

    // ==================== deleteDemande ====================

    public function testDeleteDemandeIsANoopWhenAlreadyCancelled(): void
    {
        $demande = $this->demande(1, $this->user(1), statut: 'annulee');
        $this->demandeRepository->method('find')->willReturn($demande);
        $this->em->expects(self::never())->method('flush');

        $this->service->deleteDemande(1);
    }

    public function testDeleteDemandeIsANoopWhenAlreadyExpired(): void
    {
        $demande = $this->demande(1, $this->user(1), statut: 'expiree');
        $this->demandeRepository->method('find')->willReturn($demande);
        $this->em->expects(self::never())->method('flush');

        $this->service->deleteDemande(1);
    }

    public function testDeleteDemandeReleasesReservedWeightOnAnAcceptedProposition(): void
    {
        $client = $this->user(1);
        $voyageur = $this->user(2);
        $demande = $this->demande(1, $client, poidsEstime: '8');
        $voyage = $this->voyage(10, $voyageur, statut: 'actif', poidsRestant: '2');
        $proposition = $this->proposition($demande, $voyage, 'acceptee');
        $this->demandeRepository->method('find')->willReturn($demande);

        $this->service->deleteDemande(1);

        self::assertSame('10.00', $voyage->getPoidsDisponibleRestant(), '2 restant + 8 libere par l\'annulation = 10');
        self::assertSame('annulee', $proposition->getStatut());
    }

    public function testDeleteDemandeReactivatesACompleteVoyageWhenWeightIsFreed(): void
    {
        $client = $this->user(1);
        $voyageur = $this->user(2);
        $demande = $this->demande(1, $client, poidsEstime: '10');
        $voyage = $this->voyage(10, $voyageur, statut: 'complete', poidsRestant: '0');
        $this->proposition($demande, $voyage, 'acceptee');
        $this->demandeRepository->method('find')->willReturn($demande);

        $this->service->deleteDemande(1);

        self::assertSame('actif', $voyage->getStatut());
    }

    public function testDeleteDemandeCancelsAPendingPropositionWithoutTouchingVoyageWeight(): void
    {
        $client = $this->user(1);
        $voyageur = $this->user(2);
        $demande = $this->demande(1, $client, poidsEstime: '8');
        $voyage = $this->voyage(10, $voyageur, statut: 'actif', poidsRestant: '12');
        $proposition = $this->proposition($demande, $voyage, 'en_attente');
        $this->demandeRepository->method('find')->willReturn($demande);
        $this->notificationService->expects(self::once())->method('createNotification');

        $this->service->deleteDemande(1);

        self::assertSame('annulee', $proposition->getStatut());
        self::assertSame('12', $voyage->getPoidsDisponibleRestant(), 'une proposition en_attente ne reservait aucun poids, rien a liberer');
    }

    public function testDeleteDemandeLeavesAnAlreadyCancelledPropositionUntouched(): void
    {
        $client = $this->user(1);
        $voyageur = $this->user(2);
        $demande = $this->demande(1, $client);
        $voyage = $this->voyage(10, $voyageur);
        $proposition = $this->proposition($demande, $voyage, 'annulee');
        $this->demandeRepository->method('find')->willReturn($demande);
        $this->notificationService->expects(self::never())->method('createNotification');

        $this->service->deleteDemande(1);

        self::assertSame('annulee', $proposition->getStatut());
    }

    // ==================== findMatchingVoyages ====================

    public function testFindMatchingVoyagesNotifiesWhenMatchesFound(): void
    {
        $client = $this->user(1);
        $demande = $this->demande(1, $client);
        $this->demandeRepository->method('find')->willReturn($demande);
        $this->matchingService->method('findBestMatchesVoyages')->willReturn([['voyage' => new Voyage(), 'score' => 90]]);
        $this->notifier->expects(self::once())->method('publishToUser');

        $result = $this->service->findMatchingVoyages(1);

        self::assertCount(1, $result);
    }

    public function testFindMatchingVoyagesSkipsNotificationWhenNoMatches(): void
    {
        $client = $this->user(1);
        $demande = $this->demande(1, $client);
        $this->demandeRepository->method('find')->willReturn($demande);
        $this->matchingService->method('findBestMatchesVoyages')->willReturn([]);
        $this->notifier->expects(self::never())->method('publishToUser');

        $this->service->findMatchingVoyages(1);
    }

    // ==================== convertDemandeAmounts ====================

    public function testConvertDemandeAmountsConvertsPriceAndCommissionWhenPresent(): void
    {
        $demande = $this->demande(1, $this->user(1));
        $demande->setPrixParKilo('5');
        $demande->setCommissionProposeePourUnBagage('1000');
        $this->currencyService->method('convert')->willReturn(1.5);
        $this->currencyService->method('formatAmount')->willReturn('formatted');

        $result = $this->service->convertDemandeAmounts($demande, 'EUR');

        self::assertArrayHasKey('prixParKilo', $result);
        self::assertArrayHasKey('commission', $result);
    }

    public function testConvertDemandeAmountsOmitsAbsentFields(): void
    {
        $demande = $this->demande(1, $this->user(1));
        $this->currencyService->expects(self::never())->method('convert');

        $result = $this->service->convertDemandeAmounts($demande, 'EUR');

        self::assertArrayNotHasKey('prixParKilo', $result);
        self::assertArrayNotHasKey('commission', $result);
    }

    // ==================== getDemande ====================

    public function testGetDemandeThrowsWhenNotFound(): void
    {
        $this->demandeRepository->method('find')->willReturn(null);

        $this->expectException(NotFoundHttpException::class);
        $this->service->getDemande(999);
    }
}
