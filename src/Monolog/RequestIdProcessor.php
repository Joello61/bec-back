<?php

declare(strict_types=1);

namespace App\Monolog;

use App\EventListener\RequestIdListener;
use Monolog\LogRecord;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Injecte le request_id (RequestIdListener) dans chaque log émis pendant une requête HTTP -
 * corrélation avec les traces Tempo dans Grafana (via le champ dérivé configuré côté
 * infrastructure sur la source Loki).
 */
final readonly class RequestIdProcessor
{
    public function __construct(
        private RequestStack $requestStack,
    ) {}

    public function __invoke(LogRecord $record): LogRecord
    {
        $requestId = $this->requestStack->getCurrentRequest()
            ?->attributes
            ->get(RequestIdListener::ATTRIBUTE);

        if (is_string($requestId)) {
            $record->extra['request_id'] = $requestId;
        }

        return $record;
    }
}
