<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\Signalement;
use App\Entity\User;
use App\Tests\Support\JwtAuthenticationTrait;
use App\Tests\Support\UserFactoryTrait;
use Doctrine\ORM\EntityManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Phase 4b, Lot 8 (bec-docs/docs/plan-correction/plan-correction-cobage.md) : integration
 * HTTP de SignalementController - le detail du controle "profil complet requis pour creer"
 * est deja verifie unitairement par SignalementVoterTest (Phase 4).
 */
class SignalementControllerTest extends WebTestCase
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

    private function admin(): User
    {
        $admin = $this->createUser('signalement-admin');
        $admin->setRoles(['ROLE_ADMIN']);
        $this->em->flush();

        return $admin;
    }

    private function signalement(User $signaleur, User $signale): Signalement
    {
        $signalement = new Signalement();
        $signalement->setSignaleur($signaleur);
        $signalement->setUtilisateurSignale($signale);
        $signalement->setMotif('spam');
        $signalement->setDescription('Description assez longue du signalement');
        $this->em->persist($signalement);
        $this->em->flush();

        return $signalement;
    }

    // ==================== create ====================

    public function testCreateRequiresAuthentication(): void
    {
        $this->client->request(
            'POST',
            '/api/signalements',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode(['motif' => 'spam', 'description' => 'description assez longue'])
        );

        self::assertResponseStatusCodeSame(403);
    }

    public function testCreateRejectsAPayloadWithoutAnyTarget(): void
    {
        $this->authenticateAs($this->createCompleteProfileUser('signalement-no-target'));

        $this->client->request(
            'POST',
            '/api/signalements',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode(['motif' => 'spam', 'description' => 'description assez longue'])
        );

        self::assertResponseStatusCodeSame(400);
    }

    public function testCreateRejectsAnIncompleteProfile(): void
    {
        $signale = $this->createUser('signalement-target-incomplete');
        $this->authenticateAs($this->createUser('signalement-incomplete-profile'));

        $this->client->request(
            'POST',
            '/api/signalements',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode([
                'motif' => 'spam',
                'description' => 'description assez longue',
                'utilisateurSignaleId' => $signale->getId(),
            ])
        );

        self::assertResponseStatusCodeSame(403);
    }

    public function testCreateSucceedsWithACompleteProfile(): void
    {
        $signale = $this->createUser('signalement-target-ok');
        $this->authenticateAs($this->createCompleteProfileUser('signalement-create-ok'));

        $this->client->request(
            'POST',
            '/api/signalements',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode([
                'motif' => 'spam',
                'description' => 'description assez longue',
                'utilisateurSignaleId' => $signale->getId(),
            ])
        );

        self::assertResponseStatusCodeSame(201);
    }

    // ==================== me ====================

    public function testMeRequiresAuthentication(): void
    {
        $this->client->request('GET', '/api/signalements/me');

        self::assertResponseStatusCodeSame(403);
    }

    public function testMeReturnsOnlyTheCallersSignalements(): void
    {
        $signaleur = $this->createUser('signalement-me-signaleur');
        $autre = $this->createUser('signalement-me-autre');
        $signale = $this->createUser('signalement-me-cible');
        $this->signalement($signaleur, $signale);
        $this->signalement($autre, $signale);
        $this->authenticateAs($signaleur);

        $this->client->request('GET', '/api/signalements/me');

        self::assertResponseIsSuccessful();
        $payload = json_decode($this->client->getResponse()->getContent(), true);
        self::assertCount(1, $payload['data']);
    }

    // ==================== list (admin) ====================

    public function testListRejectsANonAdminUser(): void
    {
        $this->authenticateAs($this->createUser('signalement-list-nonadmin'));

        $this->client->request('GET', '/api/signalements');

        self::assertResponseStatusCodeSame(403);
    }

    public function testListSucceedsForAnAdmin(): void
    {
        $signaleur = $this->createUser('signalement-list-signaleur');
        $signale = $this->createUser('signalement-list-cible');
        $this->signalement($signaleur, $signale);
        $this->authenticateAs($this->admin());

        $this->client->request('GET', '/api/signalements');

        self::assertResponseIsSuccessful();
        $payload = json_decode($this->client->getResponse()->getContent(), true);
        self::assertNotEmpty($payload['data']);
    }

    // ==================== process (admin) ====================

    public function testProcessRejectsANonAdminUser(): void
    {
        $signaleur = $this->createUser('signalement-process-signaleur');
        $signale = $this->createUser('signalement-process-cible');
        $signalement = $this->signalement($signaleur, $signale);
        $this->authenticateAs($this->createUser('signalement-process-nonadmin'));

        $this->client->request(
            'PATCH',
            '/api/signalements/' . $signalement->getId() . '/traiter',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode(['statut' => 'traite'])
        );

        self::assertResponseStatusCodeSame(403);
    }

    public function testProcessRejectsAnInvalidStatus(): void
    {
        $signaleur = $this->createUser('signalement-process-invalid-signaleur');
        $signale = $this->createUser('signalement-process-invalid-cible');
        $signalement = $this->signalement($signaleur, $signale);
        $this->authenticateAs($this->admin());

        $this->client->request(
            'PATCH',
            '/api/signalements/' . $signalement->getId() . '/traiter',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode(['statut' => 'invalide'])
        );

        self::assertResponseStatusCodeSame(400);
    }

    public function testProcessSucceedsForAnAdmin(): void
    {
        $signaleur = $this->createUser('signalement-process-ok-signaleur');
        $signale = $this->createUser('signalement-process-ok-cible');
        $signalement = $this->signalement($signaleur, $signale);
        $this->authenticateAs($this->admin());

        $this->client->request(
            'PATCH',
            '/api/signalements/' . $signalement->getId() . '/traiter',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode(['statut' => 'traite', 'reponseAdmin' => 'Contenu retire'])
        );

        self::assertResponseIsSuccessful();
        $payload = json_decode($this->client->getResponse()->getContent(), true);
        self::assertSame('traite', $payload['statut']);
    }

    // ==================== pendingCount (admin) ====================

    public function testPendingCountRejectsANonAdminUser(): void
    {
        $this->authenticateAs($this->createUser('signalement-pending-nonadmin'));

        $this->client->request('GET', '/api/signalements/pending-count');

        self::assertResponseStatusCodeSame(403);
    }

    public function testPendingCountSucceedsForAnAdmin(): void
    {
        $signaleur = $this->createUser('signalement-pending-signaleur');
        $signale = $this->createUser('signalement-pending-cible');
        $this->signalement($signaleur, $signale);
        $this->authenticateAs($this->admin());

        $this->client->request('GET', '/api/signalements/pending-count');

        self::assertResponseIsSuccessful();
        $payload = json_decode($this->client->getResponse()->getContent(), true);
        self::assertGreaterThanOrEqual(1, $payload['count']);
    }
}
