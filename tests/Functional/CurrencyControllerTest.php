<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\Currency;
use App\Entity\User;
use App\Tests\Support\JwtAuthenticationTrait;
use App\Tests\Support\UserFactoryTrait;
use Doctrine\ORM\EntityManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Phase 4b, Lot 10 (bec-docs/docs/plan-correction/plan-correction-cobage.md), constat #1 du
 * plan : CurrencyController::updateRates n'imposait que IS_AUTHENTICATED_FULLY (regle
 * globale ^/api de security.yaml, /api/currencies n'etant pas dans la liste PUBLIC_ACCESS)
 * - n'importe quel utilisateur connecte, pas seulement un admin, pouvait declencher un
 * appel API tiers payant. Corrige dans ce lot avec #[IsGranted('ROLE_ADMIN')]. Le test de
 * succes de updateRates n'est pas couvert ici (declenche un vrai appel reseau vers l'API
 * externe de taux de change, deja mocke via MockHttpClient dans CurrencyServiceTest au
 * Lot 5) - seule la porte d'autorisation est verifiee au niveau HTTP.
 *
 * Depuis la Phase 13/Lot B3 (2026-09-11) : les routes de LECTURE (GET) sont publiques
 * (donnee referentielle non sensible) - seules les routes de mutation (update-rates)
 * restent authentifiees/ROLE_ADMIN. Les tests de contenu authentifient tout de meme un
 * utilisateur standard par habitude/coherence avec le reste de la suite, mais un test
 * dedie verifie explicitement l'acces anonyme reel pour chaque famille de route.
 */
