<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Service\RefreshTokenManager;
use App\Tests\Support\UserFactoryTrait;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\BrowserKit\Cookie;

/**
 * Phase 4 du plan de correction (bec-docs/docs/plan-correction/plan-correction-cobage.md) :
 * le refresh token est gere par TokenController (pas AuthController, malgre le brief
 * d'origine - voir AuthControllerTest). La joignabilite sans access token JWT est deja
 * couverte par PublicAuthRoutesTest ; ce fichier couvre le flux complet (rotation, rejet).
 */
class TokenControllerTest extends WebTestCase
{
    use UserFactoryTrait;

    private KernelBrowser $client;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        self::ensureKernelShutdown();
        $this->client = static::createClient();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
    }

    private function issueRefreshToken(): string
    {
        $user = $this->createUser('refresh-user');
        $manager = static::getContainer()->get(RefreshTokenManager::class);

        return $manager->createAndSaveRefreshToken($user);
    }

    private function setRefreshCookie(string $rawToken): void
    {
        $this->client->getCookieJar()->set(new Cookie('bagage_refresh_token', $rawToken, null, '/', 'localhost', false, false));
    }

    public function testRefreshWithValidTokenIssuesNewCookiesAndRotates(): void
    {
        $rawToken = $this->issueRefreshToken();
        $this->setRefreshCookie($rawToken);

        $this->client->request('POST', '/api/token/refresh');

        self::assertResponseStatusCodeSame(204);
        self::assertResponseHasCookie('bagage_token', '/', 'localhost');
        self::assertResponseHasCookie('bagage_refresh_token', '/', 'localhost');

        $newRefreshCookie = null;
        foreach ($this->client->getResponse()->headers->getCookies() as $cookie) {
            if ($cookie->getName() === 'bagage_refresh_token') {
                $newRefreshCookie = $cookie->getValue();
            }
        }
        self::assertNotSame($rawToken, $newRefreshCookie, 'le refresh token doit tourner (rotation) a chaque usage');
    }

    public function testRefreshRejectsAnAlreadyUsedToken(): void
    {
        $rawToken = $this->issueRefreshToken();
        $this->setRefreshCookie($rawToken);

        // Premier usage : consomme le token (invalidation + rotation cote serveur)
        $this->client->request('POST', '/api/token/refresh');
        self::assertResponseStatusCodeSame(204);

        // Rejouer le meme cookie (le client n'a pas recupere le nouveau) doit echouer
        $this->setRefreshCookie($rawToken);
        $this->client->request('POST', '/api/token/refresh');

        self::assertResponseStatusCodeSame(401, 'un refresh token deja consomme ne doit jamais pouvoir etre rejoue');
    }

    public function testRefreshRejectsAnInvalidToken(): void
    {
        $this->setRefreshCookie('ce-token-ne-correspond-a-rien');

        $this->client->request('POST', '/api/token/refresh');

        self::assertResponseStatusCodeSame(401);
    }

    public function testMercureRefreshRequiresAuthentication(): void
    {
        $this->client->request('POST', '/api/token/mercure/refresh');

        // #[IsGranted('ROLE_USER')] rejette avant meme d'atteindre le corps du controleur -
        // le firewall JWT renvoie 403, pas 401, pour un utilisateur anonyme face a un
        // IsGranted non satisfait sur ce projet. La verification manuelle "$user === null"
        // qui existait dans le corps du controleur (mort code, jamais atteignable) a ete
        // retiree (cf. plan-correction-cobage.md, Phase 13/Lot B4).
        self::assertResponseStatusCodeSame(403);
    }
}
