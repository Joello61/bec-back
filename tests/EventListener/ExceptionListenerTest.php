<?php

declare(strict_types=1);

namespace App\Tests\EventListener;

use App\EventListener\ExceptionListener;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

class ExceptionListenerTest extends TestCase
{
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
