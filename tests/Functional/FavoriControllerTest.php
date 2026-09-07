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
 * Phase 4b, Lot 8 (bec-docs/docs/plan-correction/plan-correction-cobage.md) : integration
 * HTTP de FavoriController - controleur mince, delegue entierement a FavoriService
 * (deja teste unitairement en Lot 6, anti-doublon et controle d'appartenance sur remove()).
 */
class FavoriControllerTest extends WebTestCase
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

    private function voyage(User $voyageur): Voyage
    {
        $voyage = new Voyage();
        $voyage->setVoyageur($voyageur);
        $voyage->setVilleDepart('Douala');
        $voyage->setVilleArrivee('Paris');
        $voyage->setDateDepart(new \DateTime('+5 days'));
        $voyage->setDateArrivee(new \DateTime('+6 days'));
        $voyage->setPoidsDisponible('20');
        $voyage->setPoidsDisponibleRestant('20');
        $this->em->persist($voyage);
        $this->em->flush();

        return $voyage;
    }

    private function demande(User $client): Demande
    {
        $demande = new Demande();
        $demande->setClient($client);
        $demande->setVilleDepart('Douala');
        $demande->setVilleArrivee('Paris');
        $demande->setPoidsEstime('5');
        $demande->setDescription('Demande de test');
        $this->em->persist($demande);
        $this->em->flush();

        return $demande;
    }

    // ==================== auth requise ====================

    public function testListRequiresAuthentication(): void
    {
        $this->client->request('GET', '/api/favoris');

        self::assertResponseStatusCodeSame(403);
    }

    // ==================== addVoyage ====================

    public function testAddVoyageReturns404ForAnUnknownVoyage(): void
    {
        $this->authenticateAs($this->createUser('favori-addvoyage-404'));

        $this->client->request('POST', '/api/favoris/voyage/999999');

        self::assertResponseStatusCodeSame(404);
    }

    public function testAddVoyageSucceeds(): void
    {
        $user = $this->createUser('favori-addvoyage-ok');
        $voyageur = $this->createUser('favori-addvoyage-voyageur');
        $voyage = $this->voyage($voyageur);
        $this->authenticateAs($user);

        $this->client->request('POST', '/api/favoris/voyage/' . $voyage->getId());

        self::assertResponseStatusCodeSame(201);
    }

    public function testAddVoyageRejectsADuplicate(): void
    {
        $user = $this->createUser('favori-addvoyage-dup');
        $voyageur = $this->createUser('favori-addvoyage-dup-voyageur');
        $voyage = $this->voyage($voyageur);
        $this->authenticateAs($user);
        $this->client->request('POST', '/api/favoris/voyage/' . $voyage->getId());

        $this->client->request('POST', '/api/favoris/voyage/' . $voyage->getId());

        self::assertResponseStatusCodeSame(400);
    }

    // ==================== addDemande ====================

    public function testAddDemandeSucceeds(): void
    {
        $user = $this->createUser('favori-adddemande-ok');
        $client = $this->createUser('favori-adddemande-client');
        $demande = $this->demande($client);
        $this->authenticateAs($user);

        $this->client->request('POST', '/api/favoris/demande/' . $demande->getId());

        self::assertResponseStatusCodeSame(201);
    }

    // ==================== list / voyages / demandes ====================

    public function testListReturnsOnlyTheAuthenticatedUsersFavoris(): void
    {
        $user = $this->createUser('favori-list-owner');
        $stranger = $this->createUser('favori-list-stranger');
        $voyageur = $this->createUser('favori-list-voyageur');
        $voyage = $this->voyage($voyageur);
        $this->authenticateAs($user);
        $this->client->request('POST', '/api/favoris/voyage/' . $voyage->getId());

        $this->authenticateAs($stranger);
        $this->client->request('GET', '/api/favoris');

        self::assertResponseIsSuccessful();
        $payload = json_decode($this->client->getResponse()->getContent(), true);
        self::assertSame([], $payload);
    }

    public function testVoyagesReturnsOnlyFavoriteVoyages(): void
    {
        $user = $this->createUser('favori-voyages-ok');
        $voyageur = $this->createUser('favori-voyages-voyageur');
        $client = $this->createUser('favori-voyages-client');
        $voyage = $this->voyage($voyageur);
        $demande = $this->demande($client);
        $this->authenticateAs($user);
        $this->client->request('POST', '/api/favoris/voyage/' . $voyage->getId());
        $this->client->request('POST', '/api/favoris/demande/' . $demande->getId());

        $this->client->request('GET', '/api/favoris/voyages');

        self::assertResponseIsSuccessful();
        $payload = json_decode($this->client->getResponse()->getContent(), true);
        self::assertCount(1, $payload);
        self::assertSame($voyage->getId(), $payload[0]['voyage']['id']);
    }

    // ==================== remove ====================

    public function testRemoveRejectsAnInvalidType(): void
    {
        $this->authenticateAs($this->createUser('favori-remove-invalid-type'));

        $this->client->request('DELETE', '/api/favoris/invalide/1');

        self::assertResponseStatusCodeSame(400);
    }

    public function testRemoveReturns404WhenNotFavorited(): void
    {
        $user = $this->createUser('favori-remove-404');
        $voyageur = $this->createUser('favori-remove-404-voyageur');
        $voyage = $this->voyage($voyageur);
        $this->authenticateAs($user);

        $this->client->request('DELETE', '/api/favoris/voyage/' . $voyage->getId());

        self::assertResponseStatusCodeSame(404);
    }

    public function testRemoveCannotTargetAnotherUsersFavori(): void
    {
        // removeFromFavoris(voyageId, type, user) recherche le favori scope par
        // (user courant, voyageId) - un autre utilisateur ne peut donc jamais viser le
        // favori de quelqu'un d'autre via cette route, meme en devinant le bon voyageId :
        // la recherche renvoie null pour lui -> 404, jamais 403 (IDOR impossible ici).
        $owner = $this->createUser('favori-remove-owner');
        $voyageur = $this->createUser('favori-remove-owner-voyageur');
        $voyage = $this->voyage($voyageur);
        $this->authenticateAs($owner);
        $this->client->request('POST', '/api/favoris/voyage/' . $voyage->getId());

        $stranger = $this->createUser('favori-remove-stranger');
        $this->authenticateAs($stranger);
        $this->client->request('DELETE', '/api/favoris/voyage/' . $voyage->getId());

        self::assertResponseStatusCodeSame(404);
    }

    public function testRemoveSucceedsForTheOwner(): void
    {
        $user = $this->createUser('favori-remove-ok');
        $voyageur = $this->createUser('favori-remove-ok-voyageur');
        $voyage = $this->voyage($voyageur);
        $this->authenticateAs($user);
        $this->client->request('POST', '/api/favoris/voyage/' . $voyage->getId());

        $this->client->request('DELETE', '/api/favoris/voyage/' . $voyage->getId());

        self::assertResponseStatusCodeSame(204);
    }
}
