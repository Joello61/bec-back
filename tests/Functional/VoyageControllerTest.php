<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\Demande;
use App\Entity\User;
use App\Entity\Voyage;
use App\Tests\Support\JwtAuthenticationTrait;
use App\Tests\Support\UserFactoryTrait;
use Doctrine\ORM\EntityManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Phase 10 du plan de correction (bec-docs/docs/plan-correction/plan-correction-cobage.md) :
 * /api/voyages ne doit exposer email/telephone du voyageur que si ses préférences
 * de confidentialité (VisibilityService) l'autorisent pour le viewer courant -
 * plus jamais inconditionnellement via les groupes voyage:read/voyage:list.
 */
class VoyageControllerTest extends WebTestCase
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

    public function testShowHidesContactFromThirdPartyWhenOwnerDisallowsIt(): void
    {
        $owner = $this->createUser('owner-show', showEmail: false, showPhone: false);
        $viewer = $this->createUser('viewer-show');
        $voyage = $this->createVoyage($owner);
        $this->authenticateAs($viewer);

        $this->client->request('GET', '/api/voyages/' . $voyage->getId());

        self::assertResponseIsSuccessful();
        $payload = json_decode($this->client->getResponse()->getContent(), true);
        self::assertArrayNotHasKey('email', $payload['voyageur']);
        self::assertArrayNotHasKey('telephone', $payload['voyageur']);
    }

    public function testShowRevealsOwnContactToTheOwnerThemself(): void
    {
        $owner = $this->createUser('owner-self', showEmail: false, showPhone: false);
        $owner->setTelephone('+237600000001');
        $this->em->flush();
        $voyage = $this->createVoyage($owner);
        $this->authenticateAs($owner);

        $this->client->request('GET', '/api/voyages/' . $voyage->getId());

        self::assertResponseIsSuccessful();
        $payload = json_decode($this->client->getResponse()->getContent(), true);
        self::assertSame($owner->getEmail(), $payload['voyageur']['email']);
        self::assertSame($owner->getTelephone(), $payload['voyageur']['telephone']);
    }

    public function testListDoesNotMixContactVisibilityBetweenDifferentVoyageurs(): void
    {
        $viewer = $this->createUser('viewer-list');
        $openOwner = $this->createUser('open-owner', showEmail: true, showPhone: true);
        $privateOwner = $this->createUser('private-owner', showEmail: false, showPhone: false);
        $this->createVoyage($openOwner);
        $this->createVoyage($privateOwner);
        $this->authenticateAs($viewer);

        $this->client->request('GET', '/api/voyages');

        self::assertResponseIsSuccessful();
        $payload = json_decode($this->client->getResponse()->getContent(), true);
        $openItem = $this->findByVoyageurId($payload['data'], $openOwner->getId());
        $privateItem = $this->findByVoyageurId($payload['data'], $privateOwner->getId());

        self::assertSame($openOwner->getEmail(), $openItem['voyageur']['email']);
        self::assertArrayNotHasKey('email', $privateItem['voyageur']);
        self::assertArrayNotHasKey('telephone', $privateItem['voyageur']);
    }

    public function testUpdateReturnsOwnContactToTheOwner(): void
    {
        // Vérifie le chemin "entité brute -> $this->json(..., ['groups' => ...])" converti en
        // normalize()+injectContactIfVisible() par cette phase. create()/updateStatus()
        // suivent exactement le même chemin de code mais déclenchent en plus une publication
        // Mercure réelle (RealtimeNotifier), non joignable en environnement de test (aucun hub
        // configuré, cf. Phase D0) - update() n'a pas cet effet de bord et exerce le même pattern.
        $owner = $this->createUser('update-owner', showEmail: false, showPhone: false);
        $owner->setTelephone('+237600000003');
        $this->em->flush();
        $voyage = $this->createVoyage($owner);
        $this->authenticateAs($owner);

        $this->client->request(
            'PUT',
            '/api/voyages/' . $voyage->getId(),
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode(['description' => 'mis a jour'])
        );

        self::assertResponseIsSuccessful();
        $payload = json_decode($this->client->getResponse()->getContent(), true);
        self::assertSame($owner->getEmail(), $payload['voyageur']['email']);
        self::assertSame($owner->getTelephone(), $payload['voyageur']['telephone']);
    }

    public function testMatchingDemandesInjectsContactForClientAccordingToPreferences(): void
    {
        $voyageur = $this->createCompleteProfileUser('matching-voyageur');
        $client = $this->createUser('matching-client', showEmail: true, showPhone: false);
        $voyage = $this->createVoyage($voyageur, 'Douala-Unique-VD', 'Paris-Unique-VD');

        $demande = new Demande();
        $demande->setClient($client);
        $demande->setVilleDepart('Douala-Unique-VD');
        $demande->setVilleArrivee('Paris-Unique-VD');
        $demande->setPoidsEstime('5');
        $demande->setDescription('Phase 10 matching test');
        $this->em->persist($demande);
        $this->em->flush();

        $this->authenticateAs($voyageur);

        $this->client->request('GET', '/api/voyages/' . $voyage->getId() . '/matching-demandes');

        self::assertResponseIsSuccessful();
        $payload = json_decode($this->client->getResponse()->getContent(), true);
        self::assertNotEmpty($payload, 'la demande créée devrait matcher (mêmes villes, pas de date limite)');
        self::assertSame($client->getEmail(), $payload[0]['demande']['client']['email']);
        self::assertArrayNotHasKey('telephone', $payload[0]['demande']['client']);
    }

    private function createVoyage(User $voyageur, string $villeDepart = 'Douala', string $villeArrivee = 'Paris'): Voyage
    {
        $voyage = new Voyage();
        $voyage->setVoyageur($voyageur);
        $voyage->setVilleDepart($villeDepart);
        $voyage->setVilleArrivee($villeArrivee);
        $voyage->setDateDepart(new \DateTime('+5 days'));
        $voyage->setDateArrivee(new \DateTime('+6 days'));
        $voyage->setPoidsDisponible('10');
        $voyage->setPoidsDisponibleRestant('10');
        $this->em->persist($voyage);
        $this->em->flush();

        return $voyage;
    }

    private function findByVoyageurId(array $items, int $voyageurId): array
    {
        foreach ($items as $item) {
            if (($item['voyageur']['id'] ?? null) === $voyageurId) {
                return $item;
            }
        }

        self::fail(sprintf('Voyage du voyageur %d introuvable dans la réponse', $voyageurId));
    }
}
