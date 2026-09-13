<?php

declare(strict_types=1);

namespace App\Tests\EventListener;

use App\Entity\Address;
use App\Entity\SubscriptionPlan;
use App\Entity\User;
use App\EventListener\ExceptionListener;
use App\Repository\DemandeRepository;
use App\Repository\VoyageRepository;
use App\Service\SubscriptionService;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

class ExceptionListenerTest extends TestCase
{
    public function testProfileIncompleteAccessDeniedReturnsMissingFieldsWithoutCrashing(): void
    {
        $user = new User();
        $user->setEmail('incomplete@example.test');
        $user->setNom('Test');
        $user->setPrenom('Incomplet');
        $user->setPassword('irrelevant');
        $user->setTelephone('+237600000000');
        // Email et téléphone non vérifiés, pas d'adresse : profil incomplet sur tous les axes.

        $kernel = $this->createMock(HttpKernelInterface::class);
        $request = Request::create('/api/test');
        $request->attributes->set('_security_user', $user);

        $event = new ExceptionEvent(
            $kernel,
            $request,
            HttpKernelInterface::MAIN_REQUEST,
            new AccessDeniedException('Accès refusé')
        );

        $listener = new ExceptionListener($this->createMock(LoggerInterface::class), 'prod', false);
        $listener->onKernelException($event);

        $payload = json_decode($event->getResponse()->getContent(), true);

        self::assertSame('PROFILE_INCOMPLETE', $payload['error']);
        self::assertContains('email_verification', $payload['details']);
        self::assertContains('telephone_verification', $payload['details']);
        self::assertContains('location', $payload['details']);
        self::assertContains('address', $payload['details']);
    }

    public function testProfileIncompleteWithAddressDoesNotFlagLocationOrAddress(): void
    {
        $user = new User();
        $user->setEmail('complete-address@example.test');
        $user->setNom('Test');
        $user->setPrenom('AvecAdresse');
        $user->setPassword('irrelevant');
        $user->setTelephone('+237600000001');

        $address = new Address();
        $address->setPays('Cameroun');
        $address->setVille('Douala');
        $address->setQuartier('Bonapriso');
        $user->setAddress($address);

        $kernel = $this->createMock(HttpKernelInterface::class);
        $request = Request::create('/api/test');
        $request->attributes->set('_security_user', $user);

        $event = new ExceptionEvent(
            $kernel,
            $request,
            HttpKernelInterface::MAIN_REQUEST,
            new AccessDeniedException('Accès refusé')
        );

        $listener = new ExceptionListener($this->createMock(LoggerInterface::class), 'prod', false);
        $listener->onKernelException($event);

        $payload = json_decode($event->getResponse()->getContent(), true);

        self::assertSame('PROFILE_INCOMPLETE', $payload['error']);
        self::assertNotContains('location', $payload['details']);
        self::assertNotContains('address', $payload['details']);
    }

    public function testVoyageQuotaExceededReturnsQuotaExceededPayload(): void
    {
        $user = $this->createCompleteProfileUser('quota-atteint@example.test');

        $plan = $this->createMock(SubscriptionPlan::class);
        $plan->method('getMaxActiveVoyages')->willReturn(3);
        $plan->method('getCode')->willReturn('free');

        $subscriptionService = $this->createMock(SubscriptionService::class);
        $subscriptionService->method('getEffectivePlan')->with($user)->willReturn($plan);

        $voyageRepository = $this->createMock(VoyageRepository::class);
        $voyageRepository->method('countActiveByUser')->with($user)->willReturn(3);

        $event = $this->createAccessDeniedEventFor($user, 'VOYAGE_CREATE');

        $listener = new ExceptionListener(
            $this->createMock(LoggerInterface::class),
            'prod',
            false,
            $subscriptionService,
            $voyageRepository,
            $this->createMock(DemandeRepository::class),
        );
        $listener->onKernelException($event);

        $payload = json_decode($event->getResponse()->getContent(), true);

        self::assertSame('QUOTA_EXCEEDED', $payload['error']);
        self::assertSame('free', $payload['currentPlan']);
        self::assertSame(3, $payload['limit']);
    }

