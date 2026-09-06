<?php

declare(strict_types=1);

namespace App\Tests\EventListener;

use App\EventListener\SecurityHeadersListener;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

class SecurityHeadersListenerTest extends TestCase
{
    public function testSecurityHeadersAddedOnApiRoutes(): void
    {
        $event = $this->createResponseEvent('/api/voyages', secure: false);

        (new SecurityHeadersListener())($event);

        $headers = $event->getResponse()->headers;
        self::assertSame('nosniff', $headers->get('X-Content-Type-Options'));
        self::assertSame('DENY', $headers->get('X-Frame-Options'));
        self::assertSame('no-referrer', $headers->get('Referrer-Policy'));
        self::assertSame("default-src 'none'; frame-ancestors 'none'", $headers->get('Content-Security-Policy'));
        self::assertFalse($headers->has('Strict-Transport-Security'));
    }

    public function testHstsOnlyAddedOverHttps(): void
    {
        $event = $this->createResponseEvent('/api/voyages', secure: true);

        (new SecurityHeadersListener())($event);

        self::assertTrue($event->getResponse()->headers->has('Strict-Transport-Security'));
    }

    public function testHeadersNotAddedOutsideApi(): void
    {
        $event = $this->createResponseEvent('/some-non-api-path', secure: false);

        (new SecurityHeadersListener())($event);

        self::assertFalse($event->getResponse()->headers->has('X-Frame-Options'));
    }

    private function createResponseEvent(string $path, bool $secure): ResponseEvent
    {
        $kernel = $this->createMock(HttpKernelInterface::class);
        $request = Request::create($path);
        if ($secure) {
            $request->server->set('HTTPS', 'on');
        }

        return new ResponseEvent(
            $kernel,
            $request,
            HttpKernelInterface::MAIN_REQUEST,
            new Response()
        );
    }
}
