<?php

declare(strict_types=1);

namespace App\Service;

use App\DTO\UpdateSettingsDTO;
use App\Entity\User;
use App\Entity\UserSettings;
use Doctrine\ORM\EntityManagerInterface;

readonly class SettingsService
{
    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {}

    /**
     * Créer les paramètres par défaut pour un utilisateur
     */
    public function createDefaultSettings(User $user): UserSettings
    {
        $settings = new UserSettings();
        $settings->setUser($user);

        $this->entityManager->persist($settings);
        $this->entityManager->flush();

        return $settings;
    }

    /**
     * Récupérer les paramètres d'un utilisateur
     */
    public function getUserSettings(User $user): UserSettings
    {
        $settings = $user->getSettings();

        if (!$settings) {
            // Créer les settings s'ils n'existent pas (pour les anciens utilisateurs)
            $settings = $this->createDefaultSettings($user);
        }

        return $settings;
    }

    /**
     * Mettre à jour les paramètres
     */
    public function updateSettings(User $user, UpdateSettingsDTO $dto): UserSettings
    {
        $settings = $this->getUserSettings($user);

        // Notifications
        if ($dto->emailNotificationsEnabled !== null) {
            $settings->setEmailNotificationsEnabled($dto->emailNotificationsEnabled);
        }
        if ($dto->smsNotificationsEnabled !== null) {
            $settings->setSmsNotificationsEnabled($dto->smsNotificationsEnabled);
        }
        if ($dto->pushNotificationsEnabled !== null) {
            $settings->setPushNotificationsEnabled($dto->pushNotificationsEnabled);
        }
        if ($dto->notifyOnNewMessage !== null) {
            $settings->setNotifyOnNewMessage($dto->notifyOnNewMessage);
        }
        if ($dto->notifyOnMatchingVoyage !== null) {
            $settings->setNotifyOnMatchingVoyage($dto->notifyOnMatchingVoyage);
        }
        if ($dto->notifyOnMatchingDemande !== null) {
            $settings->setNotifyOnMatchingDemande($dto->notifyOnMatchingDemande);
        }
        if ($dto->notifyOnNewAvis !== null) {
            $settings->setNotifyOnNewAvis($dto->notifyOnNewAvis);
        }
        if ($dto->notifyOnFavoriUpdate !== null) {
            $settings->setNotifyOnFavoriUpdate($dto->notifyOnFavoriUpdate);
        }

        // Confidentialité
        if ($dto->profileVisibility !== null) {
            $settings->setProfileVisibility($dto->profileVisibility);
        }
        if ($dto->showPhone !== null) {
            $settings->setShowPhone($dto->showPhone);
        }
        if ($dto->showEmail !== null) {
            $settings->setShowEmail($dto->showEmail);
        }
        if ($dto->showStats !== null) {
            $settings->setShowStats($dto->showStats);
        }
        if ($dto->messagePermission !== null) {
            $settings->setMessagePermission($dto->messagePermission);
        }
        if ($dto->showInSearchResults !== null) {
            $settings->setShowInSearchResults($dto->showInSearchResults);
        }
        if ($dto->showLastSeen !== null) {
            $settings->setShowLastSeen($dto->showLastSeen);
        }

        // Préférences
        if ($dto->langue !== null) {
            $settings->setLangue($dto->langue);
        }
        if ($dto->devise !== null) {
            $settings->setDevise($dto->devise);
        }
        if ($dto->timezone !== null) {
            $settings->setTimezone($dto->timezone);
        }
        if ($dto->dateFormat !== null) {
            $settings->setDateFormat($dto->dateFormat);
        }

        // RGPD
        if ($dto->cookiesConsent !== null) {
            $settings->setCookiesConsent($dto->cookiesConsent);
        }
        if ($dto->analyticsConsent !== null) {
            $settings->setAnalyticsConsent($dto->analyticsConsent);
        }
        if ($dto->marketingConsent !== null) {
            $settings->setMarketingConsent($dto->marketingConsent);
        }
        if ($dto->dataShareConsent !== null) {
            $settings->setDataShareConsent($dto->dataShareConsent);
        }

        // Sécurité
        if ($dto->twoFactorEnabled !== null) {
            $settings->setTwoFactorEnabled($dto->twoFactorEnabled);
        }
        if ($dto->loginNotifications !== null) {
            $settings->setLoginNotifications($dto->loginNotifications);
        }

        $this->entityManager->flush();

        return $settings;
    }

    /**
     * Réinitialiser les paramètres par défaut
     */
    public function resetToDefaults(User $user): UserSettings
    {
        $settings = $this->getUserSettings($user);

        // Réinitialiser aux valeurs par défaut
        $settings->setEmailNotificationsEnabled(true)
            ->setSmsNotificationsEnabled(true)
            ->setPushNotificationsEnabled(true)
            ->setNotifyOnNewMessage(true)
            ->setNotifyOnMatchingVoyage(true)
            ->setNotifyOnMatchingDemande(true)
            ->setNotifyOnNewAvis(true)
            ->setNotifyOnFavoriUpdate(true)
            ->setProfileVisibility('public')
            ->setShowPhone(true)
            ->setShowEmail(false)
            ->setShowStats(true)
            ->setMessagePermission('everyone')
            ->setShowInSearchResults(true)
            ->setShowLastSeen(true)
            ->setLangue('fr')
            ->setDevise('XAF')
            ->setTimezone('Africa/Douala')
            ->setDateFormat('dd/MM/yyyy')
            ->setTwoFactorEnabled(false)
            ->setLoginNotifications(true);

        $this->entityManager->flush();

        return $settings;
    }

    /**
     * Exporter les données utilisateur (RGPD)
     */
    public function exportUserData(User $user): array
    {
        $settings = $this->getUserSettings($user);

        return [
            'user' => [
                'id' => $user->getId(),
                'email' => $user->getEmail(),
                'nom' => $user->getNom(),
                'prenom' => $user->getPrenom(),
                'telephone' => $user->getTelephone(),
                'bio' => $user->getBio(),
                'emailVerifie' => $user->isEmailVerifie(),
                'telephoneVerifie' => $user->isTelephoneVerifie(),
                'authProvider' => $user->getAuthProvider(),
                'createdAt' => $user->getCreatedAt()?->format('Y-m-d H:i:s'),
            ],
            'settings' => [
                'notifications' => [
                    'email' => $settings->isEmailNotificationsEnabled(),
                    'sms' => $settings->isSmsNotificationsEnabled(),
                    'push' => $settings->isPushNotificationsEnabled(),
                    'newMessage' => $settings->isNotifyOnNewMessage(),
                    'matchingVoyage' => $settings->isNotifyOnMatchingVoyage(),
                    'matchingDemande' => $settings->isNotifyOnMatchingDemande(),
                    'newAvis' => $settings->isNotifyOnNewAvis(),
                    'favoriUpdate' => $settings->isNotifyOnFavoriUpdate(),
                ],
                'privacy' => [
                    'profileVisibility' => $settings->getProfileVisibility(),
                    'showPhone' => $settings->isShowPhone(),
                    'showEmail' => $settings->isShowEmail(),
                    'showStats' => $settings->isShowStats(),
                    'messagePermission' => $settings->getMessagePermission(),
                    'showInSearchResults' => $settings->isShowInSearchResults(),
                    'showLastSeen' => $settings->isShowLastSeen(),
                ],
                'preferences' => [
                    'langue' => $settings->getLangue(),
                    'devise' => $settings->getDevise(),
                    'timezone' => $settings->getTimezone(),
                    'dateFormat' => $settings->getDateFormat(),
                ],
                'rgpd' => [
                    'cookiesConsent' => $settings->isCookiesConsent(),
                    'analyticsConsent' => $settings->isAnalyticsConsent(),
                    'marketingConsent' => $settings->isMarketingConsent(),
                    'dataShareConsent' => $settings->isDataShareConsent(),
                    'consentDate' => $settings->getConsentDate()?->format('Y-m-d H:i:s'),
                ],
            ],
            'voyages' => $user->getVoyages()->count(),
            'demandes' => $user->getDemandes()->count(),
            'messages' => $user->getMessagesEnvoyes()->count() + $user->getMessagesRecus()->count(),
            'avis' => $user->getAvisRecus()->count(),
        ];
    }
}