    public function testDemandeQuotaNotYetReachedFallsBackToGenericAccessDenied(): void
    {
        $user = $this->createCompleteProfileUser('quota-ok@example.test');

        $plan = $this->createMock(SubscriptionPlan::class);
        $plan->method('getMaxActiveDemandes')->willReturn(3);

        $subscriptionService = $this->createMock(SubscriptionService::class);
        $subscriptionService->method('getEffectivePlan')->with($user)->willReturn($plan);

        $demandeRepository = $this->createMock(DemandeRepository::class);
        $demandeRepository->method('countActiveByUser')->with($user)->willReturn(1);

        $event = $this->createAccessDeniedEventFor($user, 'DEMANDE_CREATE');

        $listener = new ExceptionListener(
            $this->createMock(LoggerInterface::class),
            'prod',
            false,
            $subscriptionService,
            $this->createMock(VoyageRepository::class),
            $demandeRepository,
        );
        $listener->onKernelException($event);

        $payload = json_decode($event->getResponse()->getContent(), true);

        self::assertSame('ACCESS_DENIED', $payload['error']);
    }

    public function testQuotaCheckFailingFailsOpenToGenericAccessDenied(): void
    {
        $user = $this->createCompleteProfileUser('catalogue-non-seede@example.test');

        $subscriptionService = $this->createMock(SubscriptionService::class);
        $subscriptionService->method('getEffectivePlan')->willThrowException(new \RuntimeException('Plan gratuit introuvable'));

        $event = $this->createAccessDeniedEventFor($user, 'VOYAGE_CREATE');

        $listener = new ExceptionListener(
            $this->createMock(LoggerInterface::class),
            'prod',
            false,
            $subscriptionService,
            $this->createMock(VoyageRepository::class),
            $this->createMock(DemandeRepository::class),
        );
        $listener->onKernelException($event);

        $payload = json_decode($event->getResponse()->getContent(), true);

        self::assertSame('ACCESS_DENIED', $payload['error']);
    }

    public function testAccessDeniedWithoutCreateAttributeIsUnaffectedByQuotaCheck(): void
    {
        $user = $this->createCompleteProfileUser('edit-refuse@example.test');

        $kernel = $this->createMock(HttpKernelInterface::class);
        $request = Request::create('/api/test');
        $request->attributes->set('_security_user', $user);

        $exception = new AccessDeniedException('Accès refusé');
        $exception->setAttributes(['VOYAGE_EDIT']);

        $event = new ExceptionEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST, $exception);

        $listener = new ExceptionListener(
            $this->createMock(LoggerInterface::class),
            'prod',
            false,
            $this->createMock(SubscriptionService::class),
            $this->createMock(VoyageRepository::class),
            $this->createMock(DemandeRepository::class),
        );
        $listener->onKernelException($event);

        $payload = json_decode($event->getResponse()->getContent(), true);

        self::assertSame('ACCESS_DENIED', $payload['error']);
    }

    private function createCompleteProfileUser(string $email): User
    {
        $user = new User();
        $user->setEmail($email);
        $user->setNom('Test');
        $user->setPrenom('Complet');
        $user->setPassword('irrelevant');
        $user->setTelephone('+237600000002');
        $user->setEmailVerifie(true);
        $user->setTelephoneVerifie(true);

        $address = new Address();
        $address->setPays('Cameroun');
        $address->setVille('Douala');
        $address->setQuartier('Bonapriso');
        $user->setAddress($address);

        return $user;
    }

    private function createAccessDeniedEventFor(User $user, string $attribute): ExceptionEvent
    {
        $kernel = $this->createMock(HttpKernelInterface::class);
        $request = Request::create('/api/test');
        $request->attributes->set('_security_user', $user);

        $exception = new AccessDeniedException('Accès refusé');
        $exception->setAttributes([$attribute]);

        return new ExceptionEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST, $exception);
    }

    public function testNoTechnicalDetailsWhenDebugIsDisabled(): void
    {
        $event = $this->createExceptionEvent();
        $listener = new ExceptionListener($this->createMock(LoggerInterface::class), 'prod', false);

        $listener->onKernelException($event);

        $payload = json_decode($event->getResponse()->getContent(), true);

        self::assertSame([], $payload['errors']);
        self::assertSame('Une erreur est survenue', $payload['message']);
    }

    public function testTechnicalDetailsExposedWhenDebugIsEnabled(): void
    {
        $event = $this->createExceptionEvent();
        $listener = new ExceptionListener($this->createMock(LoggerInterface::class), 'prod', true);

        $listener->onKernelException($event);

        $payload = json_decode($event->getResponse()->getContent(), true);

        self::assertArrayHasKey('trace', $payload['errors']);
        self::assertSame(\RuntimeException::class, $payload['errors']['exception']);
    }

    private function createExceptionEvent(): ExceptionEvent
    {
        $kernel = $this->createMock(HttpKernelInterface::class);
        $request = Request::create('/api/test');

        return new ExceptionEvent(
            $kernel,
            $request,
            HttpKernelInterface::MAIN_REQUEST,
            new \RuntimeException('Erreur technique interne sensible')
        );
    }
}
