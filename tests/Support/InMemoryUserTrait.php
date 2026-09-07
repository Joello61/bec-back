<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Entity\Address;
use App\Entity\User;

/**
 * Construit des User en mémoire, sans Doctrine, pour les tests unitaires purs
 * (Voters, VisibilityService, MatchingService::calculateMatchScore) qui n'ont
 * besoin que du comportement des entités, jamais de persistance.
 */
trait InMemoryUserTrait
{
    /**
     * @param string[] $roles
     */
    private function makeUser(array $roles = [], bool $profileComplete = false, bool $banned = false): User
    {
        $user = new User();
        $user->setEmail('user-' . uniqid() . '@example.test');
        $user->setNom('Test');
        $user->setPrenom('User');
        $user->setPassword('irrelevant');
        $user->setRoles($roles);
        $user->setIsBanned($banned);

        if ($profileComplete) {
            $address = new Address();
            $address->setPays('Cameroun');
            $address->setVille('Douala');
            $address->setQuartier('Bonapriso');
            $address->setUser($user);
            $user->setAddress($address);

            $user->setTelephone('+237600000000');
            $user->setTelephoneVerifie(true);
            $user->setEmailVerifie(true);
        }

        return $user;
    }
}
