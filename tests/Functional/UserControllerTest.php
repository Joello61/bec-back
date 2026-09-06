<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\Address;
use App\Entity\Avis;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\BrowserKit\Cookie;

/**
 * Phase 2 du plan de correction (bec-docs/docs/plan-correction/plan-correction-cobage.md) :
 * /api/users et /api/users/search ne doivent exposer aucune PII (email, téléphone,
 * adresse précise, statut de bannissement) et doivent plafonner la pagination.
 */
class UserControllerTest extends WebTestCase
{
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

    public function testListCapsLimitAt50(): void
    {
        $viewer = $this->createUser('viewer-cap');
        $this->authenticateAs($viewer);

        $this->client->request('GET', '/api/users?limit=100000');

        self::assertResponseIsSuccessful();
        $payload = json_decode($this->client->getResponse()->getContent(), true);
        self::assertSame(50, $payload['pagination']['limit']);
        self::assertLessThanOrEqual(50, count($payload['data']));
    }

    public function testListExposesOnlyPublicFields(): void
    {
        $viewer = $this->createUser('viewer-list');
        $target = $this->createUser('target-list', 'Douala');
        $target->setTelephone('+237600000000');
        $target->setIsBanned(true);
        $target->setBanReason('test');
        $target->setBannedAt(new \DateTime());
        $this->em->flush();

        $rater = $this->createUser('rater-list');
        $this->rate($rater, $target, 4);
        $this->authenticateAs($viewer);

        $this->client->request('GET', '/api/users?limit=50');

        self::assertResponseIsSuccessful();
        $payload = json_decode($this->client->getResponse()->getContent(), true);
        $item = $this->findById($payload['data'], $target->getId());

        $this->assertPublicShapeOnly($item, $target);
    }

    public function testSearchExposesOnlyPublicFields(): void
    {
        $viewer = $this->createUser('viewer-search');
        $target = $this->createUser('target-search-unique', 'Yaoundé');
        $target->setTelephone('+237611111111');
        $this->em->flush();

        $rater = $this->createUser('rater-search');
        $this->rate($rater, $target, 5);
        $this->authenticateAs($viewer);

        $this->client->request('GET', '/api/users/search?q=target-search-unique');

        self::assertResponseIsSuccessful();
        $payload = json_decode($this->client->getResponse()->getContent(), true);
        $item = $this->findById($payload, $target->getId());

        $this->assertPublicShapeOnly($item, $target);
    }

    public function testUpdateMeStillReturnsFullProfile(): void
    {
        $viewer = $this->createUser('viewer-me');
        $this->authenticateAs($viewer);

        $this->client->request(
            'PUT',
            '/api/users/me',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: '{}'
        );

        self::assertResponseIsSuccessful();
        $payload = json_decode($this->client->getResponse()->getContent(), true);
        self::assertSame($viewer->getEmail(), $payload['email']);
        self::assertArrayHasKey('telephone', $payload);
    }

    public function testSearchIsRateLimitedAfter30RequestsPerMinute(): void
    {
        $viewer = $this->createUser('viewer-ratelimit');
        $this->authenticateAs($viewer);

        for ($i = 0; $i < 30; $i++) {
            $this->client->request('GET', '/api/users/search?q=ab');
            self::assertNotSame(429, $this->client->getResponse()->getStatusCode());
        }

        $this->client->request('GET', '/api/users/search?q=ab');
        self::assertSame(429, $this->client->getResponse()->getStatusCode());
    }

    private function createUser(string $emailPrefix, string $ville = 'Douala'): User
    {
        $user = new User();
        $user->setEmail($emailPrefix . '-' . uniqid() . '@example.test');
        $user->setNom('Test');
        $user->setPrenom($emailPrefix);
        $user->setPassword('irrelevant');

        $address = new Address();
        $address->setPays('Cameroun');
        $address->setVille($ville);
        $address->setAdresseLigne1('12 rue secrète');
        $address->setUser($user);
        $user->setAddress($address);

        $this->em->persist($user);
        $this->em->persist($address);
        $this->em->flush();

        return $user;
    }

    private function rate(User $auteur, User $cible, int $note): void
    {
        $avis = new Avis();
        $avis->setAuteur($auteur);
        $avis->setCible($cible);
        $avis->setNote($note);
        $this->em->persist($avis);
        $this->em->flush();
    }

    private function authenticateAs(User $user): void
    {
        $token = $this->jwtManager->create($user);
        $this->client->getCookieJar()->set(new Cookie('bagage_token', $token, null, '/', 'localhost', false, false));
    }

    private function findById(array $items, int $id): array
    {
        foreach ($items as $item) {
            if (($item['id'] ?? null) === $id) {
                return $item;
            }
        }

        self::fail(sprintf('Utilisateur %d introuvable dans la réponse', $id));
    }

    private function assertPublicShapeOnly(array $item, User $target): void
    {
        self::assertSame($target->getId(), $item['id']);
        self::assertSame($target->getPrenom(), $item['prenom']);
        self::assertSame($target->getNom(), $item['nom']);
        self::assertSame($target->getAddress()?->getVille(), $item['address']['ville']);
        self::assertArrayHasKey('noteAvisMoyen', $item);
        self::assertGreaterThan(0, $item['noteAvisMoyen']);

        self::assertArrayNotHasKey('email', $item);
        self::assertArrayNotHasKey('telephone', $item);
        self::assertArrayNotHasKey('roles', $item);
        self::assertArrayNotHasKey('emailVerifie', $item);
        self::assertArrayNotHasKey('telephoneVerifie', $item);
        self::assertArrayNotHasKey('isBanned', $item);
        self::assertArrayNotHasKey('bannedAt', $item);
        self::assertArrayNotHasKey('banReason', $item);
        self::assertArrayNotHasKey('createdAt', $item);
        self::assertArrayNotHasKey('updatedAt', $item);
        self::assertArrayNotHasKey('settings', $item);
        self::assertArrayNotHasKey('pays', $item['address']);
        self::assertArrayNotHasKey('adresseLigne1', $item['address']);
    }
}
