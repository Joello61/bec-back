<?php

declare(strict_types=1);

namespace App\Tests\Service\OAuth;

use App\Entity\User;
use App\Repository\UserRepository;
use App\Service\OAuth\GoogleAuthService;
use App\Service\SettingsService;
use Doctrine\ORM\EntityManagerInterface;
use League\OAuth2\Client\Provider\Google;
use League\OAuth2\Client\Provider\GoogleUser;
use League\OAuth2\Client\Token\AccessToken;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

/**
 * Phase 4b, Lot 2 (bec-docs/docs/plan-correction/plan-correction-cobage.md) : les 3 branches
 * de resolution d'identite (par googleId, par email a lier, creation) sont la logique la
 * plus sensible du service - securite de liaison de compte. Rendu testable par le refactor
 * d'injection du provider (commit precedent de ce meme lot).
 */
class GoogleAuthServiceTest extends TestCase
{
    private EntityManagerInterface&\PHPUnit\Framework\MockObject\MockObject $em;
    private UserRepository&\PHPUnit\Framework\MockObject\MockObject $userRepository;
    private SettingsService&\PHPUnit\Framework\MockObject\MockObject $settingsService;
    private Google&\PHPUnit\Framework\MockObject\MockObject $provider;
    private GoogleAuthService $service;

    protected function setUp(): void
    {
        $this->em = $this->createMock(EntityManagerInterface::class);
        $this->userRepository = $this->createMock(UserRepository::class);
        $this->settingsService = $this->createMock(SettingsService::class);
        $this->provider = $this->createMock(Google::class);

        $this->service = new GoogleAuthService(
            $this->em,
            $this->userRepository,
            new NullLogger(),
            $this->settingsService,
            $this->provider,
        );

        $this->provider->method('getAccessToken')->willReturn($this->createMock(AccessToken::class));
    }

    private function googleUser(string $id, string $email, ?string $firstName = 'Jean', ?string $lastName = 'Dupont', ?string $avatar = null): GoogleUser&\PHPUnit\Framework\MockObject\MockObject
    {
        $googleUser = $this->createMock(GoogleUser::class);
        $googleUser->method('getId')->willReturn($id);
        $googleUser->method('getEmail')->willReturn($email);
        $googleUser->method('getFirstName')->willReturn($firstName);
        $googleUser->method('getLastName')->willReturn($lastName);
        $googleUser->method('getAvatar')->willReturn($avatar);

        return $googleUser;
    }

    public function testGetAuthorizationUrlDelegatesToTheProvider(): void
    {
        $this->provider->method('getAuthorizationUrl')->with(['scope' => ['email', 'profile']])->willReturn('https://accounts.google.com/authorize?x=1');

        self::assertSame('https://accounts.google.com/authorize?x=1', $this->service->getAuthorizationUrl());
    }

    public function testGetStateDelegatesToTheProvider(): void
    {
        $this->provider->method('getState')->willReturn('a-csrf-state');

        self::assertSame('a-csrf-state', $this->service->getState());
    }

    public function testAuthenticateReturnsTheExistingUserMatchedByGoogleId(): void
    {
        $existing = new User();
        $existing->setEmail('deja-lie@example.test');
        $existing->setEmailVerifie(true);
        $existing->setPrenom('Jean');
        $existing->setNom('Dupont');
        $this->provider->method('getResourceOwner')->willReturn($this->googleUser('google-id-1', 'deja-lie@example.test'));
        $this->userRepository->method('findOneBy')->with(['googleId' => 'google-id-1'])->willReturn($existing);
        $this->em->expects(self::never())->method('persist');

        $result = $this->service->authenticate('a-code');

        self::assertSame($existing, $result);
    }

    public function testAuthenticateUpdatesTheExistingUserWhenGoogleDataChanged(): void
    {
        $existing = new User();
        $existing->setEmail('ancien-email@example.test');
        $existing->setEmailVerifie(true);
        $existing->setPrenom('Jean');
        $existing->setNom('Dupont');
        $this->provider->method('getResourceOwner')->willReturn($this->googleUser('google-id-2', 'nouvel-email@example.test'));
        $this->userRepository->method('findOneBy')->willReturn($existing);
        $this->em->expects(self::once())->method('flush');

        $this->service->authenticate('a-code');

        self::assertSame('nouvel-email@example.test', $existing->getEmail());
    }

