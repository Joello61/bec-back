<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\Conversation;
use App\Entity\Message;
use App\Entity\User;
use App\Tests\Support\JwtAuthenticationTrait;
use App\Tests\Support\UserFactoryTrait;
use Doctrine\ORM\EntityManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\BrowserKit\Cookie;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Phase 5 (bec-docs/docs/plan-correction/plan-correction-cobage.md) : DELETE /api/users/me,
 * seul flux self-service de suppression de compte (le flux admin equivalent est couvert par
 * tests/Functional/Admin/AdminUserControllerTest.php). Verifie le garde-fou mot de passe
 * (meme logique que le changement de mot de passe), le cas OAuth pur, l'interdiction pour un
 * compte admin, l'exclusion des recherches/annuaire apres suppression, la conservation des
 * messages visibles par des tiers, et l'invalidation immediate du JWT deja emis.
 */
class UserAccountDeletionTest extends WebTestCase
{
    use UserFactoryTrait;
    use JwtAuthenticationTrait;

    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private JWTTokenManagerInterface $jwtManager;
    private UserPasswordHasherInterface $passwordHasher;

    protected function setUp(): void
    {
        self::ensureKernelShutdown();
        $this->client = static::createClient();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->jwtManager = static::getContainer()->get('lexik_jwt_authentication.jwt_manager');
        $this->passwordHasher = static::getContainer()->get(UserPasswordHasherInterface::class);
    }

    private function userWithPassword(string $prefix, string $plainPassword): User
    {
        $user = $this->createUser($prefix);
        $user->setPassword($this->passwordHasher->hashPassword($user, $plainPassword));
        $this->em->flush();

        return $user;
    }

    private function deleteMyAccount(?string $currentPassword): void
    {
        $body = $currentPassword !== null ? ['currentPassword' => $currentPassword] : [];

        $this->client->request(
            'DELETE',
            '/api/users/me',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode($body)
        );
    }

    // ==================== garde-fous ====================

    public function testDeletingWithTheCorrectPasswordSucceeds(): void
    {
        $user = $this->userWithPassword('delete-me-ok', 'CorrectPass123');
        $userId = $user->getId();
        $this->authenticateAs($user);

        $this->deleteMyAccount('CorrectPass123');

        self::assertResponseIsSuccessful();

        $this->em->clear();
        $deleted = $this->em->getRepository(User::class)->find($userId);
        self::assertNotNull($deleted, 'Le compte ne doit pas etre physiquement supprime');
        self::assertNotNull($deleted->getDeletedAt());
        self::assertNull($deleted->getPassword());
    }

    public function testDeletingWithAnIncorrectPasswordIsRejected(): void
    {
        $user = $this->userWithPassword('delete-me-wrong', 'CorrectPass123');
        $userId = $user->getId();
        $this->authenticateAs($user);

        $this->deleteMyAccount('WrongPassword');

        self::assertResponseStatusCodeSame(400);

        $this->em->clear();
        $stillActive = $this->em->getRepository(User::class)->find($userId);
        self::assertNull($stillActive->getDeletedAt());
    }

    public function testDeletingWithoutAPasswordOnALocalAccountIsRejected(): void
    {
        $user = $this->userWithPassword('delete-me-nopass', 'CorrectPass123');
        $this->authenticateAs($user);

        $this->deleteMyAccount(null);

        self::assertResponseStatusCodeSame(400);
    }

    public function testDeletingAnOAuthOnlyAccountSucceedsWithoutAPassword(): void
    {
        $user = $this->createUser('delete-me-oauth');
        $user->setPassword(null);
        $user->setAuthProvider('google');
        $user->setGoogleId('google-test-id');
        $this->em->flush();
        $this->authenticateAs($user);

        $this->deleteMyAccount(null);

        self::assertResponseIsSuccessful();
    }

    public function testDeletingAnAdminAccountIsRejected(): void
    {
        $admin = $this->userWithPassword('delete-me-admin', 'CorrectPass123');
        $admin->setRoles(['ROLE_ADMIN']);
        $this->em->flush();
        $this->authenticateAs($admin);

        $this->deleteMyAccount('CorrectPass123');

        self::assertResponseStatusCodeSame(400);

        $this->em->clear();
        $stillActive = $this->em->getRepository(User::class)->find($admin->getId());
        self::assertNull($stillActive->getDeletedAt());
    }

    // ==================== effets de la suppression ====================

    public function testDeletedAccountDisappearsFromListSearchAndProfile(): void
    {
        $target = $this->userWithPassword('delete-me-hidden', 'CorrectPass123');
        $targetId = $target->getId();
        $observer = $this->createUser('delete-me-observer');

        $this->authenticateAs($target);
        $this->deleteMyAccount('CorrectPass123');
        self::assertResponseIsSuccessful();

        $this->authenticateAs($observer);

        $this->client->request('GET', '/api/users/' . $targetId);
        self::assertResponseStatusCodeSame(404);

        $this->client->request('GET', '/api/users?limit=50');
        $listData = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertNotContains($targetId, array_column($listData['data'], 'id'));

        $this->client->request('GET', '/api/users/search?q=delete-me-hidden');
        $searchData = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertNotContains($targetId, array_column($searchData, 'id'));
    }

    public function testMessageFromADeletedAccountRemainsVisibleToTheOtherParty(): void
    {
        $target = $this->userWithPassword('delete-me-msg', 'CorrectPass123');
        $tiers = $this->createUser('delete-me-msg-tiers');

        $conversation = new Conversation();
        $conversation->setParticipant1($target);
        $conversation->setParticipant2($tiers);
        $this->em->persist($conversation);

        $message = new Message();
        $message->setConversation($conversation);
        $message->setExpediteur($target);
        $message->setDestinataire($tiers);
        $message->setContenu('Message qui doit survivre a la suppression du compte');
        $this->em->persist($message);
        $this->em->flush();
        $messageId = $message->getId();

        $this->authenticateAs($target);
        $this->deleteMyAccount('CorrectPass123');
        self::assertResponseIsSuccessful();

        $this->em->clear();
        $persistedMessage = $this->em->getRepository(Message::class)->find($messageId);
        self::assertNotNull($persistedMessage);
        self::assertSame('Message qui doit survivre a la suppression du compte', $persistedMessage->getContenu());
    }

    public function testAJwtIssuedBeforeDeletionIsRejectedImmediatelyAfterwards(): void
    {
        $user = $this->userWithPassword('delete-me-jwt', 'CorrectPass123');
        $preDeletionToken = $this->jwtManager->create($user);
        $this->client->getCookieJar()->set(new Cookie('bagage_token', $preDeletionToken, null, '/', 'localhost', false, false));

        $this->deleteMyAccount('CorrectPass123');
        self::assertResponseIsSuccessful();

        // Reinjecte le meme JWT (emis avant suppression) : doit echouer car l'email associe
        // au token n'existe plus (anonymise), independamment du nettoyage des cookies deja
        // effectue par la reponse de suppression elle-meme.
        $this->client->getCookieJar()->set(new Cookie('bagage_token', $preDeletionToken, null, '/', 'localhost', false, false));
        $this->client->request('GET', '/api/users/me/dashboard');

        self::assertResponseStatusCodeSame(401);
    }
}
