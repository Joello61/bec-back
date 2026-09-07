<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\User;
use App\Tests\Support\UserFactoryTrait;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Phase 4 du plan de correction (bec-docs/docs/plan-correction/plan-correction-cobage.md) :
 * flux d'authentification complet - login (json_login + JWTListener), register,
 * verify-email, forgot/reset-password, change-password, logout.
 *
 * Ecart constate avec le brief d'origine : le refresh token est gere par
 * TokenController, pas AuthController - voir TokenControllerTest.
 */
class AuthControllerTest extends WebTestCase
{
    use UserFactoryTrait;

    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private UserPasswordHasherInterface $passwordHasher;

    protected function setUp(): void
    {
        self::ensureKernelShutdown();
        $this->client = static::createClient();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->passwordHasher = static::getContainer()->get(UserPasswordHasherInterface::class);

        // Le pool cache.rate_limiter (register/forgot-password/login) est backe par le
        // filesystem par defaut, contrairement a la base de test (remise a zero par DAMA
        // a chaque test) - sans ce clear, l'etat s'accumule entre deux executions distinctes
        // de `php bin/phpunit` et les tests ci-dessous sur register()/forgot-password()
        // deviennent intermittents apres quelques executions rapprochees.
        static::getContainer()->get('cache.rate_limiter')->clear();
    }

    private function createLocalUser(string $emailPrefix, string $plainPassword, bool $emailVerifie): User
    {
        $user = $this->createUser($emailPrefix);
        $user->setAuthProvider('local');
        $user->setEmailVerifie($emailVerifie);
        $user->setPassword($this->passwordHasher->hashPassword($user, $plainPassword));
        $this->em->flush();

        return $user;
    }

    private function login(string $email, string $password): void
    {
        $this->client->request(
            'POST',
            '/api/login',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode(['email' => $email, 'password' => $password])
        );
    }

    public function testLoginDeniedWhenLocalEmailNotVerified(): void
    {
        $user = $this->createLocalUser('login-unverified', 'Password123', emailVerifie: false);

        $this->login($user->getEmail(), 'Password123');

        self::assertResponseStatusCodeSame(401);
        $payload = json_decode($this->client->getResponse()->getContent(), true);
        self::assertStringContainsString('vérifier votre adresse email', $payload['message']);
    }

    public function testLoginGrantedWhenLocalEmailVerified(): void
    {
        $user = $this->createLocalUser('login-verified', 'Password123', emailVerifie: true);

        $this->login($user->getEmail(), 'Password123');

        self::assertResponseIsSuccessful();
        $payload = json_decode($this->client->getResponse()->getContent(), true);
        self::assertTrue($payload['success']);
        self::assertSame($user->getEmail(), $payload['user']['email']);
        self::assertResponseHasCookie('bagage_token', '/', 'localhost');
        self::assertResponseHasCookie('bagage_refresh_token', '/', 'localhost');
    }

    public function testLoginDeniedWithWrongPassword(): void
    {
        $user = $this->createLocalUser('login-wrongpass', 'Password123', emailVerifie: true);

        $this->login($user->getEmail(), 'CompletelyWrongPassword1');

        self::assertResponseStatusCodeSame(401);
    }

    public function testRegisterCreatesAutoVerifiedAccountAndNeverReturnsAJwt(): void
    {
        // EMAIL_VERIFICATION_ENABLED=false dans cet environnement (.env) : le comportement
        // reel actuel auto-verifie le compte a l'inscription - voir AuthService::register().
        $email = 'register-' . uniqid() . '@example.test';

        $this->client->request(
            'POST',
            '/api/register',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode([
                'nom' => 'Test',
                'prenom' => 'Register',
                'email' => $email,
                'password' => 'Password123',
            ])
        );

        self::assertResponseStatusCodeSame(201);
        self::assertResponseNotHasCookie('bagage_token', '/', 'localhost', 'register() ne doit jamais emettre de JWT, meme quand le compte est auto-verifie');

        $created = $this->em->getRepository(User::class)->findOneBy(['email' => $email]);
        self::assertNotNull($created);
        self::assertTrue($created->isEmailVerifie());
    }

