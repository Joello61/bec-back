<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Tests\Support\JwtAuthenticationTrait;
use App\Tests\Support\UserFactoryTrait;
use Doctrine\ORM\EntityManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Monétisation Lot 6.3 : la cadence de facturation (mensuel/annuel) est revalidée
 * cote backend - le chemin nominal (checkout reussi) n'est pas teste ici, il exigerait
 * un vrai appel Stripe/Notch Pay (meme patron que BoostControllerTest, qui ne teste
 * que les rejets jusqu'a l'appel prestataire).
 */
class SubscriptionControllerTest extends WebTestCase
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
        static::getContainer()->get('cache.rate_limiter')->clear();
    }

    private function validPayload(): array
    {
        return [
            'planCode' => 'plus',
            'paymentMethod' => 'card',
            'billingPeriod' => 'monthly',
            'accessImmediateConsent' => true,
            'withdrawalWaiverConsent' => true,
        ];
    }

    public function testCheckoutRejectsAnInvalidBillingPeriod(): void
    {
        $this->authenticateAs($this->createUser('sub-checkout-billing-period'));

        $payload = $this->validPayload();
        $payload['billingPeriod'] = 'weekly';

        $this->client->request(
            'POST',
            '/api/subscriptions/checkout',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode($payload)
        );

        self::assertResponseStatusCodeSame(422);
    }

    public function testCheckoutRejectsAnUnauthenticatedRequest(): void
    {
        $this->client->request(
            'POST',
            '/api/subscriptions/checkout',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode($this->validPayload())
        );

        self::assertResponseStatusCodeSame(403);
    }
}
