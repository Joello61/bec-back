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
 * /api/demandes ne doit exposer email/telephone du client que si ses préférences
 * de confidentialité (VisibilityService) l'autorisent pour le viewer courant -
 * plus jamais inconditionnellement via les groupes demande:read/demande:list.
 * Symétrique de VoyageControllerTest.
 */
class DemandeControllerTest extends WebTestCase
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
        $demande = $this->createDemande($owner);
        $this->authenticateAs($viewer);

        $this->client->request('GET', '/api/demandes/' . $demande->getId());

        self::assertResponseIsSuccessful();
        $payload = json_decode($this->client->getResponse()->getContent(), true);
        self::assertArrayNotHasKey('email', $payload['client']);
        self::assertArrayNotHasKey('telephone', $payload['client']);
    }

    public function testShowRevealsOwnContactToTheOwnerThemself(): void
    {
        $owner = $this->createUser('owner-self', showEmail: false, showPhone: false);
        $owner->setTelephone('+237600000004');
        $this->em->flush();
        $demande = $this->createDemande($owner);
        $this->authenticateAs($owner);

        $this->client->request('GET', '/api/demandes/' . $demande->getId());

        self::assertResponseIsSuccessful();
        $payload = json_decode($this->client->getResponse()->getContent(), true);
        self::assertSame($owner->getEmail(), $payload['client']['email']);
        self::assertSame($owner->getTelephone(), $payload['client']['telephone']);
    }

    public function testListDoesNotMixContactVisibilityBetweenDifferentClients(): void
    {
        $viewer = $this->createUser('viewer-list');
        $openOwner = $this->createUser('open-owner', showEmail: true, showPhone: true);
        $privateOwner = $this->createUser('private-owner', showEmail: false, showPhone: false);
        $this->createDemande($openOwner);
        $this->createDemande($privateOwner);
        $this->authenticateAs($viewer);

        $this->client->request('GET', '/api/demandes');

        self::assertResponseIsSuccessful();
        $payload = json_decode($this->client->getResponse()->getContent(), true);
        $openItem = $this->findByClientId($payload['data'], $openOwner->getId());
        $privateItem = $this->findByClientId($payload['data'], $privateOwner->getId());

        self::assertSame($openOwner->getEmail(), $openItem['client']['email']);
        self::assertArrayNotHasKey('email', $privateItem['client']);
        self::assertArrayNotHasKey('telephone', $privateItem['client']);
    }

    public function testUpdateReturnsOwnContactToTheOwner(): void
    {
        // Vérifie le chemin "entité brute -> $this->json(..., ['groups' => ...])" converti en
        // normalize()+injectContactIfVisible() par cette phase. update() n'exige pas de profil
        // complet, contrairement à create() (DEMANDE_CREATE) - suffisant pour ce pattern.
        $owner = $this->createUser('update-owner', showEmail: false, showPhone: false);
        $owner->setTelephone('+237600000005');
        $this->em->flush();
        $demande = $this->createDemande($owner);
        $this->authenticateAs($owner);

        $this->client->request(
            'PUT',
            '/api/demandes/' . $demande->getId(),
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode(['description' => 'description mise a jour'])
        );

        self::assertResponseIsSuccessful();
        $payload = json_decode($this->client->getResponse()->getContent(), true);
        self::assertSame($owner->getEmail(), $payload['client']['email']);
        self::assertSame($owner->getTelephone(), $payload['client']['telephone']);
    }

    public function testMatchingVoyagesInjectsContactForVoyageurAccordingToPreferences(): void
    {
        $client = $this->createUser('matching-client');
        $voyageur = $this->createUser('matching-voyageur', showEmail: true, showPhone: false);
        $demande = $this->createDemande($client, 'Douala-Unique-DV', 'Paris-Unique-DV');

        $voyage = new Voyage();
        $voyage->setVoyageur($voyageur);
        $voyage->setVilleDepart('Douala-Unique-DV');
        $voyage->setVilleArrivee('Paris-Unique-DV');
        $voyage->setDateDepart(new \DateTime('+5 days'));
        $voyage->setDateArrivee(new \DateTime('+6 days'));
        $voyage->setPoidsDisponible('10');
        $voyage->setPoidsDisponibleRestant('10');
        $this->em->persist($voyage);
        $this->em->flush();

        $this->authenticateAs($client);

        $this->client->request('GET', '/api/demandes/' . $demande->getId() . '/matching-voyages');

        self::assertResponseIsSuccessful();
        $payload = json_decode($this->client->getResponse()->getContent(), true);
        self::assertNotEmpty($payload, 'le voyage créé devrait matcher (mêmes villes, pas de date limite)');
        self::assertSame($voyageur->getEmail(), $payload[0]['voyage']['voyageur']['email']);
        self::assertArrayNotHasKey('telephone', $payload[0]['voyage']['voyageur']);
    }

    private function createDemande(User $client, string $villeDepart = 'Douala', string $villeArrivee = 'Paris'): Demande
    {
        $demande = new Demande();
        $demande->setClient($client);
        $demande->setVilleDepart($villeDepart);
        $demande->setVilleArrivee($villeArrivee);
        $demande->setPoidsEstime('5');
        $demande->setDescription('Demande de test Phase 10');
        $this->em->persist($demande);
        $this->em->flush();

        return $demande;
    }

    private function findByClientId(array $items, int $clientId): array
    {
        foreach ($items as $item) {
            if (($item['client']['id'] ?? null) === $clientId) {
                return $item;
            }
        }

        self::fail(sprintf('Demande du client %d introuvable dans la réponse', $clientId));
    }
}
