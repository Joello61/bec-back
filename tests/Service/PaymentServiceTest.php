<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\Boost;
use App\Entity\Transaction;
use App\Entity\User;
use App\Repository\TransactionRepository;
use App\Service\PaymentService;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Monétisation Lot 1 : PaymentService::findOrCreateFromProviderEvent() est le point
 * d'idempotence central face aux retries webhook (Stripe/Notch Pay livrent
 * at-least-once) - le test critique est qu'un même providerPaymentId ne crée jamais
 * deux Transaction.
 */
class PaymentServiceTest extends TestCase
{
    private TransactionRepository&\PHPUnit\Framework\MockObject\MockObject $transactionRepository;
    private EntityManagerInterface&\PHPUnit\Framework\MockObject\MockObject $em;
    private PaymentService $paymentService;

    protected function setUp(): void
    {
        $this->transactionRepository = $this->createMock(TransactionRepository::class);
        $this->em = $this->createMock(EntityManagerInterface::class);
        $this->paymentService = new PaymentService($this->em, $this->transactionRepository, new NullLogger());
    }

    private function call(User $user): Transaction
    {
        return $this->paymentService->findOrCreateFromProviderEvent(
            provider: 'stripe',
            providerPaymentId: 'in_123',
            user: $user,
            subscription: null,
            type: Transaction::TYPE_SUBSCRIPTION_INITIAL,
            paymentMethodFamily: Transaction::METHOD_FAMILY_CARD,
            amount: '4.99',
            currency: 'EUR',
            status: Transaction::STATUS_SUCCEEDED,
            rawPayload: ['stripe_event' => 'invoice.paid'],
        );
    }

    public function testCreatesANewTransactionWhenProviderPaymentIdIsUnknown(): void
    {
        $user = new User();
        $this->transactionRepository->method('findByProviderPaymentId')->willReturn(null);
        $this->em->method('wrapInTransaction')->willReturnCallback(fn (callable $fn) => $fn());
        $this->em->expects(self::once())->method('persist');
        $this->em->expects(self::once())->method('flush');

        $transaction = $this->call($user);

        self::assertSame('in_123', $transaction->getProviderPaymentId());
        self::assertSame(Transaction::STATUS_SUCCEEDED, $transaction->getStatus());
    }

    public function testReturnsExistingTransactionWithoutCreatingADuplicateWhenAlreadyKnown(): void
    {
        $user = new User();
        $existing = (new Transaction())->setProvider('stripe')->setProviderPaymentId('in_123');
        $this->transactionRepository->method('findByProviderPaymentId')->willReturn($existing);
        $this->em->expects(self::never())->method('wrapInTransaction');
        $this->em->expects(self::never())->method('persist');

        $result = $this->call($user);

        self::assertSame($existing, $result, 'un providerPaymentId deja connu ne doit jamais recreer de Transaction (idempotence webhook)');
    }

    public function testTwoConcurrentCallsWithTheSamePaymentIdNeverProduceTwoTransactions(): void
    {
        $user = new User();
        $existingAfterRace = (new Transaction())->setProvider('stripe')->setProviderPaymentId('in_123');

        // Simule une course : la lecture initiale ne voit rien, l'insertion echoue sur la
        // contrainte UNIQUE(provider, provider_payment_id) car un autre worker a deja
        // traite le meme evenement entre-temps.
        $this->transactionRepository
            ->method('findByProviderPaymentId')
            ->willReturnOnConsecutiveCalls(null, $existingAfterRace);

        $this->em->method('wrapInTransaction')->willThrowException(
            $this->createMock(UniqueConstraintViolationException::class)
        );

        $result = $this->call($user);

        self::assertSame($existingAfterRace, $result);
    }

    public function testCreatesABoostTransactionWhenBoostIsProvided(): void
    {
        $user = new User();
        $boost = new Boost();
        $this->transactionRepository->method('findByProviderPaymentId')->willReturn(null);
        $this->em->method('wrapInTransaction')->willReturnCallback(fn (callable $fn) => $fn());
        $this->em->expects(self::once())->method('persist');
        $this->em->expects(self::once())->method('flush');

        $transaction = $this->paymentService->findOrCreateFromProviderEvent(
            provider: 'stripe',
            providerPaymentId: 'pi_456',
            user: $user,
            subscription: null,
            type: Transaction::TYPE_BOOST,
            paymentMethodFamily: Transaction::METHOD_FAMILY_CARD,
            amount: '2.99',
            currency: 'EUR',
            status: Transaction::STATUS_SUCCEEDED,
            rawPayload: ['stripe_event' => 'checkout.session.completed'],
            boost: $boost,
        );

        self::assertSame($boost, $transaction->getBoost());
        self::assertNull($transaction->getSubscription());
    }
}
