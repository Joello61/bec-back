<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Entity\Address;
use App\Entity\User;
use App\Entity\UserSettings;

/**
 * Fabrique d'utilisateurs partagée entre les tests fonctionnels/d'intégration
 * (WebTestCase/KernelTestCase). Attend que la classe consommatrice expose
 * `$this->em` (EntityManagerInterface), comme le fait déjà chaque test qui
 * l'utilisait avant l'extraction de ce trait.
 */
trait UserFactoryTrait
{
    protected function createUser(
        string $emailPrefix,
        string $ville = 'Douala',
        ?bool $showEmail = null,
        ?bool $showPhone = null,
    ): User {
        $user = new User();
        $user->setEmail($emailPrefix . '-' . uniqid() . '@example.test');
        $user->setNom('Test');
        $user->setPrenom($emailPrefix);
        $user->setPassword('irrelevant');

        $address = new Address();
        $address->setPays('Cameroun');
        $address->setVille($ville);
        $address->setQuartier('Bonapriso');
        $address->setUser($user);
        $user->setAddress($address);

        $this->em->persist($user);
        $this->em->persist($address);

        if ($showEmail !== null || $showPhone !== null) {
            $settings = new UserSettings();
            $settings->setUser($user);
            if ($showEmail !== null) {
                $settings->setShowEmail($showEmail);
            }
            if ($showPhone !== null) {
                $settings->setShowPhone($showPhone);
            }
            $user->setSettings($settings);
            $this->em->persist($settings);
        }

        $this->em->flush();

        return $user;
    }

    /**
     * Utilisateur satisfaisant User::isProfileComplete() (email/téléphone vérifiés,
     * téléphone renseigné, adresse valide) - nécessaire pour les Voters *_CREATE
     * (VoyageVoter, DemandeVoter, MessageVoter, AvisVoter, SignalementVoter) et les
     * flux d'authentification qui en dépendent.
     */
    protected function createCompleteProfileUser(
        string $emailPrefix,
        string $ville = 'Douala',
        ?bool $showEmail = null,
        ?bool $showPhone = null,
    ): User {
        $user = $this->createUser($emailPrefix, $ville, $showEmail, $showPhone);
        $user->setTelephone('+237600000000');
        $user->setTelephoneVerifie(true);
        $user->setEmailVerifie(true);
        $this->em->flush();

        return $user;
    }
}
