<?php

declare(strict_types=1);

namespace App\Tests\EventListener;

use App\Entity\Address;
use App\Entity\User;
use App\EventListener\ExceptionListener;
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
