<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\DTO\CreateVoyageDTO;
use App\DTO\UpdateVoyageDTO;
use App\Entity\Demande;
use App\Entity\Proposition;
use App\Entity\User;
use App\Entity\UserSettings;
use App\Entity\Voyage;
use App\Repository\VoyageRepository;
use App\Service\CurrencyService;
use App\Service\MatchingService;
use App\Service\NotificationService;
use App\Service\RealtimeNotifier;
use App\Service\VoyageService;
use App\Tests\Support\EntityIdTrait;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Phase 4b, Lot 3 (bec-docs/docs/plan-correction/plan-correction-cobage.md) : symetrique de
 * DemandeServiceTest, avec en plus la validation de capacite propre a updateVoyage() (le
 * poids disponible ne peut jamais descendre sous ce qui est deja reserve par des
 * propositions acceptees).
 */
class VoyageServiceTest extends TestCase
{
    use EntityIdTrait;

    private EntityManagerInterface&\PHPUnit\Framework\MockObject\MockObject $em;
    private VoyageRepository&\PHPUnit\Framework\MockObject\MockObject $voyageRepository;
    private NotificationService&\PHPUnit\Framework\MockObject\MockObject $notificationService;
    private MatchingService&\PHPUnit\Framework\MockObject\MockObject $matchingService;
    private CurrencyService&\PHPUnit\Framework\MockObject\MockObject $currencyService;
    private RealtimeNotifier&\PHPUnit\Framework\MockObject\MockObject $notifier;
    private VoyageService $service;

