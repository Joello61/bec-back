<?php

declare(strict_types=1);

namespace App\Tests\Functional\Admin;

use App\Entity\Transaction;
use App\Entity\User;
use App\Tests\Support\JwtAuthenticationTrait;
use App\Tests\Support\UserFactoryTrait;
use Doctrine\ORM\EntityManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Lot 6.1 (monétisation) : liste admin des transactions et remboursement. Le chemin
 * "remboursement réussi" (appel réseau réel au prestataire) est couvert par
 * RefundServiceTest (provider mocké) - même patron que BoostControllerTest : les tests
 * fonctionnels ici couvrent l'autorisation et la validation, jamais un vrai appel Stripe/
 * Notch Pay (aucun compte sandbox disponible, cf. mémoire du projet).
 */
class AdminTransactionControllerTest extends WebTestCase
{
    use UserFactoryTrait;
    use JwtAuthenticationTrait;

    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private JWTTokenManagerInterface $jwtManager;

    protected function setUp(): void
    {
        self::ensureKernelShutdown();
        $this->client = static::createClient();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->jwtManager = static::getContainer()->get('lexik_jwt_authentication.jwt_manager');
    }

    private function admin(string $prefix = 'admin-tx-admin'): User
    {
        $admin = $this->createUser($prefix);
        $admin->setRoles(['ROLE_ADMIN']);
        $this->em->flush();

        return $admin;
    }

    private function transaction(User $user, string $status = Transaction::STATUS_SUCCEEDED): Transaction
    {
        $transaction = new Transaction();
        $transaction->setUser($user)
            ->setType(Transaction::TYPE_BOOST)
            ->setProvider('stripe')
            ->setProviderPaymentId('pi_test_' . uniqid())
            ->setPaymentMethodFamily(Transaction::METHOD_FAMILY_CARD)
            ->setAmount('2.99')
            ->setCurrency('EUR')
            ->setStatus($status);

        $this->em->persist($transaction);
        $this->em->flush();

        return $transaction;
    }

    // ==================== auth requise ====================

    public function testListRejectsANonAdminUser(): void
    {
        $this->authenticateAs($this->createUser('admin-tx-nonadmin'));

        $this->client->request('GET', '/api/admin/transactions');

        self::assertResponseStatusCodeSame(403);
    }

    public function testRefundRejectsANonAdminUser(): void
    {
        $user = $this->createUser('admin-tx-nonadmin2');
        $transaction = $this->transaction($user);
        $this->authenticateAs($user);

        $this->client->request('POST', '/api/admin/transactions/' . $transaction->getId() . '/refund');

        self::assertResponseStatusCodeSame(403);
    }

    // ==================== list ====================

    public function testListReturnsTransactionsForAnAdmin(): void
    {
        $user = $this->createUser('admin-tx-owner');
        $this->transaction($user);
        $this->authenticateAs($this->admin());

        $this->client->request('GET', '/api/admin/transactions');

        self::assertResponseIsSuccessful();
        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('data', $data);
        $this->assertArrayHasKey('pagination', $data);
    }

    // ==================== refund ====================

    public function testRefundRejectsAnUnknownTransaction(): void
    {
        $this->authenticateAs($this->admin('admin-tx-admin2'));

        $this->client->request('POST', '/api/admin/transactions/999999/refund');

        self::assertResponseStatusCodeSame(400);
    }

    public function testRefundRejectsATransactionThatIsNotSucceeded(): void
    {
        $user = $this->createUser('admin-tx-owner2');
        $transaction = $this->transaction($user, Transaction::STATUS_FAILED);
        $this->authenticateAs($this->admin('admin-tx-admin3'));

        $this->client->request(
            'POST',
            '/api/admin/transactions/' . $transaction->getId() . '/refund',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode(['reason' => 'Test'])
        );

        self::assertResponseStatusCodeSame(400);
    }
}
