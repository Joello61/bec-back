<?php

declare(strict_types=1);

namespace App\Tests\Functional\Admin;

use App\Entity\SubscriptionPlan;
use App\Entity\User;
use App\Tests\Support\JwtAuthenticationTrait;
use App\Tests\Support\UserFactoryTrait;
use Doctrine\ORM\EntityManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Lot 5 (monétisation) : CRUD admin du catalogue d'abonnements.
 */
class AdminSubscriptionPlanControllerTest extends WebTestCase
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

    private function admin(string $prefix = 'admin-plan-admin'): User
    {
        $admin = $this->createUser($prefix);
        $admin->setRoles(['ROLE_ADMIN']);
        $this->em->flush();

        return $admin;
    }

    private function validPayload(string $code): array
    {
        return [
            'code' => $code,
            'name' => 'Plus',
            'priceAmountEur' => '4.99',
            'priceAmountXaf' => '3000',
            'billingPeriod' => 'monthly',
            'maxActiveVoyages' => null,
            'maxActiveDemandes' => null,
            'hasBadge' => true,
            'isFeatured' => false,
            'isActive' => true,
            'sortOrder' => 1,
            'stripePriceId' => null,
        ];
    }

    // ==================== auth requise ====================

    public function testListRejectsANonAdminUser(): void
    {
        $this->authenticateAs($this->createUser('admin-plan-nonadmin'));

        $this->client->request('GET', '/api/admin/subscription-plans');

        self::assertResponseStatusCodeSame(403);
    }

    // ==================== list ====================

    public function testListIncludesInactivePlansUnlikeThePublicEndpoint(): void
    {
        $inactive = new SubscriptionPlan();
        $inactive->setCode('inactive-plan-' . uniqid())->setName('Inactif')->setIsActive(false);
        $this->em->persist($inactive);
        $this->em->flush();

        $this->authenticateAs($this->admin('admin-plan-list'));

        $this->client->request('GET', '/api/admin/subscription-plans');

        self::assertResponseIsSuccessful();
        $payload = json_decode($this->client->getResponse()->getContent(), true);
        $codes = array_column($payload, 'code');
        self::assertContains($inactive->getCode(), $codes);
    }

    // ==================== create ====================

    public function testCreateRejectsADuplicateCode(): void
    {
        $existing = new SubscriptionPlan();
        $existing->setCode('dup-plan-' . uniqid())->setName('Existant');
        $this->em->persist($existing);
        $this->em->flush();
        $this->authenticateAs($this->admin('admin-plan-create-dup'));

        $this->client->request(
            'POST',
            '/api/admin/subscription-plans',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode($this->validPayload($existing->getCode()))
        );

        self::assertResponseStatusCodeSame(400);
    }

    public function testCreateRejectsAnInvalidCode(): void
    {
        $this->authenticateAs($this->admin('admin-plan-create-invalid'));

        $payload = $this->validPayload('CodeInvalideAvecMajuscules');

        $this->client->request(
            'POST',
            '/api/admin/subscription-plans',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode($payload)
        );

        self::assertResponseStatusCodeSame(422);
    }

    public function testCreateSucceeds(): void
    {
        $this->authenticateAs($this->admin('admin-plan-create-ok'));
        $code = 'new-plan-' . uniqid();

        $this->client->request(
            'POST',
            '/api/admin/subscription-plans',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode($this->validPayload($code))
        );

        self::assertResponseStatusCodeSame(201);
        $payload = json_decode($this->client->getResponse()->getContent(), true);
        self::assertSame($code, $payload['code']);
        self::assertArrayHasKey('isFeatured', $payload);
    }

    // ==================== update ====================

    public function testUpdateReturns400ForAnUnknownPlan(): void
    {
        $this->authenticateAs($this->admin('admin-plan-update-404'));

        $this->client->request(
            'PUT',
            '/api/admin/subscription-plans/999999',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode($this->validPayload('irrelevant'))
        );

        self::assertResponseStatusCodeSame(400);
    }

    public function testUpdateSucceedsAndNeverChangesTheCode(): void
    {
        $plan = new SubscriptionPlan();
        $plan->setCode('update-plan-' . uniqid())->setName('Avant');
        $this->em->persist($plan);
        $this->em->flush();
        $originalCode = $plan->getCode();
        $this->authenticateAs($this->admin('admin-plan-update-ok'));

        $payload = $this->validPayload('code-ignore-since-update-dto-has-none');
        unset($payload['code']);
        $payload['name'] = 'Après';
        $payload['isFeatured'] = true;

        $this->client->request(
            'PUT',
            '/api/admin/subscription-plans/' . $plan->getId(),
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode($payload)
        );

        self::assertResponseIsSuccessful();
        $responsePayload = json_decode($this->client->getResponse()->getContent(), true);
        self::assertSame('Après', $responsePayload['name']);
        self::assertTrue($responsePayload['isFeatured']);
        self::assertSame($originalCode, $responsePayload['code']);
    }

    // ==================== delete ====================

    public function testDeleteRefusesTheFreePlan(): void
    {
        $free = $this->em->getRepository(SubscriptionPlan::class)->findOneBy(['code' => 'free']);

        if ($free === null) {
            $free = new SubscriptionPlan();
            $free->setCode('free')->setName('Free');
            $this->em->persist($free);
            $this->em->flush();
        }

        $this->authenticateAs($this->admin('admin-plan-delete-free'));

        $this->client->request('DELETE', '/api/admin/subscription-plans/' . $free->getId());

        self::assertResponseStatusCodeSame(400);
    }

    public function testDeleteSoftDeletesANonFreePlan(): void
    {
        $plan = new SubscriptionPlan();
        $plan->setCode('delete-plan-' . uniqid())->setName('À supprimer');
        $this->em->persist($plan);
        $this->em->flush();
        $planId = $plan->getId();
        $this->authenticateAs($this->admin('admin-plan-delete-ok'));

        $this->client->request('DELETE', '/api/admin/subscription-plans/' . $planId);

        self::assertResponseIsSuccessful();
        $this->em->clear();
        $refreshed = $this->em->getRepository(SubscriptionPlan::class)->find($planId);
        self::assertNotNull($refreshed, 'jamais de suppression physique');
        self::assertNotNull($refreshed->getDeletedAt());
        self::assertFalse($refreshed->isActive());
    }
}
