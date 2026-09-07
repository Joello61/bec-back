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
 * Phase 4b, Lot 10 (bec-docs/docs/plan-correction/plan-correction-cobage.md) : integration
 * HTTP de SettingsController - cree/lit/modifie/reinitialise/exporte les preferences de
 * l'utilisateur connecte, aucune notion de propriete tierce (toujours $this->getUser()).
 */
class SettingsControllerTest extends WebTestCase
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

    // ==================== auth requise ====================

    public function testGetSettingsRequiresAuthentication(): void
    {
        $this->client->request('GET', '/api/settings');

        self::assertResponseStatusCodeSame(403);
    }

    // ==================== getSettings ====================

    public function testGetSettingsCreatesDefaultsOnFirstAccess(): void
    {
        $this->authenticateAs($this->createUser('settings-get'));

        $this->client->request('GET', '/api/settings');

        self::assertResponseIsSuccessful();
        $payload = json_decode($this->client->getResponse()->getContent(), true);
        self::assertArrayHasKey('langue', $payload);
    }

    // ==================== updateSettings ====================

    public function testUpdateSettingsRejectsAnInvalidChoice(): void
    {
        $this->authenticateAs($this->createUser('settings-update-invalid'));

        $this->client->request(
            'PATCH',
            '/api/settings',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode(['profileVisibility' => 'invalide'])
        );

        self::assertResponseStatusCodeSame(422);
    }

    public function testUpdateSettingsAppliesTheChanges(): void
    {
        $this->authenticateAs($this->createUser('settings-update-ok'));

        $this->client->request(
            'PATCH',
            '/api/settings',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode(['langue' => 'en', 'showEmail' => true])
        );

        self::assertResponseIsSuccessful();
        $payload = json_decode($this->client->getResponse()->getContent(), true);
        self::assertSame('en', $payload['langue']);
        self::assertTrue($payload['showEmail']);
    }

    // ==================== resetSettings ====================

    public function testResetSettingsRestoresDefaults(): void
    {
        $user = $this->createUser('settings-reset');
        $this->authenticateAs($user);
        $this->client->request(
            'PATCH',
            '/api/settings',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode(['langue' => 'en'])
        );

        $this->client->request('POST', '/api/settings/reset');

        self::assertResponseIsSuccessful();
        $payload = json_decode($this->client->getResponse()->getContent(), true);
        self::assertSame('fr', $payload['langue']);
    }

    // ==================== exportData ====================

    public function testExportDataReturnsTheUsersData(): void
    {
        $this->authenticateAs($this->createUser('settings-export'));

        $this->client->request('GET', '/api/settings/export');

        self::assertResponseIsSuccessful();
        $payload = json_decode($this->client->getResponse()->getContent(), true);
        self::assertArrayHasKey('user', $payload);
        self::assertArrayHasKey('settings', $payload);
    }
}
