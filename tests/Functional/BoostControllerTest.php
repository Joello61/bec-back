<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\BoostOffer;
use App\Entity\User;
use App\Entity\Voyage;
use App\Tests\Support\JwtAuthenticationTrait;
use App\Tests\Support\UserFactoryTrait;
use Doctrine\ORM\EntityManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Monétisation Lot 2 : double consentement revalidé côté backend, et IDOR (un tiers ne
 * doit jamais pouvoir booster le voyage d'un autre) - même patron que ContactControllerTest.
 */
class BoostControllerTest extends WebTestCase
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

    private function voyage(User $owner): Voyage
    {
        $voyage = new Voyage();
        $voyage->setVoyageur($owner);
        $voyage->setVilleDepart('Douala');
        $voyage->setVilleArrivee('Paris');
        $voyage->setDateDepart(new \DateTime('+10 days'));
        $voyage->setDateArrivee(new \DateTime('+11 days'));
        $voyage->setPoidsDisponible('10.00');
        $voyage->setPoidsDisponibleRestant('10.00');
        $voyage->setStatut('actif');
        $this->em->persist($voyage);
        $this->em->flush();

        return $voyage;
    }

    private function offer(): BoostOffer
    {
        $offer = new BoostOffer();
        $offer->setName('7 jours')->setDurationDays(7)->setPriceAmountEur('2.99');
        $this->em->persist($offer);
        $this->em->flush();

        return $offer;
    }

    private function validPayload(int $targetId, int $offerId): array
    {
        return [
            'targetType' => 'voyage',
            'targetId' => $targetId,
            'offerId' => $offerId,
            'accessImmediateConsent' => true,
            'withdrawalWaiverConsent' => true,
        ];
    }

    public function testOffersIsPublic(): void
    {
        $this->offer();

        $this->client->request('GET', '/api/boosts/offers');

        self::assertResponseIsSuccessful();
    }

    public function testCheckoutRejectsWhenBothConsentsAreMissing(): void
    {
        $owner = $this->createUser('boost-checkout-owner');
        $voyage = $this->voyage($owner);
        $offer = $this->offer();
        $this->authenticateAs($owner);

        $payload = $this->validPayload($voyage->getId(), $offer->getId());
        $payload['accessImmediateConsent'] = false;
        $payload['withdrawalWaiverConsent'] = false;

        $this->client->request(
            'POST',
            '/api/boosts/checkout',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode($payload)
        );

        self::assertResponseStatusCodeSame(422);
    }

    public function testCheckoutRejectsWhenOnlyOneConsentIsGiven(): void
    {
        $owner = $this->createUser('boost-checkout-owner2');
        $voyage = $this->voyage($owner);
        $offer = $this->offer();
        $this->authenticateAs($owner);

        $payload = $this->validPayload($voyage->getId(), $offer->getId());
        $payload['withdrawalWaiverConsent'] = false;

        $this->client->request(
            'POST',
            '/api/boosts/checkout',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode($payload)
        );

        self::assertResponseStatusCodeSame(422);
    }

    public function testCheckoutRejectsAThirdPartyBoostingAnotherUsersVoyage(): void
    {
        $owner = $this->createUser('boost-checkout-owner3');
        $voyage = $this->voyage($owner);
        $offer = $this->offer();
        $thirdParty = $this->createUser('boost-checkout-thirdparty');
        $this->authenticateAs($thirdParty);

        $this->client->request(
            'POST',
            '/api/boosts/checkout',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode($this->validPayload($voyage->getId(), $offer->getId()))
        );

        self::assertResponseStatusCodeSame(403, 'un tiers ne doit jamais pouvoir booster le voyage d\'un autre (IDOR)');
    }

    public function testCheckoutRejectsAnUnauthenticatedRequest(): void
    {
        $owner = $this->createUser('boost-checkout-owner4');
        $voyage = $this->voyage($owner);
        $offer = $this->offer();

        $this->client->request(
            'POST',
            '/api/boosts/checkout',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode($this->validPayload($voyage->getId(), $offer->getId()))
        );

        self::assertResponseStatusCodeSame(403);
    }
}
