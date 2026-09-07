<?php

declare(strict_types=1);

namespace App\Tests\Functional\Admin;

use App\Entity\Avis;
use App\Entity\Conversation;
use App\Entity\Demande;
use App\Entity\Message;
use App\Entity\User;
use App\Entity\Voyage;
use App\Tests\Support\JwtAuthenticationTrait;
use App\Tests\Support\UserFactoryTrait;
use Doctrine\ORM\EntityManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Phase 4b, Lot 9 (bec-docs/docs/plan-correction/plan-correction-cobage.md) : integration
 * HTTP d'AdminModerationController - la contrainte croisee de DeleteContentDTO (banReason
 * obligatoire si banUser=true) est le seul point de validation propre a ce controleur.
 * Note : les 4 actions delete* attrapent `\Exception` generiquement (y compris
 * NotFoundHttpException), donc un contenu introuvable renvoie 400 ici, pas 404 - verifie
 * empiriquement, comportement volontaire et coherent sur tout le controleur (pas un bug
 * isole a corriger, contrairement aux constats #2/#5 deja traites dans ce lot).
 */
class AdminModerationControllerTest extends WebTestCase
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

    private function admin(string $prefix = 'admin-mod-admin'): User
    {
        $admin = $this->createUser($prefix);
        $admin->setRoles(['ROLE_ADMIN']);
        $this->em->flush();

        return $admin;
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

    private function avis(User $auteur, User $cible): Avis
    {
        $avis = new Avis();
        $avis->setAuteur($auteur);
        $avis->setCible($cible);
        $avis->setNote(3);
        $avis->setCommentaire('Correct');
        $this->em->persist($avis);
        $this->em->flush();

        return $avis;
    }

    private function message(User $expediteur, User $destinataire): Message
    {
        $conversation = new Conversation();
        $conversation->setParticipant1($expediteur);
        $conversation->setParticipant2($destinataire);
        $this->em->persist($conversation);

        $message = new Message();
        $message->setConversation($conversation);
        $message->setExpediteur($expediteur);
        $message->setDestinataire($destinataire);
        $message->setContenu('Bonjour');
        $this->em->persist($message);
        $this->em->flush();

        return $message;
    }

    private function validPayload(): array
    {
        return ['reason' => 'Contenu signale par plusieurs utilisateurs'];
    }

    // ==================== auth requise ====================

    public function testDeleteVoyageRejectsANonAdminUser(): void
    {
        $this->authenticateAs($this->createUser('admin-mod-nonadmin'));

        $this->client->request(
            'DELETE',
            '/api/admin/moderation/voyages/1',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode($this->validPayload())
        );

        self::assertResponseStatusCodeSame(403);
    }

    // ==================== contrainte croisee DeleteContentDTO ====================

    public function testDeleteVoyageRejectsBanUserWithoutBanReason(): void
    {
        $voyageur = $this->createUser('admin-mod-banwithoutreason-voyageur');
        $voyage = $this->voyage($voyageur);
        $this->authenticateAs($this->admin('admin-mod-banwithoutreason-admin'));

        $this->client->request(
            'DELETE',
            '/api/admin/moderation/voyages/' . $voyage->getId(),
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode(['reason' => 'Contenu inapproprie signale', 'banUser' => true])
        );

        self::assertResponseStatusCodeSame(422);
    }

    public function testDeleteVoyageAcceptsBanUserWithABanReason(): void
    {
        $voyageur = $this->createUser('admin-mod-banwithreason-voyageur');
        $voyage = $this->voyage($voyageur);
        $this->authenticateAs($this->admin('admin-mod-banwithreason-admin'));

        $this->client->request(
            'DELETE',
            '/api/admin/moderation/voyages/' . $voyage->getId(),
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode([
                'reason' => 'Contenu inapproprie signale',
                'banUser' => true,
                'banReason' => 'Recidive apres plusieurs avertissements',
            ])
        );

        self::assertResponseIsSuccessful();
    }

    // ==================== deleteVoyage ====================

    public function testDeleteVoyageReturnsAnErrorForAnUnknownVoyage(): void
    {
        $this->authenticateAs($this->admin('admin-mod-voyage-404'));

        $this->client->request(
            'DELETE',
            '/api/admin/moderation/voyages/999999',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode($this->validPayload())
        );

        self::assertResponseStatusCodeSame(400);
        $payload = json_decode($this->client->getResponse()->getContent(), true);
        self::assertFalse($payload['success']);
    }

    public function testDeleteVoyageSucceeds(): void
    {
        $voyageur = $this->createUser('admin-mod-voyage-ok-voyageur');
        $voyage = $this->voyage($voyageur);
        $voyageId = $voyage->getId();
        $this->authenticateAs($this->admin('admin-mod-voyage-ok-admin'));

        $this->client->request(
            'DELETE',
            '/api/admin/moderation/voyages/' . $voyageId,
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode($this->validPayload())
        );

        self::assertResponseIsSuccessful();
        self::assertNull($this->em->getRepository(Voyage::class)->find($voyageId));
    }

    // ==================== deleteDemande ====================

    public function testDeleteDemandeSucceeds(): void
    {
        $client = $this->createUser('admin-mod-demande-ok-client');
        $demande = $this->demande($client);
        $demandeId = $demande->getId();
        $this->authenticateAs($this->admin('admin-mod-demande-ok-admin'));

        $this->client->request(
            'DELETE',
            '/api/admin/moderation/demandes/' . $demandeId,
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode($this->validPayload())
        );

        self::assertResponseIsSuccessful();
        self::assertNull($this->em->getRepository(Demande::class)->find($demandeId));
    }

    // ==================== deleteAvis ====================

    public function testDeleteAvisSucceeds(): void
    {
        $auteur = $this->createUser('admin-mod-avis-ok-auteur');
        $cible = $this->createUser('admin-mod-avis-ok-cible');
        $avis = $this->avis($auteur, $cible);
        $avisId = $avis->getId();
        $this->authenticateAs($this->admin('admin-mod-avis-ok-admin'));

        $this->client->request(
            'DELETE',
            '/api/admin/moderation/avis/' . $avisId,
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode($this->validPayload())
        );

        self::assertResponseIsSuccessful();
        self::assertNull($this->em->getRepository(Avis::class)->find($avisId));
    }

    // ==================== deleteMessage ====================

    public function testDeleteMessageSucceeds(): void
    {
        $expediteur = $this->createUser('admin-mod-message-ok-exp');
        $destinataire = $this->createUser('admin-mod-message-ok-dest');
        $message = $this->message($expediteur, $destinataire);
        $messageId = $message->getId();
        $this->authenticateAs($this->admin('admin-mod-message-ok-admin'));

        $this->client->request(
            'DELETE',
            '/api/admin/moderation/messages/' . $messageId,
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode($this->validPayload())
        );

        self::assertResponseIsSuccessful();
        self::assertNull($this->em->getRepository(Message::class)->find($messageId));
    }

    // ==================== deleteAllUserContent ====================

    public function testDeleteAllUserContentReturns404ForAnUnknownUser(): void
    {
        $this->authenticateAs($this->admin('admin-mod-deleteall-404'));

        $this->client->request(
            'DELETE',
            '/api/admin/moderation/users/999999/delete-all-content',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode(['reason' => 'Suppression RGPD'])
        );

        self::assertResponseStatusCodeSame(404);
    }

    public function testDeleteAllUserContentRemovesAllContentTypes(): void
    {
        $target = $this->createUser('admin-mod-deleteall-ok');
        $this->voyage($target);
        $this->demande($target);
        $admin = $this->admin('admin-mod-deleteall-ok-admin');
        $targetId = $target->getId();

        // Vide l'identity map : $target vient d'etre persiste dans ce process, ses
        // collections voyages/demandes (mappedBy, cote inverse) restent la simple
        // ArrayCollection vide du constructeur tant que l'entite n'a pas ete rechargee
        // depuis la DB - sans ce clear(), le controleur recupererait par identity map
        // ce meme objet en memoire et ne verrait aucun contenu, contrairement a une
        // vraie requete HTTP en production (nouvelle requete = nouvel EntityManager).
        $this->em->clear();

        $this->authenticateAs($admin);

        $this->client->request(
            'DELETE',
            '/api/admin/moderation/users/' . $targetId . '/delete-all-content',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode(['reason' => 'Suppression en masse demandee'])
        );

        self::assertResponseIsSuccessful();
        $payload = json_decode($this->client->getResponse()->getContent(), true);
        self::assertSame(1, $payload['stats']['voyages']);
        self::assertSame(1, $payload['stats']['demandes']);
    }

    // ==================== moderationStats ====================

    public function testModerationStatsSucceeds(): void
    {
        $this->authenticateAs($this->admin('admin-mod-stats'));

        $this->client->request('GET', '/api/admin/moderation/stats');

        self::assertResponseIsSuccessful();
    }
}
