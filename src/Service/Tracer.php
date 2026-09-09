<?php

declare(strict_types=1);

namespace App\Service;

use App\EventListener\RequestIdListener;
use OpenTelemetry\API\Common\Time\Clock;
use OpenTelemetry\API\Trace\StatusCode;
use OpenTelemetry\API\Trace\TracerInterface;
use OpenTelemetry\Contrib\Otlp\ContentTypes;
use OpenTelemetry\Contrib\Otlp\OtlpHttpTransportFactory;
use OpenTelemetry\Contrib\Otlp\SpanExporter;
use OpenTelemetry\SDK\Common\Attribute\Attributes;
use OpenTelemetry\SDK\Resource\ResourceInfo;
use OpenTelemetry\SDK\Resource\ResourceInfoFactory;
use OpenTelemetry\SDK\Trace\SpanProcessor\BatchSpanProcessor;
use OpenTelemetry\SDK\Trace\TracerProvider;
use OpenTelemetry\SDK\Trace\TracerProviderInterface;
use OpenTelemetry\SemConv\ResourceAttributes;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Event\TerminateEvent;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;
use Symfony\Component\Messenger\Event\WorkerMessageHandledEvent;

/**
 * Point d'entrée unique vers le SDK OpenTelemetry (Phase D1) - instrumentation manuelle
 * uniquement, jamais l'extension PECL d'auto-instrumentation ni un bundle Symfony
 * communautaire (même choix que factu_sentinel, déjà en production sur ce VPS).
 *
 * request_id (RequestIdListener) est injecté automatiquement sur chaque span émis depuis
 * une requête HTTP - condition nécessaire à la corrélation logs Loki <-> traces Tempo dans
 * Grafana. Dans un contexte sans requête HTTP (worker Messenger), $attributes doit porter
 * explicitement ce dont dispose l'appelant.
 *
 * BatchSpanProcessor + flush explicite (jamais un minuteur d'arrière-plan, qui ne
 * survivrait pas à la fin d'un processus PHP-FPM/Messenger court-vécu) : kernel.terminate
 * pour les requêtes HTTP (après l'envoi de la réponse, jamais sur le chemin critique), les
 * deux événements de fin de message Messenger pour worker/scheduler.
 */
final class Tracer
{
    private readonly TracerProviderInterface $tracerProvider;
    private readonly TracerInterface $tracer;

    public function __construct(
        private readonly RequestStack $requestStack,
        string $tempoOtlpEndpoint,
    ) {
        $transport = (new OtlpHttpTransportFactory())->create($tempoOtlpEndpoint, ContentTypes::JSON);
        $exporter = new SpanExporter($transport);

        $resource = ResourceInfoFactory::defaultResource()->merge(ResourceInfo::create(Attributes::create([
            ResourceAttributes::SERVICE_NAME => 'cobage-backend',
        ])));

        $this->tracerProvider = new TracerProvider(
            new BatchSpanProcessor($exporter, Clock::getDefault()),
            null,
            $resource,
        );

        $this->tracer = $this->tracerProvider->getTracer('app.cobage');
    }

    /**
     * @template T
     *
     * @param callable(): T       $callback
     * @param array<string,mixed> $attributes
     *
     * @return T
     */
    public function trace(string $spanName, callable $callback, array $attributes = []): mixed
    {
        $span = $this->tracer->spanBuilder($spanName)->startSpan();

        foreach ($attributes as $key => $value) {
            $span->setAttribute($key, $value);
        }

        $requestId = $this->requestStack->getCurrentRequest()?->attributes->get(RequestIdListener::ATTRIBUTE);
        if (is_string($requestId)) {
            $span->setAttribute('request_id', $requestId);
        }

        $scope = $span->activate();

        try {
            return $callback();
        } catch (\Throwable $exception) {
            $span->recordException($exception);
            $span->setStatus(StatusCode::STATUS_ERROR, $exception->getMessage());

            throw $exception;
        } finally {
            $scope->detach();
            $span->end();
        }
    }

    #[AsEventListener]
    public function onKernelTerminate(TerminateEvent $event): void
    {
        $this->tracerProvider->forceFlush();
    }

    #[AsEventListener]
    public function onWorkerMessageHandled(WorkerMessageHandledEvent $event): void
    {
        $this->tracerProvider->forceFlush();
    }

    #[AsEventListener]
    public function onWorkerMessageFailed(WorkerMessageFailedEvent $event): void
    {
        $this->tracerProvider->forceFlush();
    }
}
