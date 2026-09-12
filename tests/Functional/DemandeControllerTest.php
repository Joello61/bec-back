<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\Demande;
use App\Entity\SubscriptionPlan;
use App\Entity\User;
use App\Entity\UserSubscription;
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

    // ==================== nombreVues (Lot 6.2) ====================

    public function testShowNeverExposesViewsCountToAThirdParty(): void
    {
        $owner = $this->createUser('owner-views-third-party');
        $viewer = $this->createUser('viewer-views-third-party');
        $demande = $this->createDemande($owner);
        $this->authenticateAs($viewer);

        $this->client->request('GET', '/api/demandes/' . $demande->getId());

        self::assertResponseIsSuccessful();
        $payload = json_decode($this->client->getResponse()->getContent(), true);
        self::assertArrayNotHasKey('nombreVues', $payload);
        self::assertArrayNotHasKey('nombreVuesLocked', $payload);
    }

    public function testShowLocksViewsCountForAnOwnerWithoutTheEntitlement(): void
    {
        $owner = $this->createUser('owner-views-locked');
        $demande = $this->createDemande($owner);
        $this->authenticateAs($owner);

        $this->client->request('GET', '/api/demandes/' . $demande->getId());

        self::assertResponseIsSuccessful();
        $payload = json_decode($this->client->getResponse()->getContent(), true);
        self::assertTrue($payload['nombreVuesLocked']);
        self::assertArrayNotHasKey('nombreVues', $payload);
    }

    public function testShowRevealsTheRealViewsCountForAnEntitledOwnerAndIncrementsForThirdParties(): void
    {
        $owner = $this->createUser('owner-views-entitled');
        $this->activeSubscription($owner, $this->planWithViewStats('plus-views-entitled'));
        $demande = $this->createDemande($owner);

        $viewer1 = $this->createUser('viewer-views-1');
        $this->authenticateAs($viewer1);
        $this->client->request('GET', '/api/demandes/' . $demande->getId());
        self::assertResponseIsSuccessful();

        $viewer2 = $this->createUser('viewer-views-2');
        $this->authenticateAs($viewer2);
        $this->client->request('GET', '/api/demandes/' . $demande->getId());
        self::assertResponseIsSuccessful();

        // Une vue du propriétaire lui-même ne doit jamais compter.
        $this->authenticateAs($owner);
        $this->client->request('GET', '/api/demandes/' . $demande->getId());

        self::assertResponseIsSuccessful();
        $payload = json_decode($this->client->getResponse()->getContent(), true);
        self::assertSame(2, $payload['nombreVues']);
        self::assertArrayNotHasKey('nombreVuesLocked', $payload);
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

    public function testListFiltersByVilleDepart(): void
    {
        $viewer = $this->createUser('filter-viewer');
        $owner = $this->createUser('filter-owner');
        $matching = $this->createDemande($owner, 'Douala-Filtre-Unique', 'Paris');
        $other = $this->createDemande($owner, 'Yaounde-Filtre-Unique', 'Paris');
        $this->authenticateAs($viewer);

        $this->client->request('GET', '/api/demandes?villeDepart=Douala-Filtre-Unique');

        self::assertResponseIsSuccessful();
        $payload = json_decode($this->client->getResponse()->getContent(), true);
        $ids = array_column($payload['data'], 'id');
        self::assertContains($matching->getId(), $ids);
        self::assertNotContains($other->getId(), $ids);
    }

    public function testPublicListFiltersByVilleDepart(): void
    {
        $owner = $this->createUser('filter-public-owner');
        $matching = $this->createDemande($owner, 'Douala-Public-Unique', 'Paris');
        $other = $this->createDemande($owner, 'Yaounde-Public-Unique', 'Paris');

        $this->client->request('GET', '/api/demandes/public?villeDepart=Douala-Public-Unique');

        self::assertResponseIsSuccessful();
        $payload = json_decode($this->client->getResponse()->getContent(), true);
        $ids = array_column($payload['data'], 'id');
        self::assertContains($matching->getId(), $ids);
        self::assertNotContains($other->getId(), $ids);
    }

    /**
     * Recherche texte libre admin (Phase 13/Lot B5, plan-correction-cobage.md) : sur le
     * proprietaire (nom/prenom/email), pas sur les villes - le champ frontend
     * (demandes-clients.tsx, admin) etait jusqu'ici totalement inerte.
     */
    public function testListFiltersBySearchOnOwnerName(): void
    {
        $viewer = $this->createUser('demande-search-viewer');
        $matchingOwner = $this->createUser('DemandeSearchOwnerUnique');
        $otherOwner = $this->createUser('demande-search-other-owner');
        $matching = $this->createDemande($matchingOwner);
        $other = $this->createDemande($otherOwner);
        $this->authenticateAs($viewer);

        $this->client->request('GET', '/api/demandes?search=DemandeSearchOwnerUnique');

        self::assertResponseIsSuccessful();
        $payload = json_decode($this->client->getResponse()->getContent(), true);
        $ids = array_column($payload['data'], 'id');
        self::assertContains($matching->getId(), $ids);
        self::assertNotContains($other->getId(), $ids);
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

    private function planWithViewStats(string $code): SubscriptionPlan
    {
        $plan = new SubscriptionPlan();
        $plan->setCode($code)->setName(ucfirst($code))->setPriceAmountEur('4.99')->setHasViewStats(true);
        $this->em->persist($plan);

        return $plan;
    }

    private function activeSubscription(User $user, SubscriptionPlan $plan): UserSubscription
    {
        $subscription = new UserSubscription();
        $subscription->setUser($user)
            ->setPlan($plan)
            ->setProvider(UserSubscription::PROVIDER_STRIPE)
            ->setStatus(UserSubscription::STATUS_ACTIVE)
            ->setAmount('4.99')
            ->setCurrency('EUR')
            ->setCurrentPeriodStart(new \DateTime('-1 day'))
            ->setCurrentPeriodEnd(new \DateTime('+29 days'));
        $this->em->persist($subscription);
        $this->em->flush();

        return $subscription;
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
