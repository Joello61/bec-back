<?php

declare(strict_types=1);

namespace App\Tests\Support;

/**
 * Force l'id d'une entite jamais persistee, par reflexion. Necessaire des qu'un test
 * unitaire pur doit distinguer deux entites via une comparaison getId() (ex. "un admin
 * ne peut pas s'auto-cibler") sans passer par une vraie base - deux entites non
 * persistees ont sinon toutes un id null, rendant cette comparaison toujours vraie.
 */
trait EntityIdTrait
{
    private function setEntityId(object $entity, int $id): void
    {
        $property = new \ReflectionProperty($entity, 'id');
        $property->setValue($entity, $id);
    }
}
