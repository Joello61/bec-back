<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\RefreshToken;
use App\Entity\User;
use App\Repository\RefreshTokenRepository;
use App\Service\RefreshTokenManager;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\PasswordHasher\Hasher\PasswordHasherFactory;

/**
 * Phase 4b, Lot 1 (bec-docs/docs/plan-correction/plan-correction-cobage.md) : composant de
 * securite critique (rotation JWT, cf. CLAUDE.md section 5) - jusqu'ici exerce uniquement
 * indirectement via TokenControllerTest (Phase 4), jamais teste unitairement en isolation.
 *
 * Le hasher utilise est un PasswordHasherFactory REEL (pas mocke) pour valider le vrai
 * comportement hash/verify du pattern selector/validator, pas une simulation.
 */
class RefreshTokenManagerTest extends TestCase
{
    private EntityManagerInterface&\PHPUnit\Framework\MockObject\MockObject $em;
    private RefreshTokenRepository&\PHPUnit\Framework\MockObject\MockObject $repository;
    private RequestStack $requestStack;
    private RefreshTokenManager $manager;

    protected function setUp(): void
    {
        $this->em = $this->createMock(EntityManagerInterface::class);
        $this->repository = $this->createMock(RefreshTokenRepository::class);
        $this->requestStack = new RequestStack();
        $hasherFactory = new PasswordHasherFactory([
            'common' => ['algorithm' => 'auto', 'cost' => 4, 'time_cost' => 3, 'memory_cost' => 10],
        ]);

        $this->manager = new RefreshTokenManager(
            $this->em,
            $this->repository,
            $this->requestStack,
            $hasherFactory,
            new NullLogger(),
        );
    }

    /**
     * @return array{entity: RefreshToken, rawToken: string} le raw validator n'est jamais
     * relisible depuis l'entite persistee (par design, seul son hash y est stocke) - le
     * capturer ici, au moment de la creation, est le seul moyen de disposer d'un couple
     * (entite geree par un mock repository, token brut valide) pour les tests de validate*/
    private function capturePersistedEntity(): array
    {
        $captured = null;
        $this->em->method('persist')->willReturnCallback(function ($entity) use (&$captured) {
            $captured = $entity;
        });

        $rawToken = $this->manager->createAndSaveRefreshToken(new User(), invalidateOldTokens: false);

        self::assertInstanceOf(RefreshToken::class, $captured);
        [$selector] = explode('.', $rawToken, 2);
        self::assertSame($selector, $captured->getSelector());

        return ['entity' => $captured, 'rawToken' => $rawToken];
    }

    public function testCreateAndSaveRefreshTokenReturnsSelectorDotValidator(): void
    {
        $this->em->expects(self::once())->method('persist');
        $this->em->expects(self::once())->method('flush');

        $token = $this->manager->createAndSaveRefreshToken(new User(), invalidateOldTokens: false);

        $parts = explode('.', $token);
        self::assertCount(2, $parts);
        self::assertSame(32, strlen($parts[0]), 'selecteur : 16 octets en hexadecimal = 32 caracteres');
        self::assertSame(64, strlen($parts[1]), 'validateur : 32 octets en hexadecimal = 64 caracteres');
    }

    public function testCreateAndSaveRefreshTokenNeverStoresTheRawValidatorInClear(): void
    {
        ['entity' => $captured, 'rawToken' => $rawToken] = $this->capturePersistedEntity();
        [, $rawValidator] = explode('.', $rawToken, 2);

        self::assertNotSame($rawValidator, $captured->getValidatorHash(), 'seul le hash du validateur doit etre persiste, jamais sa valeur en clair');
    }

    public function testCreateAndSaveRefreshTokenWithRotationInvalidatesOldTokens(): void
    {
        $user = new User();
        $existingToken = new RefreshToken();
        $this->repository->method('findBy')->with(['user' => $user])->willReturn([$existingToken]);
        $this->em->expects(self::once())->method('remove')->with($existingToken);
        // flush() appele une fois pour l'invalidation, une fois pour la creation
        $this->em->expects(self::exactly(2))->method('flush');

        $this->manager->createAndSaveRefreshToken($user, invalidateOldTokens: true);
    }

