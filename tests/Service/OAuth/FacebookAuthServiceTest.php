<?php

declare(strict_types=1);

namespace App\Tests\Service\OAuth;

use App\Entity\User;
use App\Repository\UserRepository;
use App\Service\OAuth\FacebookAuthService;
use App\Service\SettingsService;
use Doctrine\ORM\EntityManagerInterface;
use League\OAuth2\Client\Provider\Facebook;
use League\OAuth2\Client\Provider\FacebookUser;
use League\OAuth2\Client\Token\AccessToken;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

/**
 * Phase 4b, Lot 2 (bec-docs/docs/plan-correction/plan-correction-cobage.md) : symetrique de
 * GoogleAuthServiceTest, avec les particularites propres a Facebook (email obligatoire,
 * parsing du nom complet en prenom/nom, cf. la difference deja notee dans l'exploration).
 */
class FacebookAuthServiceTest extends TestCase
{
    private EntityManagerInterface&\PHPUnit\Framework\MockObject\MockObject $em;
    private UserRepository&\PHPUnit\Framework\MockObject\MockObject $userRepository;
    private SettingsService&\PHPUnit\Framework\MockObject\MockObject $settingsService;
    private Facebook&\PHPUnit\Framework\MockObject\MockObject $provider;
    private FacebookAuthService $service;

    protected function setUp(): void
    {
        $this->em = $this->createMock(EntityManagerInterface::class);
        $this->userRepository = $this->createMock(UserRepository::class);
        $this->settingsService = $this->createMock(SettingsService::class);
        $this->provider = $this->createMock(Facebook::class);

        $this->service = new FacebookAuthService(
            $this->em,
            $this->userRepository,
            new NullLogger(),
            $this->settingsService,
            $this->provider,
        );

        $this->provider->method('getAccessToken')->willReturn($this->createMock(AccessToken::class));
    }

    private function facebookUser(string $id, ?string $email, string $name = 'Jean Dupont', ?string $avatar = null): FacebookUser&\PHPUnit\Framework\MockObject\MockObject
    {
        $facebookUser = $this->createMock(FacebookUser::class);
        $facebookUser->method('getId')->willReturn($id);
        $facebookUser->method('getEmail')->willReturn($email);
        $facebookUser->method('getName')->willReturn($name);
        $facebookUser->method('getPictureUrl')->willReturn($avatar);

        return $facebookUser;
    }

    public function testGetAuthorizationUrlDelegatesToTheProvider(): void
    {
        $this->provider->method('getAuthorizationUrl')->with(['scope' => ['email', 'public_profile']])->willReturn('https://facebook.com/authorize?x=1');

        self::assertSame('https://facebook.com/authorize?x=1', $this->service->getAuthorizationUrl());
    }

    public function testGetStateDelegatesToTheProvider(): void
    {
        $this->provider->method('getState')->willReturn('a-csrf-state');

        self::assertSame('a-csrf-state', $this->service->getState());
    }

    public function testAuthenticateRejectsWhenFacebookProvidesNoEmail(): void
    {
        $this->provider->method('getResourceOwner')->willReturn($this->facebookUser('fb-id-0', null));

        try {
            $this->service->authenticate('a-code');
            self::fail('une BadRequestHttpException etait attendue');
        } catch (BadRequestHttpException $e) {
            // le catch generique de authenticate() remplace le message specifique par un
            // message generique - comportement reel actuel, verifie explicitement.
            self::assertSame('Erreur lors de l\'authentification Facebook', $e->getMessage());
        }
    }

    public function testAuthenticateParsesFullNameIntoFirstAndLastName(): void
    {
        $this->provider->method('getResourceOwner')->willReturn($this->facebookUser('fb-id-1', 'inconnu@example.test', 'Alice Bernadette Martin'));
        $this->userRepository->method('findOneBy')->willReturn(null);
        $this->userRepository->method('findByEmail')->willReturn(null);

        $result = $this->service->authenticate('a-code');

        self::assertSame('Alice', $result->getPrenom());
        self::assertSame('Bernadette Martin', $result->getNom(), 'explode(...,2) : tout ce qui suit le premier espace forme le nom');
    }

