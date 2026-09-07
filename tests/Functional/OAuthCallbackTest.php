<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\User;
use App\Service\OAuth\FacebookAuthService;
use App\Service\OAuth\GoogleAuthService;
use App\Tests\Support\UserFactoryTrait;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Phase 4 du plan de correction (bec-docs/docs/plan-correction/plan-correction-cobage.md) :
 * callback OAuth Google/Facebook, provider mocke (AuthController::googleCallback()/
 * facebookCallback() - duplication assumee, la factorisation est Phase 6, pas ici).
 *
 * GoogleAuthService/FacebookAuthService remplaces dans le conteneur de test par un
 * double PHPUnit (classes concretes non-final, pas d'interface dediee) - authenticate()
 * seul est mocke, l'etat CSRF est un vrai state genere par le endpoint /auth/google (non
 * mocke) puis reutilise pour le callback, comme un navigateur reel le ferait.
 */
class OAuthCallbackTest extends WebTestCase
{
    use UserFactoryTrait;

    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private string $frontendUrl;

    protected function setUp(): void
    {
        self::ensureKernelShutdown();
        $this->client = static::createClient();
        // Chaque requete du client reboote le noyau par defaut, ce qui reconstruirait un
        // conteneur neuf et perdrait le remplacement de service fait via set() avant la
        // requete suivante (le callback) - desactive ici puisque ce test enchaine
        // volontairement deux requetes (obtenir un state, puis l'utiliser) sur le meme
        // conteneur/mock.
        $this->client->disableReboot();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->frontendUrl = static::getContainer()->getParameter('app.frontend_url');
    }

    private function realState(string $provider): string
    {
        $this->client->request('GET', '/api/auth/' . $provider);
        self::assertResponseIsSuccessful();
        $payload = json_decode($this->client->getResponse()->getContent(), true);

        return $payload['state'];
    }

    /**
     * AuthController construit GoogleAuthService/FacebookAuthService des le premier
     * routage vers AuthController (constructeur eager, pas de lazy loading) - le mock
     * doit donc etre enregistre dans le conteneur AVANT toute requete de ce test, sinon
     * TestContainer::set() refuse de remplacer un service deja initialise. getAuthorizationUrl()/
     * getState() sont donc mockes ici aussi (pas seulement authenticate()), pour que
     * l'appel reel a /api/auth/{provider} qui suit produise un state coherent avec le
     * mock utilise ensuite par le callback.
     */
    private function stateForMockedProvider(string $provider, object $mock): string
    {
        $mock->method('getAuthorizationUrl')->willReturn('https://provider.example.test/authorize');
        $mock->method('getState')->willReturn('mocked-state-' . uniqid());
        static::getContainer()->set($provider === 'google' ? GoogleAuthService::class : FacebookAuthService::class, $mock);

        return $this->realState($provider);
    }

    /**
     * Le reboot du noyau etant desactive, Symfony reinitialise tout de meme les services
     * "kernel.reset" (dont l'EntityManager) entre les deux requetes de ces tests - une
     * entite User capturee avant la premiere requete redevient inconnue de l'UnitOfWork
     * courant au moment ou authenticate() est appele pendant la seconde (Doctrine refuse
     * alors la cascade persist du RefreshToken associe). Rechercher l'utilisateur par
     * email au moment de l'appel, plutot que de retourner une reference capturee plus
     * tot, evite ce probleme.
     */
    private function refetchUser(string $email): User
    {
        return static::getContainer()->get(EntityManagerInterface::class)
            ->getRepository(User::class)
            ->findOneBy(['email' => $email]);
    }

    public function testGoogleCallbackRedirectsWithErrorOnCsrfMismatch(): void
    {
        $this->realState('google');

        $this->client->request('GET', '/api/auth/google/callback?code=whatever&state=state-invente');

        self::assertResponseRedirects($this->frontendUrl . '/auth/login?error=csrf_failed');
    }

    public function testGoogleCallbackRedirectsWithErrorWhenCodeMissing(): void
    {
        $state = $this->realState('google');

        $this->client->request('GET', '/api/auth/google/callback?state=' . $state);

        self::assertResponseRedirects($this->frontendUrl . '/auth/login?error=no_code');
    }

    public function testGoogleCallbackSucceedsAndAttachesAuthCookies(): void
    {
        $email = $this->createCompleteProfileUser('oauth-google-user')->getEmail();
        $googleAuthService = $this->createMock(GoogleAuthService::class);
        $googleAuthService->method('authenticate')->with('valid-code')
            ->willReturnCallback(fn () => $this->refetchUser($email));
        $state = $this->stateForMockedProvider('google', $googleAuthService);

        $this->client->request('GET', '/api/auth/google/callback?code=valid-code&state=' . $state);

        self::assertResponseRedirects($this->frontendUrl . '/auth/oauth-callback');
        self::assertResponseHasCookie('bagage_token', '/', 'localhost');
        self::assertResponseHasCookie('bagage_refresh_token', '/', 'localhost');
    }

    public function testGoogleCallbackRedirectsWithErrorWhenAuthenticateThrows(): void
    {
        $googleAuthService = $this->createMock(GoogleAuthService::class);
        $googleAuthService->method('authenticate')->willThrowException(new \RuntimeException('compte Google refuse'));
        $state = $this->stateForMockedProvider('google', $googleAuthService);

        $this->client->request('GET', '/api/auth/google/callback?code=bad-code&state=' . $state);

        self::assertResponseRedirects($this->frontendUrl . '/auth/login?error=' . urlencode('compte Google refuse'));
    }

    public function testFacebookCallbackSucceedsAndAttachesAuthCookies(): void
    {
        $email = $this->createCompleteProfileUser('oauth-facebook-user')->getEmail();
        $facebookAuthService = $this->createMock(FacebookAuthService::class);
        $facebookAuthService->method('authenticate')->with('valid-code')
            ->willReturnCallback(fn () => $this->refetchUser($email));
        $state = $this->stateForMockedProvider('facebook', $facebookAuthService);

        $this->client->request('GET', '/api/auth/facebook/callback?code=valid-code&state=' . $state);

        self::assertResponseRedirects($this->frontendUrl . '/auth/oauth-callback');
        self::assertResponseHasCookie('bagage_token', '/', 'localhost');
    }

    public function testFacebookCallbackRedirectsWithErrorOnCsrfMismatch(): void
    {
        $this->realState('facebook');

        $this->client->request('GET', '/api/auth/facebook/callback?code=whatever&state=state-invente');

        self::assertResponseRedirects($this->frontendUrl . '/auth/login?error=csrf_failed');
    }
}
