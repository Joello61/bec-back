<?php

declare(strict_types=1);

namespace App\Tests\Functional\Admin;

use App\Entity\BoostOffer;
use App\Entity\User;
use App\Tests\Support\JwtAuthenticationTrait;
use App\Tests\Support\UserFactoryTrait;
use Doctrine\ORM\EntityManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Lot 5 (monétisation) : CRUD admin du catalogue de boosts.
 */
class AdminBoostOfferControllerTest extends WebTestCase
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

    private function admin(string $prefix = 'admin-offer-admin'): User
    {
        $admin = $this->createUser($prefix);
        $admin->setRoles(['ROLE_ADMIN']);
        $this->em->flush();

        return $admin;
    }

    private function validPayload(): array
    {
        return [
            'name' => '7 jours',
            'durationDays' => 7,
            'priceAmountEur' => '2.99',
            'priceAmountXaf' => '2000',
            'isFeatured' => false,
            'isActive' => true,
            'sortOrder' => 1,
        ];
    }

    // ==================== auth requise ====================

    public function testListRejectsANonAdminUser(): void
    {
        $this->authenticateAs($this->createUser('admin-offer-nonadmin'));

        $this->client->request('GET', '/api/admin/boost-offers');

        self::assertResponseStatusCodeSame(403);
    }

    // ==================== list ====================

    public function testListIncludesInactiveOffersUnlikeThePublicEndpoint(): void
    {
        $inactive = new BoostOffer();
        $inactive->setName('Inactive-' . uniqid())->setDurationDays(7)->setPriceAmountEur('2.99')->setIsActive(false);
        $this->em->persist($inactive);
        $this->em->flush();

        $this->authenticateAs($this->admin('admin-offer-list'));

        $this->client->request('GET', '/api/admin/boost-offers');

        self::assertResponseIsSuccessful();
        $payload = json_decode($this->client->getResponse()->getContent(), true);
        $names = array_column($payload, 'name');
        self::assertContains($inactive->getName(), $names);
    }

    // ==================== create ====================

    public function testCreateRejectsAnInvalidDuration(): void
    {
        $this->authenticateAs($this->admin('admin-offer-create-invalid'));

        $payload = $this->validPayload();
        $payload['durationDays'] = -1;

        $this->client->request(
            'POST',
            '/api/admin/boost-offers',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode($payload)
        );

        self::assertResponseStatusCodeSame(422);
    }

    public function testCreateSucceeds(): void
    {
        $this->authenticateAs($this->admin('admin-offer-create-ok'));

        $this->client->request(
            'POST',
            '/api/admin/boost-offers',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode($this->validPayload())
        );

        self::assertResponseStatusCodeSame(201);
        $payload = json_decode($this->client->getResponse()->getContent(), true);
        self::assertSame(7, $payload['durationDays']);
        self::assertArrayHasKey('isFeatured', $payload);
    }

    // ==================== update ====================

    public function testUpdateReturns400ForAnUnknownOffer(): void
    {
        $this->authenticateAs($this->admin('admin-offer-update-404'));

        $this->client->request(
            'PUT',
            '/api/admin/boost-offers/999999',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode($this->validPayload())
        );

        self::assertResponseStatusCodeSame(400);
    }

    public function testUpdateSucceeds(): void
    {
        $offer = new BoostOffer();
        $offer->setName('Avant')->setDurationDays(7)->setPriceAmountEur('2.99');
        $this->em->persist($offer);
        $this->em->flush();
        $this->authenticateAs($this->admin('admin-offer-update-ok'));

        $payload = $this->validPayload();
        $payload['name'] = 'Après';
        $payload['isFeatured'] = true;

        $this->client->request(
            'PUT',
            '/api/admin/boost-offers/' . $offer->getId(),
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode($payload)
        );

        self::assertResponseIsSuccessful();
        $responsePayload = json_decode($this->client->getResponse()->getContent(), true);
        self::assertSame('Après', $responsePayload['name']);
        self::assertTrue($responsePayload['isFeatured']);
    }

    // ==================== delete ====================

    public function testDeleteSoftDeletesTheOffer(): void
    {
        $offer = new BoostOffer();
        $offer->setName('À supprimer')->setDurationDays(7)->setPriceAmountEur('2.99');
        $this->em->persist($offer);
        $this->em->flush();
        $offerId = $offer->getId();
        $this->authenticateAs($this->admin('admin-offer-delete-ok'));

        $this->client->request('DELETE', '/api/admin/boost-offers/' . $offerId);

        self::assertResponseIsSuccessful();
        $this->em->clear();
        $refreshed = $this->em->getRepository(BoostOffer::class)->find($offerId);
        self::assertNotNull($refreshed, 'jamais de suppression physique');
        self::assertNotNull($refreshed->getDeletedAt());
        self::assertFalse($refreshed->isActive());
    }
}