    protected function setUp(): void
    {
        $this->em = $this->createMock(EntityManagerInterface::class);
        $this->voyageRepository = $this->createMock(VoyageRepository::class);
        $this->notificationService = $this->createMock(NotificationService::class);
        $this->matchingService = $this->createMock(MatchingService::class);
        $this->currencyService = $this->createMock(CurrencyService::class);
        $this->notifier = $this->createMock(RealtimeNotifier::class);

        $this->service = new VoyageService(
            $this->em,
            $this->voyageRepository,
            $this->notificationService,
            $this->matchingService,
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
        $voyage->setDateArrivee(new \DateTime('+6 days'));
        $voyage->setStatut($statut);
        $voyage->setPoidsDisponible($poidsDisponible);
        $voyage->setPoidsDisponibleRestant($poidsRestant ?? $poidsDisponible);
        $voyage->setCurrency('XAF');
        $this->setEntityId($voyage, $id);

        return $voyage;
    }

    private function acceptedProposition(Voyage $voyage, float $poidsEstime): Proposition
    {
        $demande = new Demande();
        $demande->setClient($this->user(random_int(100, 999)));
        $demande->setVilleDepart('Douala');
        $demande->setVilleArrivee('Paris');
        $demande->setPoidsEstime((string) $poidsEstime);

        $proposition = new Proposition();
        $proposition->setVoyage($voyage);
        $proposition->setDemande($demande);
        $proposition->setClient($demande->getClient());
        $proposition->setVoyageur($voyage->getVoyageur());
        $proposition->setPrixParKilo('5');
        $proposition->setCommissionProposeePourUnBagage('100');
        $proposition->setCurrency('XAF');
        $proposition->setStatut('acceptee');
        $voyage->getPropositions()->add($proposition);

        return $proposition;
    }

    // ==================== createVoyage ====================

    private function createDto(): CreateVoyageDTO
    {
        $dto = new CreateVoyageDTO();
        $dto->villeDepart = 'Douala';
        $dto->villeArrivee = 'Paris';
        $dto->dateDepart = new \DateTime('+5 days');
        $dto->dateArrivee = new \DateTime('+6 days');
        $dto->poidsDisponible = 20.0;
        $dto->prixParKilo = null;
        $dto->commissionProposeePourUnBagage = null;
        $dto->description = null;

        return $dto;
    }

    public function testCreateVoyageUsesTheUserSettingsCurrency(): void
    {
        $this->currencyService->method('isSupported')->willReturn(true);
        $user = $this->user(1);
        $settings = new UserSettings();
        $settings->setUser($user);
        $settings->setDevise('EUR');
        $user->setSettings($settings);

        $result = $this->service->createVoyage($this->createDto(), $user);

        self::assertSame('EUR', $result->getCurrency());
    }

    public function testCreateVoyageRejectsAnUnsupportedCurrency(): void
    {
        $this->currencyService->method('isSupported')->willReturn(false);
        $user = $this->user(1);
        $settings = new UserSettings();
        $settings->setUser($user);
        $settings->setDevise('XYZ');
        $user->setSettings($settings);

        $this->expectException(BadRequestHttpException::class);
        $this->service->createVoyage($this->createDto(), $user);
    }

    public function testCreateVoyageFallsBackToDefaultCurrencyWithoutSettings(): void
    {
        $this->currencyService->method('isSupported')->willReturn(true);
        $this->currencyService->method('getDefaultCurrency')->willReturn('USD');
        $user = $this->user(1);

        $result = $this->service->createVoyage($this->createDto(), $user);

        self::assertSame('USD', $result->getCurrency());
    }

    public function testCreateVoyageInitializesRemainingWeightToTheFullAmount(): void
    {
        $this->currencyService->method('isSupported')->willReturn(true);
        $user = $this->user(1);
        $dto = $this->createDto();
        $dto->poidsDisponible = 15.0;

        $result = $this->service->createVoyage($dto, $user);

        self::assertSame('15', $result->getPoidsDisponible());
        self::assertSame('15', $result->getPoidsDisponibleRestant());
    }

    // ==================== updateVoyage ====================

    private function updateDto(): UpdateVoyageDTO
    {
        return new UpdateVoyageDTO();
    }

    public function testUpdateVoyageThrowsWhenNotFound(): void
    {
        $this->voyageRepository->method('find')->willReturn(null);

        $this->expectException(NotFoundHttpException::class);
        $this->service->updateVoyage(999, $this->updateDto());
    }

    public function testUpdateVoyageAppliesSimpleFieldChanges(): void
    {
        $voyage = $this->voyage(1, $this->user(1));
        $this->voyageRepository->method('find')->willReturn($voyage);
        $dto = $this->updateDto();
        $dto->description = 'nouvelle description';

        $result = $this->service->updateVoyage(1, $dto);

        self::assertSame('nouvelle description', $result->getDescription());
    }

    public function testUpdateVoyageReducesWeightWithoutConflict(): void
    {
        $voyage = $this->voyage(1, $this->user(1), poidsDisponible: '20', poidsRestant: '20');
        $this->voyageRepository->method('find')->willReturn($voyage);
        $dto = $this->updateDto();
        $dto->poidsDisponible = 10.0;

        $result = $this->service->updateVoyage(1, $dto);

        self::assertSame('10', $result->getPoidsDisponible());
        self::assertSame('10', $result->getPoidsDisponibleRestant());
    }

    public function testUpdateVoyageRejectsReducingBelowAlreadyReservedWeight(): void
    {
        $voyage = $this->voyage(1, $this->user(1), poidsDisponible: '20', poidsRestant: '20');
        $this->acceptedProposition($voyage, 12.0);
        $this->voyageRepository->method('find')->willReturn($voyage);
        $dto = $this->updateDto();
        $dto->poidsDisponible = 10.0;

        $this->expectException(BadRequestHttpException::class);
        $this->service->updateVoyage(1, $dto);
    }

    public function testUpdateVoyageSumsMultipleAcceptedPropositions(): void
    {
        $voyage = $this->voyage(1, $this->user(1), poidsDisponible: '20', poidsRestant: '20');
        $this->acceptedProposition($voyage, 5.0);
        $this->acceptedProposition($voyage, 5.0);
        $this->voyageRepository->method('find')->willReturn($voyage);
        $dto = $this->updateDto();
        $dto->poidsDisponible = 10.0;

        $result = $this->service->updateVoyage(1, $dto);

        self::assertSame('10', $result->getPoidsDisponible());
        self::assertSame('0', $result->getPoidsDisponibleRestant(), '10 total - 10 deja reserve (5+5) = 0 restant');
    }

    public function testUpdateVoyageIgnoresNonAcceptedPropositionsInTheReservedCalculation(): void
    {
        $voyage = $this->voyage(1, $this->user(1), poidsDisponible: '20', poidsRestant: '20');
        $proposition = $this->acceptedProposition($voyage, 5.0);
        $proposition->setStatut('en_attente');
        $this->voyageRepository->method('find')->willReturn($voyage);
        $dto = $this->updateDto();
        $dto->poidsDisponible = 2.0;

        // aucune proposition acceptee : 2 kg ne doit pas etre rejete
        $result = $this->service->updateVoyage(1, $dto);

        self::assertSame('2', $result->getPoidsDisponible());
    }

    // ==================== updateStatut ====================

    public function testUpdateStatutSetsTheNewStatus(): void
    {
        $voyage = $this->voyage(1, $this->user(1));
        $this->voyageRepository->method('find')->willReturn($voyage);

        $result = $this->service->updateStatut(1, 'complet');

        self::assertSame('complet', $result->getStatut());
    }

    public function testUpdateStatutHandlesCancellation(): void
    {
        $voyage = $this->voyage(1, $this->user(1));
        $this->voyageRepository->method('find')->willReturn($voyage);

        $result = $this->service->updateStatut(1, 'annule');

        self::assertSame('annule', $result->getStatut());
    }

    public function testUpdateStatutHandlesExpiration(): void
    {
        $voyage = $this->voyage(1, $this->user(1));
        $this->voyageRepository->method('find')->willReturn($voyage);

        $result = $this->service->updateStatut(1, 'expire');

        self::assertSame('expire', $result->getStatut());
    }

    public function testUpdateStatutHandlesAnArbitraryStatus(): void
    {
        $voyage = $this->voyage(1, $this->user(1));
        $this->voyageRepository->method('find')->willReturn($voyage);

        $result = $this->service->updateStatut(1, 'actif');

        self::assertSame('actif', $result->getStatut());
    }

    // ==================== deleteVoyage ====================

    public function testDeleteVoyageIsANoopWhenAlreadyCancelled(): void
    {
        $voyage = $this->voyage(1, $this->user(1), statut: 'annule');
        $this->voyageRepository->method('find')->willReturn($voyage);
        $this->em->expects(self::never())->method('flush');

        $this->service->deleteVoyage(1);
    }

    public function testDeleteVoyageIsANoopWhenAlreadyExpired(): void
    {
        $voyage = $this->voyage(1, $this->user(1), statut: 'expire');
        $this->voyageRepository->method('find')->willReturn($voyage);
        $this->em->expects(self::never())->method('flush');

        $this->service->deleteVoyage(1);
    }

    public function testDeleteVoyageCancelsTheVoyageAndLinkedPendingDemande(): void
    {
        $voyageur = $this->user(1);
        $voyage = $this->voyage(1, $voyageur);
        $proposition = $this->acceptedProposition($voyage, 5.0);
        $proposition->getDemande()->setStatut('voyageur_trouve');
        $this->voyageRepository->method('find')->willReturn($voyage);

        $this->service->deleteVoyage(1);

        self::assertSame('annule', $voyage->getStatut());
        self::assertSame('annulee', $proposition->getStatut());
        self::assertSame('en_recherche', $proposition->getDemande()->getStatut());
    }

    public function testDeleteVoyageNeverReopensAnAlreadyCancelledDemande(): void
    {
        $voyageur = $this->user(1);
        $voyage = $this->voyage(1, $voyageur);
        $proposition = $this->acceptedProposition($voyage, 5.0);
        $proposition->getDemande()->setStatut('annulee');
        $this->voyageRepository->method('find')->willReturn($voyage);

        $this->service->deleteVoyage(1);

        self::assertSame('annulee', $proposition->getDemande()->getStatut());
    }

    public function testDeleteVoyageLeavesAnAlreadyCancelledPropositionUntouched(): void
    {
        $voyageur = $this->user(1);
        $voyage = $this->voyage(1, $voyageur);
        $proposition = $this->acceptedProposition($voyage, 5.0);
        $proposition->setStatut('annulee');
        $proposition->getDemande()->setStatut('annulee');
        $this->voyageRepository->method('find')->willReturn($voyage);

        $this->service->deleteVoyage(1);

        self::assertSame('annulee', $proposition->getStatut());
    }

    // ==================== convertVoyageAmounts ====================

    public function testConvertVoyageAmountsConvertsPriceAndCommissionWhenPresent(): void
    {
        $voyage = $this->voyage(1, $this->user(1));
        $voyage->setPrixParKilo('5');
        $voyage->setCommissionProposeePourUnBagage('1000');
        $this->currencyService->method('convert')->willReturn(1.5);
        $this->currencyService->method('formatAmount')->willReturn('formatted');

        $result = $this->service->convertVoyageAmounts($voyage, 'EUR');

        self::assertArrayHasKey('prixParKilo', $result);
        self::assertArrayHasKey('commission', $result);
    }

    public function testConvertVoyageAmountsOmitsAbsentFields(): void
    {
        $voyage = $this->voyage(1, $this->user(1));
        $this->currencyService->expects(self::never())->method('convert');

        $result = $this->service->convertVoyageAmounts($voyage, 'EUR');

        self::assertArrayNotHasKey('prixParKilo', $result);
        self::assertArrayNotHasKey('commission', $result);
    }

    // ==================== getVoyage / findMatchingDemandes ====================

    public function testGetVoyageThrowsWhenNotFound(): void
    {
        $this->voyageRepository->method('find')->willReturn(null);

        $this->expectException(NotFoundHttpException::class);
        $this->service->getVoyage(999);
    }

    public function testFindMatchingDemandesDelegatesToMatchingService(): void
    {
        $voyage = $this->voyage(1, $this->user(1));
        $this->voyageRepository->method('find')->willReturn($voyage);
        $this->matchingService->expects(self::once())->method('findBestMatchesDemandes')->with($voyage, null)->willReturn(['result']);

        self::assertSame(['result'], $this->service->findMatchingDemandes(1));
    }
}
