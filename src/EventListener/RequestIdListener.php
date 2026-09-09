<?php

declare(strict_types=1);

namespace App\EventListener;

use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Uid\Uuid;

/**
 * Attache un identifiant unique à chaque requête HTTP entrante, utilisé à la fois pour
 * corréler les logs (RequestIdProcessor, Loki) et les traces (Tracer, Tempo) d'une même
 * requête - condition nécessaire pour naviguer de l'un vers l'autre dans Grafana.
 */
#[AsEventListener(event: KernelEvents::REQUEST, priority: 1000)]
class RequestIdListener
{
    public const ATTRIBUTE = 'request_id';

    public function __invoke(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $event->getRequest()->attributes->set(self::ATTRIBUTE, Uuid::v7()->toRfc4122());
    }
}