    public function testRegisterRejectsDuplicateEmail(): void
    {
        $existing = $this->createUser('register-dup');

        $this->client->request(
            'POST',
            '/api/register',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode([
                'nom' => 'Test',
                'prenom' => 'Dup',
                'email' => $existing->getEmail(),
                'password' => 'Password123',
            ])
        );

        self::assertResponseStatusCodeSame(400);
    }

    public function testVerifyEmailRejectsInvalidCode(): void
    {
        $user = $this->createLocalUser('verify-badcode', 'Password123', emailVerifie: false);

        $this->client->request(
            'POST',
            '/api/verify-email',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode(['email' => $user->getEmail(), 'code' => '000000'])
        );

        self::assertResponseStatusCodeSame(400);
    }

    public function testChangePasswordRejectsWrongCurrentPassword(): void
    {
        $user = $this->createLocalUser('changepwd-wrong', 'Password123', emailVerifie: true);
        $this->authenticateWithFreshLogin($user, 'Password123');

        $this->client->request(
            'POST',
            '/api/change-password',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode(['currentPassword' => 'NotTheRightOne1', 'newPassword' => 'NewPassword456'])
        );

        self::assertResponseStatusCodeSame(400);
    }

    public function testChangePasswordSucceedsAndOldPasswordNoLongerWorks(): void
    {
        $user = $this->createLocalUser('changepwd-ok', 'Password123', emailVerifie: true);
        $this->authenticateWithFreshLogin($user, 'Password123');

        $this->client->request(
            'POST',
            '/api/change-password',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode(['currentPassword' => 'Password123', 'newPassword' => 'NewPassword456'])
        );

        self::assertResponseIsSuccessful();

        $this->login($user->getEmail(), 'Password123');
        self::assertResponseStatusCodeSame(401, 'l\'ancien mot de passe ne doit plus fonctionner apres le changement');
    }

    public function testForgotPasswordDoesNotRevealWhetherEmailExists(): void
    {
        $this->client->request(
            'POST',
            '/api/forgot-password',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode(['email' => 'inconnu-' . uniqid() . '@example.test'])
        );

        self::assertResponseIsSuccessful();
        $payload = json_decode($this->client->getResponse()->getContent(), true);
        self::assertTrue($payload['success']);
    }

    public function testResendVerificationForExistingUserSucceeds(): void
    {
        $user = $this->createLocalUser('resend-verification', 'Password123', emailVerifie: false);

        $this->client->request(
            'POST',
            '/api/resend-verification',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode(['email' => $user->getEmail(), 'type' => 'email'])
        );

        self::assertResponseIsSuccessful();
        $payload = json_decode($this->client->getResponse()->getContent(), true);
        self::assertTrue($payload['success']);
    }

    public function testResetPasswordRejectsInvalidToken(): void
    {
        $this->client->request(
            'POST',
            '/api/reset-password',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode(['token' => 'ce-token-n-existe-pas', 'newPassword' => 'NewPassword456'])
        );

        self::assertResponseStatusCodeSame(400);
    }

    public function testLogoutClearsAuthCookies(): void
    {
        $user = $this->createLocalUser('logout-cookies', 'Password123', emailVerifie: true);
        $this->authenticateWithFreshLogin($user, 'Password123');

        $this->client->request('POST', '/api/logout');

        self::assertResponseIsSuccessful();
        $cookieNames = array_map(
            static fn ($cookie) => $cookie->getName(),
            $this->client->getResponse()->headers->getCookies()
        );
        self::assertContains('bagage_token', $cookieNames);

        foreach ($this->client->getResponse()->headers->getCookies() as $cookie) {
            if ($cookie->getName() === 'bagage_token') {
                self::assertTrue($cookie->getExpiresTime() < time(), 'le cookie JWT doit etre expire immediatement a la deconnexion');
            }
        }
    }

    private function authenticateWithFreshLogin(User $user, string $plainPassword): void
    {
        $this->login($user->getEmail(), $plainPassword);
        self::assertResponseIsSuccessful();
    }
}
