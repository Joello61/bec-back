<?php

declare(strict_types=1);

namespace App\Tests\EventListener;

use App\EventListener\JWTListener;
use App\Service\CookieManager;
use App\Service\MercureTokenService;
use App\Service\RefreshTokenManager;
use Lexik\Bundle\JWTAuthenticationBundle\Event\AuthenticationFailureEvent;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;

class JWTListenerTest extends TestCase
{
    public function testNoTraceExposedWhenDebugIsDisabled(): void
    {
        $listener = $this->createListener(debug: false);
        $event = new AuthenticationFailureEvent(
            new AuthenticationException('Identifiants invalides.'),
            null
        );

        $listener->onAuthenticationFailure($event);

        $payload = json_decode($event->getResponse()->getContent(), true);

        self::assertSame('Identifiants invalides.', $payload['message']);
        self::assertArrayNotHasKey('trace', $payload);
        self::assertArrayNotHasKey('type', $payload);
    }

    public function testTraceExposedWhenDebugIsEnabled(): void
    {
        $listener = $this->createListener(debug: true);
        $event = new AuthenticationFailureEvent(
            new AuthenticationException('Identifiants invalides.'),
            null
        );

        $listener->onAuthenticationFailure($event);

        $payload = json_decode($event->getResponse()->getContent(), true);

        self::assertArrayHasKey('trace', $payload);
        self::assertSame(AuthenticationException::class, $payload['type']);
    }

    private function createListener(bool $debug): JWTListener
    {
        return new JWTListener(
            $this->createMock(MercureTokenService::class),
            $this->createMock(RefreshTokenManager::class),
            $this->createMock(CookieManager::class),
            $this->createMock(LoggerInterface::class),
            $debug,
        );
    }
}