    public function testCreateAndSaveRefreshTokenWithoutRotationSkipsInvalidation(): void
    {
        $this->repository->expects(self::never())->method('findBy');

        $this->manager->createAndSaveRefreshToken(new User(), invalidateOldTokens: false);
    }

    public function testCreateAndSaveRefreshTokenCapturesRequestMetadataWhenPresent(): void
    {
        $request = Request::create('/api/token/refresh');
        $request->headers->set('User-Agent', 'PHPUnit-Test-Agent');
        $request->server->set('REMOTE_ADDR', '203.0.113.42');
        $this->requestStack->push($request);

        ['entity' => $captured] = $this->capturePersistedEntity();

        self::assertSame('203.0.113.42', $captured->getClientIp());
        self::assertSame('PHPUnit-Test-Agent', $captured->getUserAgent());
    }

    public function testCreateAndSaveRefreshTokenLeavesMetadataNullWithoutRequest(): void
    {
        ['entity' => $captured] = $this->capturePersistedEntity();

        self::assertNull($captured->getClientIp());
        self::assertNull($captured->getUserAgent());
    }

    public function testValidateRefreshTokenAcceptsAMatchingSelectorAndValidator(): void
    {
        ['entity' => $captured, 'rawToken' => $rawToken] = $this->capturePersistedEntity();
        $this->repository->method('findOneNonExpiredBySelector')->with($captured->getSelector())->willReturn($captured);

        $result = $this->manager->validateRefreshToken($rawToken);

        self::assertSame($captured, $result);
    }

    public function testValidateRefreshTokenRejectsMalformedToken(): void
    {
        $this->repository->expects(self::never())->method('findOneNonExpiredBySelector');

        self::assertNull($this->manager->validateRefreshToken('pas-de-point-separateur'));
    }

    public function testValidateRefreshTokenRejectsUnknownSelector(): void
    {
        $this->repository->method('findOneNonExpiredBySelector')->willReturn(null);

        self::assertNull($this->manager->validateRefreshToken('selecteur-inconnu.validateur'));
    }

    public function testValidateRefreshTokenRejectsMismatchedValidator(): void
    {
        ['entity' => $captured] = $this->capturePersistedEntity();
        $this->repository->method('findOneNonExpiredBySelector')->with($captured->getSelector())->willReturn($captured);

        $result = $this->manager->validateRefreshToken($captured->getSelector() . '.un-tout-autre-validateur');

        self::assertNull($result, 'le hash stocke ne doit correspondre qu\'au validateur original, jamais a une valeur substituee');
    }

    public function testInvalidateTokenRemovesAValidToken(): void
    {
        ['entity' => $captured, 'rawToken' => $rawToken] = $this->capturePersistedEntity();
        $this->repository->method('findOneNonExpiredBySelector')->willReturn($captured);
        $this->em->expects(self::once())->method('remove')->with($captured);

        $this->manager->invalidateToken($rawToken);
    }

    public function testInvalidateTokenIsANoopForAnUnknownToken(): void
    {
        $this->repository->method('findOneNonExpiredBySelector')->willReturn(null);
        $this->em->expects(self::never())->method('remove');

        $this->manager->invalidateToken('selecteur-inconnu.validateur');
    }

    public function testInvalidateUserTokensRemovesEachToken(): void
    {
        $user = new User();
        $tokenA = new RefreshToken();
        $tokenB = new RefreshToken();
        $this->repository->method('findBy')->with(['user' => $user])->willReturn([$tokenA, $tokenB]);
        $this->em->expects(self::exactly(2))->method('remove');
        $this->em->expects(self::once())->method('flush');

        $this->manager->invalidateUserTokens($user);
    }

    public function testInvalidateUserTokensIsANoopWhenNoneExist(): void
    {
        $this->repository->method('findBy')->willReturn([]);
        $this->em->expects(self::never())->method('remove');
        $this->em->expects(self::never())->method('flush');

        $this->manager->invalidateUserTokens(new User());
    }

    public function testDeleteExpiredTokensDelegatesToRepository(): void
    {
        $this->repository->method('deleteExpiredTokens')->willReturn(7);

        self::assertSame(7, $this->manager->deleteExpiredTokens());
    }
}