    public function testAuthenticateSkipsFlushWhenNothingChanged(): void
    {
        $existing = new User();
        $existing->setEmail('meme-email@example.test');
        $existing->setEmailVerifie(true);
        $existing->setPrenom('Jean');
        $existing->setNom('Dupont');
        $existing->setPhoto('deja-une-photo.jpg');
        $this->provider->method('getResourceOwner')->willReturn($this->googleUser('google-id-3', 'meme-email@example.test', avatar: 'deja-une-photo.jpg'));
        $this->userRepository->method('findOneBy')->willReturn($existing);
        $this->em->expects(self::never())->method('flush');

        $this->service->authenticate('a-code');
    }

    public function testAuthenticateLinksGoogleToAnExistingAccountFoundByEmail(): void
    {
        $existing = new User();
        $existing->setEmail('deja-inscrit@example.test');
        $this->provider->method('getResourceOwner')->willReturn($this->googleUser('google-id-4', 'deja-inscrit@example.test'));
        $this->userRepository->method('findOneBy')->willReturn(null);
        $this->userRepository->method('findByEmail')->with('deja-inscrit@example.test')->willReturn($existing);
        $this->em->expects(self::once())->method('flush');
        $this->em->expects(self::never())->method('persist');

        $result = $this->service->authenticate('a-code');

        self::assertSame('google-id-4', $result->getGoogleId());
        self::assertSame('google', $result->getAuthProvider());
        self::assertTrue($result->isEmailVerifie());
    }

    public function testAuthenticateCreatesANewUserWhenNoneExists(): void
    {
        $this->provider->method('getResourceOwner')->willReturn($this->googleUser('google-id-5', 'inconnu@example.test', 'Alice', 'Martin', 'https://avatar.example/a.jpg'));
        $this->userRepository->method('findOneBy')->willReturn(null);
        $this->userRepository->method('findByEmail')->willReturn(null);
        $this->em->expects(self::once())->method('persist')->with(self::isInstanceOf(User::class));
        $this->em->expects(self::once())->method('flush');
        $this->settingsService->expects(self::once())->method('createDefaultSettings');

        $result = $this->service->authenticate('a-code');

        self::assertSame('inconnu@example.test', $result->getEmail());
        self::assertSame('Alice', $result->getPrenom());
        self::assertSame('Martin', $result->getNom());
        self::assertSame('google-id-5', $result->getGoogleId());
        self::assertSame('google', $result->getAuthProvider());
        self::assertTrue($result->isEmailVerifie());
        self::assertNull($result->getPassword(), 'un compte OAuth ne doit jamais avoir de mot de passe');
        self::assertSame(['ROLE_USER'], $result->getRoles());
        self::assertSame('https://avatar.example/a.jpg', $result->getPhoto());
    }

    public function testAuthenticateFallsBackToDefaultNamesWhenGoogleOmitsThem(): void
    {
        $this->provider->method('getResourceOwner')->willReturn($this->googleUser('google-id-6', 'sans-nom@example.test', null, null));
        $this->userRepository->method('findOneBy')->willReturn(null);
        $this->userRepository->method('findByEmail')->willReturn(null);

        $result = $this->service->authenticate('a-code');

        self::assertSame('Prénom', $result->getPrenom());
        self::assertSame('Nom', $result->getNom());
    }

    public function testAuthenticateWrapsATokenExchangeFailureInAGenericException(): void
    {
        $this->provider = $this->createMock(Google::class);
        $this->provider->method('getAccessToken')->willThrowException(new \Exception('invalid_grant'));
        $service = new GoogleAuthService($this->em, $this->userRepository, new NullLogger(), $this->settingsService, $this->provider);

        try {
            $service->authenticate('bad-code');
            self::fail('une BadRequestHttpException etait attendue');
        } catch (BadRequestHttpException $e) {
            self::assertStringNotContainsString('invalid_grant', $e->getMessage(), 'le detail technique de l\'echange de token ne doit jamais fuiter au client');
        }
    }
}
