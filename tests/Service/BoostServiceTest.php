<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\Boost;
use App\Entity\BoostOffer;
use App\Entity\Demande;
use App\Entity\User;
use App\Entity\Voyage;
use App\Repository\BoostOfferRepository;
use App\Repository\BoostRepository;
use App\Repository\DemandeRepository;
use App\Repository\UserSubscriptionRepository;
use App\Repository\VoyageRepository;
use App\Service\BoostService;
use App\Service\Payment\CheckoutSessionResult;
use App\Service\Payment\PaymentProviderInterface;
use App\Service\PaymentService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Monétisation Lot 2 : checkout() fige le montant depuis l'offre, handleCheckoutCompleted()
 * calcule correctement endAt = startAt + durationDays.
 */
class BoostServiceTest extends TestCase
{
    private BoostOfferRepository&\PHPUnit\Framework\MockObject\MockObject $boostOfferRepository;
    private BoostRepository&\PHPUnit\Framework\MockObject\MockObject $boostRepository;
    private VoyageRepository&\PHPUnit\Framework\MockObject\MockObject $voyageRepository;
    private DemandeRepository&\PHPUnit\Framework\MockObject\MockObject $demandeRepository;
    private UserSubscriptionRepository&\PHPUnit\Framework\MockObject\MockObject $userSubscriptionRepository;
    private PaymentProviderInterface&\PHPUnit\Framework\MockObject\MockObject $paymentProvider;
    private EntityManagerInterface&\PHPUnit\Framework\MockObject\MockObject $em;
    private BoostService $service;

    protected function setUp(): void
    {
        $this->boostOfferRepository = $this->createMock(BoostOfferRepository::class);
        $this->boostRepository = $this->createMock(BoostRepository::class);
        $this->voyageRepository = $this->createMock(VoyageRepository::class);
        $this->demandeRepository = $this->createMock(DemandeRepository::class);
        $this->userSubscriptionRepository = $this->createMock(UserSubscriptionRepository::class);
        $this->paymentProvider = $this->createMock(PaymentProviderInterface::class);
        $this->em = $this->createMock(EntityManagerInterface::class);
        $paymentService = $this->createMock(PaymentService::class);

        $this->service = new BoostService(
            $this->em,
            $this->boostOfferRepository,
            $this->boostRepository,
            $this->voyageRepository,
            $this->demandeRepository,
            $this->userSubscriptionRepository,
            $this->paymentProvider,
            $paymentService,
            new NullLogger(),
        );
    }

    private function offer(int $durationDays = 7, string $price = '2.99'): BoostOffer
    {
        $offer = new BoostOffer();
        $offer->setName($durationDays . ' jours')->setDurationDays($durationDays)->setPriceAmountEur($price)->setIsActive(true);

        return $offer;
    }

    public function testCheckoutRejectsAnUnknownOffer(): void
    {
        $this->boostOfferRepository->method('findById')->willReturn(null);

        $this->expectException(NotFoundHttpException::class);

        $this->service->checkout(new User(), BoostService::TARGET_VOYAGE, 1, 99, 'https://ok', 'https://ko');
    }

    public function testCheckoutRejectsAnInvalidTargetType(): void
    {
        $this->boostOfferRepository->method('findById')->willReturn($this->offer());

        $this->expectException(BadRequestHttpException::class);

        $this->service->checkout(new User(), 'invalide', 1, 1, 'https://ok', 'https://ko');
    }

    public function testCheckoutRejectsAnUnknownVoyage(): void
    {
        $this->boostOfferRepository->method('findById')->willReturn($this->offer());
        $this->voyageRepository->method('find')->willReturn(null);

        $this->expectException(NotFoundHttpException::class);

        $this->service->checkout(new User(), BoostService::TARGET_VOYAGE, 1, 1, 'https://ok', 'https://ko');
    }

    public function testCheckoutCreatesAPendingBoostWithAmountFrozenFromTheOfferAndDelegatesToTheProvider(): void
    {
        $user = new User();
        $offer = $this->offer(durationDays: 15, price: '4.99');
        $voyage = new Voyage();
        $voyage->setVilleDepart('Douala')->setVilleArrivee('Paris');

        $this->boostOfferRepository->method('findById')->willReturn($offer);
        $this->voyageRepository->method('find')->willReturn($voyage);
        $this->userSubscriptionRepository->method('findLatestProviderCustomerId')->willReturn(null);

        $capturedBoost = null;
        $this->em->method('persist')->willReturnCallback(function ($entity) use (&$capturedBoost) {
            if ($entity instanceof Boost) {
                $capturedBoost = $entity;
            }
        });

        $this->paymentProvider->expects(self::once())
            ->method('createOneTimeCheckoutSession')
            ->willReturn(new CheckoutSessionResult('https://checkout.stripe.com/session/boost'));

        $result = $this->service->checkout($user, BoostService::TARGET_VOYAGE, 1, 1, 'https://ok', 'https://ko');

        self::assertSame('https://checkout.stripe.com/session/boost', $result->checkoutUrl);
        self::assertNotNull($capturedBoost);
        self::assertSame(Boost::STATUS_PENDING, $capturedBoost->getStatus());
        self::assertSame('4.99', $capturedBoost->getAmount(), 'le montant doit etre fige depuis l\'offre au moment du checkout');
        self::assertSame('EUR', $capturedBoost->getCurrency());
        self::assertSame($voyage, $capturedBoost->getVoyage());
        self::assertNull($capturedBoost->getDemande());
        self::assertNotNull($capturedBoost->getWithdrawalWaiverConsentedAt());
    }

    public function testHandleCheckoutCompletedDoesNothingWhenClientReferenceIsUnknown(): void
    {
        $this->boostRepository->method('find')->willReturn(null);
        $this->em->expects(self::never())->method('flush');

        $this->service->handleCheckoutCompleted(['client_reference_id' => '999', 'payment_intent' => 'pi_123']);
    }

    public function testHandleCheckoutCompletedActivatesTheBoostWithEndAtComputedFromDurationDays(): void
    {
        $offer = $this->offer(durationDays: 15);
        $boost = new Boost();
        $boost->setOffer($offer)
            ->setUser(new User())
            ->setStatus(Boost::STATUS_PENDING)
            ->setAmount('4.99')
            ->setCurrency('EUR');

        $this->boostRepository->method('find')->willReturn($boost);
        $this->em->expects(self::once())->method('flush');

        $this->service->handleCheckoutCompleted(['client_reference_id' => '42', 'payment_intent' => 'pi_456']);

        self::assertSame(Boost::STATUS_ACTIVE, $boost->getStatus());
        self::assertNotNull($boost->getStartAt());
        self::assertNotNull($boost->getEndAt());

        $expectedEndAt = (clone $boost->getStartAt())->modify('+15 days');
        self::assertEquals($expectedEndAt->format('Y-m-d'), $boost->getEndAt()->format('Y-m-d'));
    }
}
