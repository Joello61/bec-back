<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\Demande;
use App\Entity\Proposition;
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
 * HTTP de PropositionController - le detail des regles metier (7 validations de creation,
 * cascade d'acceptation/annulation, y compris le bug #4 du plan deja corrige au Lot 3 sur
 * deleteProposition) est deja verifie exhaustivement par PropositionServiceTest (28 tests).
 * Ici on verifie le cablage HTTP : codes de statut, auth, et que la regression du Lot 3
 * (deleteProposition rejetant toujours toute annulation) reste bien impossible via la route.
 */
class PropositionControllerTest extends WebTestCase
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

    private function proposition(Voyage $voyage, Demande $demande, string $statut = 'en_attente'): Proposition
    {
        $proposition = new Proposition();
        $proposition->setVoyage($voyage);
        $proposition->setDemande($demande);
        $proposition->setClient($demande->getClient());
        $proposition->setVoyageur($voyage->getVoyageur());
        $proposition->setPrixParKilo('10');
        $proposition->setCommissionProposeePourUnBagage('5');
        $proposition->setStatut($statut);
        $this->em->persist($proposition);
        $this->em->flush();

        return $proposition;
    }

    private function validPayload(int $demandeId): array
    {
        return [
            'demandeId' => $demandeId,
            'prixParKilo' => 10.0,
            'commissionProposeePourUnBagage' => 5.0,
            'message' => 'Je peux transporter votre colis',
        ];
    }

    // ==================== create ====================

    public function testCreateRequiresAuthentication(): void
    {
        $this->client->request(
            'POST',
            '/api/propositions/voyage/1',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode($this->validPayload(1))
        );

        self::assertResponseStatusCodeSame(403);
    }

    public function testCreateRejectsAnInvalidPayload(): void
    {
        $this->authenticateAs($this->createUser('proposition-create-invalid'));

        $this->client->request(
            'POST',
            '/api/propositions/voyage/1',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode(['demandeId' => -1, 'prixParKilo' => -5, 'commissionProposeePourUnBagage' => -1])
        );

        self::assertResponseStatusCodeSame(422);
    }

    public function testCreateReturns404ForAnUnknownVoyage(): void
    {
        $client = $this->createUser('proposition-create-novoyage');
        $demande = $this->demande($client);
        $this->authenticateAs($client);

        $this->client->request(
            'POST',
            '/api/propositions/voyage/999999',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode($this->validPayload($demande->getId()))
        );

        self::assertResponseStatusCodeSame(404);
    }

    public function testCreateRejectsADemandeThatIsNotTheCallers(): void
    {
        $voyageur = $this->createUser('proposition-create-notmine-voyageur');
        $voyage = $this->voyage($voyageur);
        $owner = $this->createUser('proposition-create-notmine-owner');
        $demande = $this->demande($owner);
        $this->authenticateAs($this->createUser('proposition-create-notmine-caller'));

        $this->client->request(
            'POST',
            '/api/propositions/voyage/' . $voyage->getId(),
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode($this->validPayload($demande->getId()))
        );

        self::assertResponseStatusCodeSame(400);
    }

    public function testCreateSucceedsAndInheritsTheDemandesCurrency(): void
    {
        $voyageur = $this->createUser('proposition-create-ok-voyageur');
        $voyage = $this->voyage($voyageur);
        $client = $this->createUser('proposition-create-ok-client');
        $demande = $this->demande($client);
        $this->authenticateAs($client);

        $this->client->request(
            'POST',
            '/api/propositions/voyage/' . $voyage->getId(),
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode($this->validPayload($demande->getId()))
        );

        self::assertResponseStatusCodeSame(201);
        $payload = json_decode($this->client->getResponse()->getContent(), true);
        self::assertSame('en_attente', $payload['statut']);
        self::assertSame($demande->getCurrency(), $payload['currency']);
    }

    public function testCreateRejectsADuplicateProposition(): void
    {
        $voyageur = $this->createUser('proposition-create-dup-voyageur');
        $voyage = $this->voyage($voyageur);
        $client = $this->createUser('proposition-create-dup-client');
        $demande = $this->demande($client);
        $this->authenticateAs($client);
        $this->client->request(
            'POST',
            '/api/propositions/voyage/' . $voyage->getId(),
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode($this->validPayload($demande->getId()))
        );

        $this->client->request(
            'POST',
            '/api/propositions/voyage/' . $voyage->getId(),
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode($this->validPayload($demande->getId()))
        );

        self::assertResponseStatusCodeSame(400);
    }

    // ==================== respond ====================

    public function testRespondReturns404ForAnUnknownProposition(): void
    {
        $this->authenticateAs($this->createUser('proposition-respond-404'));

        $this->client->request(
            'PATCH',
            '/api/propositions/999999/respond',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode(['action' => 'accepter'])
        );

        self::assertResponseStatusCodeSame(404);
    }

    public function testRespondRejectsANonVoyageurUser(): void
    {
        $voyageur = $this->createUser('proposition-respond-notmine-voyageur');
        $voyage = $this->voyage($voyageur);
        $client = $this->createUser('proposition-respond-notmine-client');
        $demande = $this->demande($client);
        $proposition = $this->proposition($voyage, $demande);
        $this->authenticateAs($this->createUser('proposition-respond-notmine-stranger'));

        $this->client->request(
            'PATCH',
            '/api/propositions/' . $proposition->getId() . '/respond',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode(['action' => 'accepter'])
        );

        self::assertResponseStatusCodeSame(400);
    }

    public function testRespondAcceptsAProposition(): void
    {
        $voyageur = $this->createUser('proposition-respond-accept-voyageur');
        $voyage = $this->voyage($voyageur);
        $client = $this->createUser('proposition-respond-accept-client');
        $demande = $this->demande($client);
        $proposition = $this->proposition($voyage, $demande);
        $this->authenticateAs($voyageur);

        $this->client->request(
            'PATCH',
            '/api/propositions/' . $proposition->getId() . '/respond',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode(['action' => 'accepter'])
        );

        self::assertResponseIsSuccessful();
        $payload = json_decode($this->client->getResponse()->getContent(), true);
        self::assertSame('acceptee', $payload['statut']);
    }

    public function testRespondRefusesAProposition(): void
    {
        $voyageur = $this->createUser('proposition-respond-refuse-voyageur');
        $voyage = $this->voyage($voyageur);
        $client = $this->createUser('proposition-respond-refuse-client');
        $demande = $this->demande($client);
        $proposition = $this->proposition($voyage, $demande);
        $this->authenticateAs($voyageur);

        $this->client->request(
            'PATCH',
            '/api/propositions/' . $proposition->getId() . '/respond',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode(['action' => 'refuser', 'messageRefus' => 'Trop lourd'])
        );

        self::assertResponseIsSuccessful();
        $payload = json_decode($this->client->getResponse()->getContent(), true);
        self::assertSame('refusee', $payload['statut']);
    }

    // ==================== delete (regression bug #4 / Lot 3) ====================

    public function testDeleteReturns404ForAnUnknownProposition(): void
    {
        $this->authenticateAs($this->createUser('proposition-delete-404'));

        $this->client->request('DELETE', '/api/propositions/999999');

        self::assertResponseStatusCodeSame(404);
    }

    public function testDeleteRejectsANonClientUser(): void
    {
        $voyageur = $this->createUser('proposition-delete-notmine-voyageur');
        $voyage = $this->voyage($voyageur);
        $client = $this->createUser('proposition-delete-notmine-client');
        $demande = $this->demande($client);
        $proposition = $this->proposition($voyage, $demande);
        $this->authenticateAs($this->createUser('proposition-delete-notmine-stranger'));

        $this->client->request('DELETE', '/api/propositions/' . $proposition->getId());

        self::assertResponseStatusCodeSame(400);
    }

    public function testDeleteRejectsAnAlreadyAcceptedProposition(): void
    {
        // Regression du bug #4 (Lot 3) : avant correction, la condition inversee
        // (|| au lieu de &&) empechait TOUTE annulation, meme d'une proposition
        // en_attente. Ici on verifie le cas symetrique attendu : une proposition
        // deja acceptee reste, elle, bien rejetee (400), pas une 500.
        $voyageur = $this->createUser('proposition-delete-accepted-voyageur');
        $voyage = $this->voyage($voyageur);
        $client = $this->createUser('proposition-delete-accepted-client');
        $demande = $this->demande($client);
        $proposition = $this->proposition($voyage, $demande, statut: 'acceptee');
        $this->authenticateAs($client);

        $this->client->request('DELETE', '/api/propositions/' . $proposition->getId());

        self::assertResponseStatusCodeSame(400);
    }

    public function testDeleteCancelsAPendingProposition(): void
    {
        // Coeur de la regression du Lot 3 : une proposition en_attente DOIT pouvoir
        // etre annulee par son client - c'est exactement le cas que le bug cassait.
        $voyageur = $this->createUser('proposition-delete-ok-voyageur');
        $voyage = $this->voyage($voyageur);
        $client = $this->createUser('proposition-delete-ok-client');
        $demande = $this->demande($client);
        $proposition = $this->proposition($voyage, $demande, statut: 'en_attente');
        $this->authenticateAs($client);

        $this->client->request('DELETE', '/api/propositions/' . $proposition->getId());

        self::assertResponseIsSuccessful();
        $payload = json_decode($this->client->getResponse()->getContent(), true);
        self::assertTrue($payload['success']);
    }

    // ==================== consultation ====================

    public function testByVoyageReturnsThePropositionsWithConversion(): void
    {
        $voyageur = $this->createUser('proposition-byvoyage-voyageur');
        $voyage = $this->voyage($voyageur);
        $client = $this->createUser('proposition-byvoyage-client');
        $demande = $this->demande($client);
        $this->proposition($voyage, $demande);
        $this->authenticateAs($voyageur);

        $this->client->request('GET', '/api/propositions/voyage/' . $voyage->getId());

        self::assertResponseIsSuccessful();
        $payload = json_decode($this->client->getResponse()->getContent(), true);
        self::assertCount(1, $payload);
        self::assertArrayHasKey('viewerCurrency', $payload[0]);
        self::assertArrayNotHasKey('converted', $payload[0], 'meme devise (EUR/EUR) : pas de bloc converted, donc pas d\'appel externe');
    }

    public function testAcceptedByVoyageOnlyReturnsAcceptedOnes(): void
    {
        $voyageur = $this->createUser('proposition-accepted-voyageur');
        $voyage = $this->voyage($voyageur);
        $client1 = $this->createUser('proposition-accepted-client1');
        $client2 = $this->createUser('proposition-accepted-client2');
        $this->proposition($voyage, $this->demande($client1), statut: 'acceptee');
        $this->proposition($voyage, $this->demande($client2), statut: 'en_attente');
        $this->authenticateAs($voyageur);

        $this->client->request('GET', '/api/propositions/voyage/' . $voyage->getId() . '/accepted');

        self::assertResponseIsSuccessful();
        $payload = json_decode($this->client->getResponse()->getContent(), true);
        self::assertCount(1, $payload);
    }

    public function testGetOneReturnsTheProposition(): void
    {
        $voyageur = $this->createUser('proposition-getone-voyageur');
        $voyage = $this->voyage($voyageur);
        $client = $this->createUser('proposition-getone-client');
        $demande = $this->demande($client);
        $proposition = $this->proposition($voyage, $demande);
        $this->authenticateAs($voyageur);

        $this->client->request('GET', '/api/propositions/' . $proposition->getId());

        self::assertResponseIsSuccessful();
        $payload = json_decode($this->client->getResponse()->getContent(), true);
        self::assertSame($proposition->getId(), $payload['id']);
    }

    public function testMySentReturnsOnlyTheCallersOwnPropositions(): void
    {
        $voyageur = $this->createUser('proposition-mysent-voyageur');
        $voyage = $this->voyage($voyageur);
        $client = $this->createUser('proposition-mysent-client');
        $other = $this->createUser('proposition-mysent-other');
        $this->proposition($voyage, $this->demande($client));
        $this->proposition($voyage, $this->demande($other));
        $this->authenticateAs($client);

        $this->client->request('GET', '/api/propositions/me/sent');

        self::assertResponseIsSuccessful();
        $payload = json_decode($this->client->getResponse()->getContent(), true);
        self::assertCount(1, $payload);
    }

    public function testMyReceivedReturnsOnlyPropositionsForTheCallersVoyages(): void
    {
        $voyageur = $this->createUser('proposition-myreceived-voyageur');
        $otherVoyageur = $this->createUser('proposition-myreceived-other-voyageur');
        $voyage = $this->voyage($voyageur);
        $otherVoyage = $this->voyage($otherVoyageur);
        $client = $this->createUser('proposition-myreceived-client');
        $this->proposition($voyage, $this->demande($client));
        $this->proposition($otherVoyage, $this->demande($this->createUser('proposition-myreceived-client2')));
        $this->authenticateAs($voyageur);

        $this->client->request('GET', '/api/propositions/me/received');

        self::assertResponseIsSuccessful();
        $payload = json_decode($this->client->getResponse()->getContent(), true);
        self::assertCount(1, $payload);
    }

    public function testMyPendingCountReturnsTheRightCount(): void
    {
        $voyageur = $this->createUser('proposition-pendingcount-voyageur');
        $voyage = $this->voyage($voyageur);
        $client1 = $this->createUser('proposition-pendingcount-client1');
        $client2 = $this->createUser('proposition-pendingcount-client2');
        $this->proposition($voyage, $this->demande($client1), statut: 'en_attente');
        $this->proposition($voyage, $this->demande($client2), statut: 'acceptee');
        $this->authenticateAs($voyageur);

        $this->client->request('GET', '/api/propositions/me/pending-count');

        self::assertResponseIsSuccessful();
        $payload = json_decode($this->client->getResponse()->getContent(), true);
        self::assertSame(1, $payload['count']);
    }
}
