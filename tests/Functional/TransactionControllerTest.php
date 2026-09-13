<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\Transaction;
use App\Entity\User;
use App\Tests\Support\JwtAuthenticationTrait;
use App\Tests\Support\UserFactoryTrait;
use Doctrine\ORM\EntityManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Lot N3 (plan-complements-monetisation-cobage.md) : historique des propres
 * transactions de l'utilisateur. Le point critique a couvrir est l'etancheite entre
 * utilisateurs (CLAUDE.md section 17) - meme patron que AdminTransactionControllerTest.
 */
class TransactionControllerTest extends WebTestCase
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

    public function testMeRejectsAnUnauthenticatedRequest(): void
    {
        // Ce backend renvoie 403 (pas 401) pour un appelant anonyme sur une route
        // #[IsGranted] - meme comportement observe sur les autres controleurs (cf.
        // ContactControllerTest, SubscriptionControllerTest).
        $this->client->request('GET', '/api/transactions/me');

        self::assertResponseStatusCodeSame(403);
    }

    public function testMeReturnsOnlyTheCurrentUsersTransactions(): void
    {
        $owner = $this->createUser('tx-me-owner');
        $otherUser = $this->createUser('tx-me-other');
        $this->transaction($owner);
        $this->transaction($otherUser);

        $this->authenticateAs($owner);
        $this->client->request('GET', '/api/transactions/me');

        self::assertResponseIsSuccessful();
        $data = json_decode($this->client->getResponse()->getContent(), true);

        self::assertCount(1, $data['data'], 'un utilisateur ne doit jamais voir les transactions d\'un tiers');
        self::assertSame(1, $data['pagination']['total']);
    }

    public function testMeNeverExposesTheUserField(): void
    {
        $owner = $this->createUser('tx-me-no-user-field');
        $this->transaction($owner);

        $this->authenticateAs($owner);
        $this->client->request('GET', '/api/transactions/me');

        self::assertResponseIsSuccessful();
        $data = json_decode($this->client->getResponse()->getContent(), true);

        self::assertArrayNotHasKey(
            'user',
            $data['data'][0],
            'le groupe admin:transaction:list (qui expose "user") ne doit jamais etre utilise ici'
        );
    }

    public function testMeExposesRefundedAtToItsOwner(): void
    {
        $owner = $this->createUser('tx-me-refunded');
        $transaction = $this->transaction($owner, Transaction::STATUS_REFUNDED);
        $transaction->setRefundedAt(new \DateTime());
        $this->em->flush();

        $this->authenticateAs($owner);
        $this->client->request('GET', '/api/transactions/me');

        self::assertResponseIsSuccessful();
        $data = json_decode($this->client->getResponse()->getContent(), true);

        self::assertArrayHasKey('refundedAt', $data['data'][0]);
        self::assertNotNull($data['data'][0]['refundedAt']);
    }

    public function testMeRespectsThePaginationLimitCap(): void
    {
        $owner = $this->createUser('tx-me-pagination');
        $this->authenticateAs($owner);

        $this->client->request('GET', '/api/transactions/me', ['limit' => 500]);

        self::assertResponseIsSuccessful();
        $data = json_decode($this->client->getResponse()->getContent(), true);
        self::assertSame(50, $data['pagination']['limit'], 'la limite doit toujours etre plafonnee a 50');
    }
}
