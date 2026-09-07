<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\Contact;
use App\Entity\User;
use App\Tests\Support\JwtAuthenticationTrait;
use App\Tests\Support\UserFactoryTrait;
use Doctrine\ORM\EntityManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Phase 4b, Lot 8 (bec-docs/docs/plan-correction/plan-correction-cobage.md) : create() est le
 * seul endpoint public de tout ce lot (rate limite 5/h/IP, meme patron d'isolation que
 * AuthControllerTest pour le pool cache.rate_limiter partage entre executions de test) ;
 * list/show/delete sont reserves a ROLE_ADMIN sans Voter dedie (pas de notion de proprietaire).
 */
class ContactControllerTest extends WebTestCase
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

    private function admin(): User
    {
        $admin = $this->createUser('contact-admin');
        $admin->setRoles(['ROLE_ADMIN']);
        $this->em->flush();

        return $admin;
    }

    private function contact(string $email = 'visiteur@example.test'): Contact
    {
        $contact = new Contact();
        $contact->setNom('Jean Dupont');
        $contact->setEmail($email);
        $contact->setSujet('Question sur un voyage');
        $contact->setMessage('Bonjour, jaimerais savoir comment cela fonctionne.');
        $this->em->persist($contact);
        $this->em->flush();

        return $contact;
    }

    private function validPayload(): array
    {
        return [
            'nom' => 'Jean Dupont',
            'email' => 'jean.dupont@example.test',
            'sujet' => 'Question sur un voyage',
            'message' => 'Bonjour, jaimerais savoir comment cela fonctionne exactement.',
        ];
    }

    // ==================== create (public) ====================

    public function testCreateSucceedsWithoutAuthentication(): void
    {
        $this->client->request(
            'POST',
            '/api/contacts/send',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode($this->validPayload())
        );

        self::assertResponseStatusCodeSame(201);
        $payload = json_decode($this->client->getResponse()->getContent(), true);
        self::assertArrayHasKey('id', $payload);
    }

    public function testCreateRejectsAnIncompletePayload(): void
    {
        $this->client->request(
            'POST',
            '/api/contacts/send',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode(['nom' => 'A'])
        );

        self::assertResponseStatusCodeSame(422);
    }

    public function testCreateIsRateLimitedAfterFiveRequestsPerHour(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->client->request(
                'POST',
                '/api/contacts/send',
                server: ['CONTENT_TYPE' => 'application/json'],
                content: json_encode($this->validPayload())
            );
            self::assertResponseStatusCodeSame(201, "requete $i devrait encore passer");
        }

        $this->client->request(
            'POST',
            '/api/contacts/send',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode($this->validPayload())
        );

        self::assertResponseStatusCodeSame(429);
    }

    // ==================== list (ROLE_ADMIN) ====================

    public function testListRequiresAuthentication(): void
    {
        // Ce backend renvoie 403 (pas 401) pour un appelant anonyme sur une route
        // #[IsGranted] - meme comportement observe sur les autres controleurs admin.
        $this->client->request('GET', '/api/contacts');

        self::assertResponseStatusCodeSame(403);
    }

    public function testListRejectsANonAdminUser(): void
    {
        $this->authenticateAs($this->createUser('contact-nonadmin'));

        $this->client->request('GET', '/api/contacts');

        self::assertResponseStatusCodeSame(403);
    }

    public function testListSucceedsForAnAdminAndOmitsTheFullMessage(): void
    {
        $this->contact();
        $this->authenticateAs($this->admin());

        $this->client->request('GET', '/api/contacts');

        self::assertResponseIsSuccessful();
        $payload = json_decode($this->client->getResponse()->getContent(), true);
        self::assertNotEmpty($payload);
        self::assertArrayNotHasKey('message', $payload[0], 'le groupe contact:list ne doit pas exposer le message complet');
    }

    // ==================== show (ROLE_ADMIN) ====================

    public function testShowReturns404ForAnUnknownId(): void
    {
        $this->authenticateAs($this->admin());

        $this->client->request('GET', '/api/contacts/999999');

        self::assertResponseStatusCodeSame(404);
    }

    public function testShowSucceedsForAnAdmin(): void
    {
        $contact = $this->contact();
        $this->authenticateAs($this->admin());

        $this->client->request('GET', '/api/contacts/' . $contact->getId());

        self::assertResponseIsSuccessful();
        $payload = json_decode($this->client->getResponse()->getContent(), true);
        self::assertSame($contact->getMessage(), $payload['message']);
    }

    // ==================== delete (ROLE_ADMIN) ====================

    public function testDeleteRejectsANonAdminUser(): void
    {
        $contact = $this->contact();
        $this->authenticateAs($this->createUser('contact-nonadmin-delete'));

        $this->client->request('DELETE', '/api/contacts/' . $contact->getId());

        self::assertResponseStatusCodeSame(403);
    }

    public function testDeleteReturns404ForAnUnknownId(): void
    {
        $this->authenticateAs($this->admin());

        $this->client->request('DELETE', '/api/contacts/999999');

        self::assertResponseStatusCodeSame(404);
    }

    public function testDeleteRemovesTheContact(): void
    {
        $contact = $this->contact();
        $contactId = $contact->getId();
        $this->authenticateAs($this->admin());

        $this->client->request('DELETE', '/api/contacts/' . $contactId);

        self::assertResponseStatusCodeSame(204);
        self::assertNull($this->em->getRepository(Contact::class)->find($contactId));
    }
}
