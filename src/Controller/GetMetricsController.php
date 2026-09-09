<?php

declare(strict_types=1);

namespace App\Controller;

use Artprima\PrometheusMetricsBundle\Metrics\Renderer;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Scrapé par Prometheus (infrastructure partagée du VPS), jamais par un utilisateur
 * applicatif - protégé par un jeton dédié (METRICS_SCRAPE_TOKEN), jamais le firewall JWT
 * (config/packages/security.yaml, access_control PUBLIC_ACCESS explicite sur cette seule
 * route). Ce jeton est la seule protection réelle de cette route.
 */
final class GetMetricsController
{
    public function __construct(
        private readonly Renderer $renderer,
        private readonly string $metricsScrapeToken,
    ) {}

    #[Route('/api/metrics', name: 'metrics_scrape', methods: ['GET'])]
    public function __invoke(Request $request): Response
    {
        $provided = $request->headers->get('Authorization');
        $expected = 'Bearer '.$this->metricsScrapeToken;

        if (!is_string($provided) || !hash_equals($expected, $provided)) {
            throw new UnauthorizedHttpException('Bearer', 'Jeton de scrape invalide ou manquant.');
        }

        return $this->renderer->renderResponse();
    }
}
