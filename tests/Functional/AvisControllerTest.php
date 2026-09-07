<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\Avis;
use App\Entity\User;
use App\Tests\Support\JwtAuthenticationTrait;
use App\Tests\Support\UserFactoryTrait;
use Doctrine\ORM\EntityManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Phase 4b, Lot 8 (bec-docs/docs/plan-correction/plan-correction-cobage.md) : integration
 * HTTP d'AvisController - le detail des regles d'autorisation (profil complet pour creer,
 * seul l'auteur/un admin peut modifier/supprimer) est deja verifie unitairement par
 * AvisVoterTest (Phase 4) ; ici on verifie que le controleur applique bien ces Voters
 * (403/404/succes) et cable correctement le DTO/les groupes de serialisation.
 */
class AvisControllerTest extends WebTestCase
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

    private function avis(User $auteur, User $cible, int $note = 4): Avis
    {
        $avis = new Avis();
        $avis->setAuteur($auteur);
        $avis->setCible($cible);
        $avis->setNote($note);
        $avis->setCommentaire('Tres bien');
        $this->em->persist($avis);
        $this->em->flush();

        return $avis;
    }

    // ==================== byUser ====================

    public function testByUserRequiresAuthentication(): void
    {
        $cible = $this->createUser('avis-cible-byuser');

        $this->client->request('GET', '/api/avis/user/' . $cible->getId());

        self::assertResponseStatusCodeSame(403);
    }

    public function testByUserReturnsAvisAndStats(): void
    {
        $auteur = $this->createUser('avis-auteur-byuser');
        $cible = $this->createUser('avis-cible-byuser2');
        $this->avis($auteur, $cible, 5);
        $this->authenticateAs($this->createUser('avis-viewer-byuser'));

        $this->client->request('GET', '/api/avis/user/' . $cible->getId());

        self::assertResponseIsSuccessful();
        $payload = json_decode($this->client->getResponse()->getContent(), true);
        self::assertCount(1, $payload['avis']);
        self::assertSame(1, $payload['stats']['total']);
    }

    // ==================== create ====================

    public function testCreateRejectsAnIncompleteProfile(): void
    {
        $auteur = $this->createUser('avis-incomplete'); // pas de createCompleteProfileUser
        $cible = $this->createUser('avis-cible-incomplete');
        $this->authenticateAs($auteur);

        $this->client->request(
            'POST',
            '/api/avis',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode(['cibleId' => $cible->getId(), 'note' => 5, 'commentaire' => 'Super'])
        );

        self::assertResponseStatusCodeSame(403);
    }

    public function testCreateRejectsAnInvalidPayload(): void
    {
        $auteur = $this->createCompleteProfileUser('avis-invalid-payload');
        $this->authenticateAs($auteur);

        $this->client->request(
            'POST',
            '/api/avis',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode(['cibleId' => 999999, 'note' => 42])
        );

        self::assertResponseStatusCodeSame(422);
    }

    public function testCreateSucceedsWithACompleteProfile(): void
    {
        $auteur = $this->createCompleteProfileUser('avis-create-ok');
        $cible = $this->createUser('avis-cible-create-ok');
        $this->authenticateAs($auteur);

        $this->client->request(
            'POST',
            '/api/avis',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode(['cibleId' => $cible->getId(), 'note' => 5, 'commentaire' => 'Excellent'])
        );

        self::assertResponseStatusCodeSame(201);
        $payload = json_decode($this->client->getResponse()->getContent(), true);
        self::assertSame(5, $payload['note']);
    }

    // ==================== update ====================

    public function testUpdateReturns404ForAnUnknownAvis(): void
    {
        $this->authenticateAs($this->createUser('avis-update-404'));

        $this->client->request(
            'PUT',
            '/api/avis/999999',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode(['cibleId' => 1, 'note' => 3])
        );

        self::assertResponseStatusCodeSame(404);
    }

    public function testUpdateRejectsANonAuthorUser(): void
    {
        $auteur = $this->createUser('avis-update-auteur');
        $cible = $this->createUser('avis-update-cible');
        $avis = $this->avis($auteur, $cible);
        $this->authenticateAs($this->createUser('avis-update-stranger'));

        $this->client->request(
            'PUT',
            '/api/avis/' . $avis->getId(),
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode(['cibleId' => $cible->getId(), 'note' => 1])
        );

        self::assertResponseStatusCodeSame(403);
    }

    public function testUpdateSucceedsForTheAuthor(): void
    {
        $auteur = $this->createUser('avis-update-author-ok');
        $cible = $this->createUser('avis-update-cible-ok');
        $avis = $this->avis($auteur, $cible, 3);
        $this->authenticateAs($auteur);

        $this->client->request(
            'PUT',
            '/api/avis/' . $avis->getId(),
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode(['cibleId' => $cible->getId(), 'note' => 2, 'commentaire' => 'Finalement moins bien'])
        );

        self::assertResponseIsSuccessful();
        $payload = json_decode($this->client->getResponse()->getContent(), true);
        self::assertSame(2, $payload['note']);
    }

    // ==================== delete ====================

    public function testDeleteReturns404ForAnUnknownAvis(): void
    {
        $this->authenticateAs($this->createUser('avis-delete-404'));

        $this->client->request('DELETE', '/api/avis/999999');

        self::assertResponseStatusCodeSame(404);
    }

    public function testDeleteRejectsANonAuthorUser(): void
    {
        $auteur = $this->createUser('avis-delete-auteur');
        $cible = $this->createUser('avis-delete-cible');
        $avis = $this->avis($auteur, $cible);
        $this->authenticateAs($this->createUser('avis-delete-stranger'));

        $this->client->request('DELETE', '/api/avis/' . $avis->getId());

        self::assertResponseStatusCodeSame(403);
    }

    public function testDeleteSucceedsForTheAuthor(): void
    {
        $auteur = $this->createUser('avis-delete-author-ok');
        $cible = $this->createUser('avis-delete-cible-ok');
        $avis = $this->avis($auteur, $cible);
        $avisId = $avis->getId();
        $this->authenticateAs($auteur);

        $this->client->request('DELETE', '/api/avis/' . $avisId);

        self::assertResponseStatusCodeSame(204);
        self::assertNull($this->em->getRepository(Avis::class)->find($avisId));
    }
}