    public function testAuthenticateFallsBackToDefaultNamesWhenNameHasNoSpace(): void
    {
        $this->provider->method('getResourceOwner')->willReturn($this->facebookUser('fb-id-2', 'monoprenom@example.test', 'Cher'));
        $this->userRepository->method('findOneBy')->willReturn(null);
        $this->userRepository->method('findByEmail')->willReturn(null);

        $result = $this->service->authenticate('a-code');

        self::assertSame('Cher', $result->getPrenom());
        self::assertSame('Nom', $result->getNom());
    }

    public function testAuthenticateReturnsTheExistingUserMatchedByFacebookId(): void
    {
        $existing = new User();
        $existing->setEmail('deja-lie@example.test');
        $existing->setEmailVerifie(true);
        $existing->setPrenom('Jean');
        $existing->setNom('Dupont');
        $this->provider->method('getResourceOwner')->willReturn($this->facebookUser('fb-id-3', 'deja-lie@example.test'));
        $this->userRepository->method('findOneBy')->with(['facebookId' => 'fb-id-3'])->willReturn($existing);
        $this->em->expects(self::never())->method('persist');

        self::assertSame($existing, $this->service->authenticate('a-code'));
    }

    public function testAuthenticateSkipsFlushWhenNothingChanged(): void
    {
        $existing = new User();
        $existing->setEmail('meme-email@example.test');
        $existing->setEmailVerifie(true);
        $existing->setPrenom('Jean');
        $existing->setNom('Dupont');
        $existing->setPhoto('deja-une-photo.jpg');
        $this->provider->method('getResourceOwner')->willReturn($this->facebookUser('fb-id-4', 'meme-email@example.test', 'Jean Dupont', 'deja-une-photo.jpg'));
        $this->userRepository->method('findOneBy')->willReturn($existing);
        $this->em->expects(self::never())->method('flush');

        $this->service->authenticate('a-code');
    }

    public function testAuthenticateLinksFacebookToAnExistingAccountFoundByEmail(): void
    {
        $existing = new User();
        $existing->setEmail('deja-inscrit@example.test');
        $this->provider->method('getResourceOwner')->willReturn($this->facebookUser('fb-id-5', 'deja-inscrit@example.test'));
        $this->userRepository->method('findOneBy')->willReturn(null);
        $this->userRepository->method('findByEmail')->with('deja-inscrit@example.test')->willReturn($existing);
        $this->em->expects(self::once())->method('flush');
        $this->em->expects(self::never())->method('persist');

        $result = $this->service->authenticate('a-code');

        self::assertSame('fb-id-5', $result->getFacebookId());
        self::assertSame('facebook', $result->getAuthProvider());
        self::assertTrue($result->isEmailVerifie());
    }

    public function testAuthenticateCreatesANewUserAndDefaultSettings(): void
    {
        $this->provider->method('getResourceOwner')->willReturn($this->facebookUser('fb-id-6', 'inconnu@example.test', 'Alice Martin', 'https://avatar.example/a.jpg'));
        $this->userRepository->method('findOneBy')->willReturn(null);
        $this->userRepository->method('findByEmail')->willReturn(null);
        $this->em->expects(self::once())->method('persist')->with(self::isInstanceOf(User::class));
        $this->em->expects(self::once())->method('flush');
        $this->settingsService->expects(self::once())->method('createDefaultSettings');

        $result = $this->service->authenticate('a-code');

        self::assertSame('inconnu@example.test', $result->getEmail());
        self::assertSame('fb-id-6', $result->getFacebookId());
        self::assertSame('facebook', $result->getAuthProvider());
        self::assertTrue($result->isEmailVerifie());
        self::assertNull($result->getPassword());
        self::assertSame(['ROLE_USER'], $result->getRoles());
    }

    public function testAuthenticateWrapsATokenExchangeFailureInAGenericException(): void
    {
        $this->provider = $this->createMock(Facebook::class);
        $this->provider->method('getAccessToken')->willThrowException(new \Exception('invalid_grant'));
        $service = new FacebookAuthService($this->em, $this->userRepository, new NullLogger(), $this->settingsService, $this->provider);

        try {
            $service->authenticate('bad-code');
            self::fail('une BadRequestHttpException etait attendue');
        } catch (BadRequestHttpException $e) {
            self::assertStringNotContainsString('invalid_grant', $e->getMessage());
        }
    }
}