class CurrencyControllerTest extends WebTestCase
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

    private function currency(string $code, string $rate = '1', bool $active = true): Currency
    {
        $currency = new Currency();
        $currency->setCode($code);
        $currency->setName($code);
        $currency->setSymbol($code);
        $currency->setExchangeRate($rate);
        $currency->setIsActive($active);
        $this->em->persist($currency);
        $this->em->flush();

        return $currency;
    }

    private function admin(): User
    {
        $admin = $this->createUser('currency-admin');
        $admin->setRoles(['ROLE_ADMIN']);
        $this->em->flush();

        return $admin;
    }

    // ==================== list ====================

    /**
     * Route de lecture publique depuis le Lot B3 (Phase 13 du plan de correction) -
     * donnee referentielle non sensible, decision actee avec l'utilisateur le 2026-09-11.
     */
    public function testListIsAccessibleWithoutAuthentication(): void
    {
        $this->currency('EUR', '1', active: true);

        $this->client->request('GET', '/api/currencies');

        self::assertResponseIsSuccessful();
    }

    public function testListReturnsOnlyActiveCurrencies(): void
    {
        $this->currency('EUR', '1', active: true);
        $this->currency('XAF', '655.957', active: false);
        $this->authenticateAs($this->createUser('currency-list'));

        $this->client->request('GET', '/api/currencies');

        self::assertResponseIsSuccessful();
        $payload = json_decode($this->client->getResponse()->getContent(), true);
        self::assertTrue($payload['success']);
        self::assertCount(1, $payload['data']);
        self::assertSame('EUR', $payload['data'][0]['code']);
    }

    // ==================== popular ====================

    public function testPopularRejectsALimitOutOfRange(): void
    {
        $this->authenticateAs($this->createUser('currency-popular-limit'));

        $this->client->request('GET', '/api/currencies/popular?limit=50');

        self::assertResponseStatusCodeSame(400);
    }

    public function testPopularSucceeds(): void
    {
        $this->currency('EUR');
        $this->authenticateAs($this->createUser('currency-popular'));

        $this->client->request('GET', '/api/currencies/popular?limit=3');

        self::assertResponseIsSuccessful();
    }

    // ==================== show ====================

    public function testShowReturns404ForAnUnknownCode(): void
    {
        $this->authenticateAs($this->createUser('currency-show-404'));

        $this->client->request('GET', '/api/currencies/ZZZ');

        self::assertResponseStatusCodeSame(404);
    }

    public function testShowSucceedsCaseInsensitively(): void
    {
        $this->currency('EUR');
        $this->authenticateAs($this->createUser('currency-show'));

        $this->client->request('GET', '/api/currencies/eur');

        self::assertResponseIsSuccessful();
        $payload = json_decode($this->client->getResponse()->getContent(), true);
        self::assertSame('EUR', $payload['data']['code']);
    }

    // ==================== convert ====================

    public function testConvertRequiresAllParameters(): void
    {
        $this->authenticateAs($this->createUser('currency-convert-params'));

        $this->client->request('GET', '/api/currencies/convert?amount=10');

        self::assertResponseStatusCodeSame(400);
    }

    public function testConvertRejectsANonPositiveAmount(): void
    {
        $this->authenticateAs($this->createUser('currency-convert-negative'));

        $this->client->request('GET', '/api/currencies/convert?amount=-5&from=EUR&to=USD');

        self::assertResponseStatusCodeSame(400);
    }

    public function testConvertRejectsAnUnsupportedCurrency(): void
    {
        $this->currency('EUR');
        $this->authenticateAs($this->createUser('currency-convert-unsupported'));

        $this->client->request('GET', '/api/currencies/convert?amount=10&from=EUR&to=ZZZ');

        self::assertResponseStatusCodeSame(400);
    }

    public function testConvertSucceedsBetweenTwoSupportedCurrencies(): void
    {
        $this->currency('EUR', '1');
        $this->currency('XAF', '655.957');
        $this->authenticateAs($this->createUser('currency-convert-ok'));

        $this->client->request('GET', '/api/currencies/convert?amount=10&from=EUR&to=XAF');

        self::assertResponseIsSuccessful();
        $payload = json_decode($this->client->getResponse()->getContent(), true);
        self::assertTrue($payload['success']);
        self::assertSame('EUR', $payload['data']['originalCurrency']);
        self::assertSame('XAF', $payload['data']['convertedCurrency']);
    }

    // ==================== detectByCountry ====================

    public function testDetectByCountryRequiresACountry(): void
    {
        $this->authenticateAs($this->createUser('currency-detect-country'));

        $this->client->request(
            'POST',
            '/api/currencies/detect-by-country',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode([])
        );

        self::assertResponseStatusCodeSame(400);
    }

    // ==================== format ====================

    public function testFormatRejectsAnUnsupportedCurrency(): void
    {
        $this->authenticateAs($this->createUser('currency-format-unsupported'));

        $this->client->request('GET', '/api/currencies/format?amount=10&currency=ZZZ');

        self::assertResponseStatusCodeSame(400);
    }

    public function testFormatSucceeds(): void
    {
        $this->currency('EUR');
        $this->authenticateAs($this->createUser('currency-format'));

        $this->client->request('GET', '/api/currencies/format?amount=1234.5&currency=EUR');

        self::assertResponseIsSuccessful();
        $payload = json_decode($this->client->getResponse()->getContent(), true);
        self::assertArrayHasKey('formatted', $payload['data']);
    }

    // ==================== updateRates : regression bug #1 ====================

    public function testUpdateRatesRequiresAuthentication(): void
    {
        $this->client->request('POST', '/api/currencies/update-rates');

        self::assertResponseStatusCodeSame(403);
    }

    public function testUpdateRatesRejectsANonAdminUser(): void
    {
        $this->authenticateAs($this->createUser('currency-nonadmin'));

        $this->client->request('POST', '/api/currencies/update-rates');

        self::assertResponseStatusCodeSame(403);
    }

    // ==================== default ====================

    public function testGetDefaultSucceeds(): void
    {
        $this->authenticateAs($this->createUser('currency-default'));

        $this->client->request('GET', '/api/currencies/default');

        self::assertResponseIsSuccessful();
        $payload = json_decode($this->client->getResponse()->getContent(), true);
        self::assertArrayHasKey('code', $payload['data']);
    }

    // ==================== convertBatch ====================

    public function testConvertBatchRequiresAConversionsArray(): void
    {
        $this->authenticateAs($this->createUser('currency-batch-empty'));

        $this->client->request(
            'POST',
            '/api/currencies/convert-batch',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode([])
        );

        self::assertResponseStatusCodeSame(400);
    }

    public function testConvertBatchProcessesEachConversionIndependently(): void
    {
        $this->currency('EUR', '1');
        $this->currency('XAF', '655.957');
        $this->authenticateAs($this->createUser('currency-batch-ok'));

        $this->client->request(
            'POST',
            '/api/currencies/convert-batch',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode([
                'conversions' => [
                    ['amount' => 10, 'from' => 'EUR', 'to' => 'XAF'],
                    ['amount' => 5, 'from' => 'EUR', 'to' => 'ZZZ'],
                ],
            ])
        );

        self::assertResponseIsSuccessful();
        $payload = json_decode($this->client->getResponse()->getContent(), true);
        self::assertFalse($payload['success']);
        self::assertCount(1, $payload['data']);
        self::assertCount(1, $payload['errors']);
    }
}
