<?php

declare(strict_types=1);

namespace App\Tests\Service\Admin;

use App\Entity\Boost;
use App\Entity\Transaction;
use App\Entity\User;
use App\Entity\UserSubscription;
use App\Repository\TransactionRepository;
use App\Service\Admin\AuditLogService;
use App\Service\Admin\RefundService;
use App\Service\Payment\PaymentProviderInterface;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;

/**
 * Lot 6.1 (monétisation) : remboursement admin, toujours total. Le provider est mocké
 * (jamais de vrai appel Stripe/Notch Pay dans les tests unitaires) - la logique testée
 * ici est la validation métier et les effets de bord (statut Transaction/abonnement/boost),
 * pas l'intégration réseau elle-même.
 */
class RefundServiceTest extends TestCase
{
    private EntityManagerInterface&\PHPUnit\Framework\MockObject\MockObject $em;
    private TransactionRepository&\PHPUnit\Framework\MockObject\MockObject $transactionRepository;
    private PaymentProviderInterface&\PHPUnit\Framework\MockObject\MockObject $paymentProvider;
    private AuditLogService&\PHPUnit\Framework\MockObject\MockObject $auditLogService;
    private RefundService $service;

    protected function setUp(): void
    {
        $this->em = $this->createMock(EntityManagerInterface::class);
        $this->transactionRepository = $this->createMock(TransactionRepository::class);
        $this->paymentProvider = $this->createMock(PaymentProviderInterface::class);
        $this->auditLogService = $this->createMock(AuditLogService::class);

        $paymentProviders = $this->createMock(ContainerInterface::class);
        $paymentProviders->method('has')->willReturn(true);
        $paymentProviders->method('get')->willReturn($this->paymentProvider);

        $this->service = new RefundService(
            $this->em,
            $this->transactionRepository,
            $paymentProviders,
            $this->auditLogService,
        );
    }

    private function succeededTransaction(): Transaction
    {
        $transaction = new Transaction();
        $transaction->setType(Transaction::TYPE_BOOST)
            ->setProvider('stripe')
            ->setProviderPaymentId('pi_123')
            ->setPaymentMethodFamily(Transaction::METHOD_FAMILY_CARD)
            ->setAmount('10.00')
            ->setCurrency('EUR')
            ->setStatus(Transaction::STATUS_SUCCEEDED);

        return $transaction;
    }

    public function testRefundThrowsWhenTransactionNotFound(): void
    {
        $this->transactionRepository->method('find')->with(1)->willReturn(null);

        $this->expectException(\InvalidArgumentException::class);

        $this->service->refund(1, new User());
    }

    public function testRefundRejectsATransactionNotSucceeded(): void
    {
        $transaction = $this->succeededTransaction();
        $transaction->setStatus(Transaction::STATUS_REFUNDED);
        $this->transactionRepository->method('find')->with(1)->willReturn($transaction);

        $this->paymentProvider->expects($this->never())->method('refundTransaction');

        $this->expectException(\InvalidArgumentException::class);

        $this->service->refund(1, new User());
    }

    public function testRefundCallsProviderAndMarksTransactionRefunded(): void
    {
        $transaction = $this->succeededTransaction();
        $this->transactionRepository->method('find')->with(1)->willReturn($transaction);

        $this->paymentProvider->expects($this->once())
            ->method('refundTransaction')
            ->with($transaction);

        $this->auditLogService->expects($this->once())
            ->method('logAdminAction')
            ->with($this->isInstanceOf(User::class), 'refund_transaction', 'transaction', 1, $this->anything());

        $result = $this->service->refund(1, new User(), 'Erreur de facturation');

        $this->assertSame(Transaction::STATUS_REFUNDED, $result->getStatus());
        $this->assertNotNull($result->getRefundedAt());
    }

    public function testRefundCancelsTheLinkedSubscription(): void
    {
        $transaction = $this->succeededTransaction();
        $transaction->setType(Transaction::TYPE_SUBSCRIPTION_INITIAL);

        $subscription = new UserSubscription();
        $subscription->setStatus(UserSubscription::STATUS_ACTIVE);
        $transaction->setSubscription($subscription);

        $this->transactionRepository->method('find')->with(1)->willReturn($transaction);

        $this->service->refund(1, new User());

        $this->assertSame(UserSubscription::STATUS_CANCELED, $subscription->getStatus());
    }

    public function testRefundExpiresTheLinkedBoost(): void
    {
        $transaction = $this->succeededTransaction();

        $boost = new Boost();
        $boost->setStatus(Boost::STATUS_ACTIVE);
        $transaction->setBoost($boost);

        $this->transactionRepository->method('find')->with(1)->willReturn($transaction);

        $this->service->refund(1, new User());

        $this->assertSame(Boost::STATUS_EXPIRED, $boost->getStatus());
        $this->assertNotNull($boost->getEndAt());
    }
}
