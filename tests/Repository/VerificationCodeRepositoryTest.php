<?php

declare(strict_types=1);

namespace App\Tests\Repository;

use App\Entity\VerificationCode;
use App\Repository\VerificationCodeRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Phase 4b, Lot 11 (bec-docs/docs/plan-correction/plan-correction-cobage.md) : repository
 * de securite - findValidCodeForEmail/Phone doivent rejeter un code errone, deja utilise,
 * expire, ou du mauvais type (email vs phone), pas seulement verifier sa presence en base.
 */
class VerificationCodeRepositoryTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private VerificationCodeRepository $repository;

    protected function setUp(): void
    {
        self::ensureKernelShutdown();
        self::bootKernel();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->repository = static::getContainer()->get(VerificationCodeRepository::class);
    }

    private function code(
        string $type,
        string $code,
        \DateTimeInterface $expiresAt,
        ?string $email = null,
        ?string $phone = null,
        bool $used = false
    ): VerificationCode {
        $entity = new VerificationCode();
        $entity->setType($type);
        $entity->setCode($code);
        $entity->setExpiresAt($expiresAt);
        $entity->setUsed($used);
        if ($email !== null) {
            $entity->setEmail($email);
        }
        if ($phone !== null) {
            $entity->setPhone($phone);
        }
        $this->em->persist($entity);
        $this->em->flush();

        return $entity;
    }

    // ==================== findValidCodeForEmail ====================

    public function testFindValidCodeForEmailRejectsAWrongCode(): void
    {
        $this->code('email', '123456', new \DateTime('+1 hour'), email: 'verif-email-wrong@example.test');

        self::assertNull($this->repository->findValidCodeForEmail('verif-email-wrong@example.test', '000000'));
    }

    public function testFindValidCodeForEmailRejectsAnExpiredCode(): void
    {
        $this->code('email', '111111', new \DateTime('-1 hour'), email: 'verif-email-expired@example.test');

        self::assertNull($this->repository->findValidCodeForEmail('verif-email-expired@example.test', '111111'));
    }

    public function testFindValidCodeForEmailRejectsAnAlreadyUsedCode(): void
    {
        $this->code('email', '222222', new \DateTime('+1 hour'), email: 'verif-email-used@example.test', used: true);

        self::assertNull($this->repository->findValidCodeForEmail('verif-email-used@example.test', '222222'));
    }

    public function testFindValidCodeForEmailDoesNotMatchAPhoneTypeCode(): void
    {
        // Meme email et meme code, mais type=phone : ne doit jamais matcher via
        // findValidCodeForEmail (isolation stricte entre les deux canaux).
        $this->code('phone', '333333', new \DateTime('+1 hour'), email: 'verif-crosstype@example.test');

        self::assertNull($this->repository->findValidCodeForEmail('verif-crosstype@example.test', '333333'));
    }

    public function testFindValidCodeForEmailAcceptsAFreshValidCode(): void
    {
        $this->code('email', '444444', new \DateTime('+1 hour'), email: 'verif-email-ok@example.test');

        $found = $this->repository->findValidCodeForEmail('verif-email-ok@example.test', '444444');

        self::assertNotNull($found);
    }

    // ==================== findValidCodeForPhone ====================

    public function testFindValidCodeForPhoneRejectsAnExpiredCode(): void
    {
        $this->code('phone', '555555', new \DateTime('-1 hour'), phone: '+237600000001');

        self::assertNull($this->repository->findValidCodeForPhone('+237600000001', '555555'));
    }

    public function testFindValidCodeForPhoneAcceptsAFreshValidCode(): void
    {
        $this->code('phone', '666666', new \DateTime('+1 hour'), phone: '+237600000002');

        $found = $this->repository->findValidCodeForPhone('+237600000002', '666666');

        self::assertNotNull($found);
    }

    // ==================== deleteExpiredCodes ====================

    public function testDeleteExpiredCodesOnlyRemovesExpiredOnes(): void
    {
        $valid = $this->code('email', '777777', new \DateTime('+1 hour'), email: 'verif-delexp-valid@example.test');
        $this->code('email', '888888', new \DateTime('-1 hour'), email: 'verif-delexp-expired@example.test');

        $deleted = $this->repository->deleteExpiredCodes();
        $this->em->clear();

        self::assertGreaterThanOrEqual(1, $deleted);
        self::assertNotNull($this->em->getRepository(VerificationCode::class)->find($valid->getId()));
    }

    // ==================== deleteOldCodesForEmail / deleteOldCodesForPhone ====================

    public function testDeleteOldCodesForEmailOnlyTargetsEmailType(): void
    {
        $phoneCode = $this->code('phone', '999999', new \DateTime('+1 hour'), phone: '+237600000003', email: 'verif-delold@example.test');
        $this->code('email', '000001', new \DateTime('+1 hour'), email: 'verif-delold@example.test');

        $this->repository->deleteOldCodesForEmail('verif-delold@example.test');
        $this->em->clear();

        self::assertNotNull($this->em->getRepository(VerificationCode::class)->find($phoneCode->getId()));
        self::assertNull($this->repository->findValidCodeForEmail('verif-delold@example.test', '000001'));
    }
}
