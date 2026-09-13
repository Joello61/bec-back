<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\Transaction;
use App\Entity\User;
use App\Repository\TransactionRepository;
use App\Service\InvoiceService;
use Doctrine\ORM\EntityManagerInterface;
use League\Flysystem\Filesystem;
use League\Flysystem\FilesystemOperator;
use League\Flysystem\Local\LocalFilesystemAdapter;
use League\Flysystem\UnableToWriteFile;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Lot N4 (plan-complements-monetisation-cobage.md) : generation et stockage durable
 * d'une facture PDF par transaction. Storage reel (Flysystem local sur un repertoire
 * temporaire), meme patron que AvatarServiceTest - seuls TransactionRepository/
 * EntityManagerInterface sont mockes (acces DB non necessaire pour tester la generation).
 */
class InvoiceServiceTest extends TestCase
{
    private string $storageDir;
    private TransactionRepository&\PHPUnit\Framework\MockObject\MockObject $transactionRepository;
    private EntityManagerInterface&\PHPUnit\Framework\MockObject\MockObject $entityManager;
    private LoggerInterface&\PHPUnit\Framework\MockObject\MockObject $logger;

    protected function setUp(): void
    {
        $this->storageDir = sys_get_temp_dir() . '/invoice-test-' . uniqid();
        mkdir($this->storageDir, 0777, true);
        $this->transactionRepository = $this->createMock(TransactionRepository::class);
        $this->entityManager = $this->createMock(EntityManagerInterface::class);
        $this->logger = $this->createMock(LoggerInterface::class);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->storageDir)) {
            foreach (glob($this->storageDir . '/*') ?: [] as $file) {
                @unlink($file);
            }
            @rmdir($this->storageDir);
        }
    }

    private function storage(): FilesystemOperator
    {
        return new Filesystem(new LocalFilesystemAdapter($this->storageDir));
    }

    private function service(?FilesystemOperator $storage = null): InvoiceService
    {
        return new InvoiceService(
            $storage ?? $this->storage(),
            $this->transactionRepository,
            $this->entityManager,
            $this->logger,
        );
    }

    private function transaction(): Transaction
    {
        $user = new User();
        $user->setEmail('client@example.test');
        $user->setNom('Doe');
        $user->setPrenom('Jane');
        $user->setPassword('irrelevant');

        $transaction = new Transaction();
        $transaction->setUser($user)
            ->setType(Transaction::TYPE_BOOST)
            ->setProvider('stripe')
            ->setProviderPaymentId('pi_test_' . uniqid())
            ->setPaymentMethodFamily(Transaction::METHOD_FAMILY_CARD)
            ->setAmount('2.99')
            ->setCurrency('EUR')
            ->setStatus(Transaction::STATUS_SUCCEEDED);

        return $transaction;
    }

    public function testEnsureGeneratedCreatesAPdfAndSetsTheInvoiceNumberOnTheTransaction(): void
    {
        $this->transactionRepository->method('countInvoicedTransactionsForYear')->willReturn(0);
        $this->entityManager->expects(self::once())->method('flush');

        $transaction = $this->transaction();
        $path = $this->service()->ensureGenerated($transaction);

        self::assertMatchesRegularExpression('/^INV-\d{4}-00001\.pdf$/', $path);
        self::assertSame($path, $transaction->getInvoiceStoragePath());
        self::assertStringStartsWith('INV-', (string) $transaction->getInvoiceNumber());
        self::assertFileExists($this->storageDir . '/' . $path);
        self::assertStringStartsWith('%PDF', file_get_contents($this->storageDir . '/' . $path));
    }

    public function testEnsureGeneratedIsIdempotentWhenAlreadyGenerated(): void
    {
        $this->transactionRepository->expects(self::never())->method('countInvoicedTransactionsForYear');
        $this->entityManager->expects(self::never())->method('flush');

        $transaction = $this->transaction();
        $transaction->setInvoiceNumber('INV-2026-00042');
        $transaction->setInvoiceStoragePath('INV-2026-00042.pdf');

        $path = $this->service()->ensureGenerated($transaction);

        self::assertSame('INV-2026-00042.pdf', $path);
    }

    public function testGetContentReturnsTheStoredPdfBytes(): void
    {
        $this->transactionRepository->method('countInvoicedTransactionsForYear')->willReturn(4);

        $transaction = $this->transaction();
        $content = $this->service()->getContent($transaction);

        self::assertStringStartsWith('%PDF', $content);
    }

    public function testEnsureGeneratedThrowsARuntimeExceptionWhenStorageWriteFails(): void
    {
        $this->transactionRepository->method('countInvoicedTransactionsForYear')->willReturn(0);
        $failingStorage = $this->createStub(FilesystemOperator::class);
        $failingStorage->method('write')->willThrowException(
            UnableToWriteFile::atLocation('facture.pdf', 'erreur simulée pour ce test')
        );
        $this->logger->expects(self::once())->method('error');

        $this->expectException(\RuntimeException::class);
        $this->service($failingStorage)->ensureGenerated($this->transaction());
    }
}
