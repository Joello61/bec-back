<?php

declare(strict_types=1);

namespace App\Tests\Mercure;

use Symfony\Component\Mercure\Update;

/**
 * Callable de publication utilisé par MockHub en environnement de test
 * (config/packages/mercure.yaml, when@test) - aucun hub Mercure réel n'est
 * joignable pendant les tests (cf. Phase D0 du plan de déploiement).
 */
final class NullMercurePublisher
{
    public function __invoke(Update $update): string
    {
        return 'test-update-id';
    }
}
