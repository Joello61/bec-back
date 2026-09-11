<?php

declare(strict_types=1);

namespace App\DTO;

use Symfony\Component\Validator\Constraints as Assert;

class UpdateSettingsDTO
{
    // ==================== NOTIFICATIONS ====================

    #[Assert\Type('bool')]
    public ?bool $emailNotificationsEnabled = null;

    #[Assert\Type('bool')]
    public ?bool $smsNotificationsEnabled = null;

    #[Assert\Type('bool')]
    public ?bool $pushNotificationsEnabled = null;

    #[Assert\Type('bool')]
    public ?bool $notifyOnNewMessage = null;

    #[Assert\Type('bool')]
    public ?bool $notifyOnMatchingVoyage = null;

    #[Assert\Type('bool')]
    public ?bool $notifyOnMatchingDemande = null;

    #[Assert\Type('bool')]
    public ?bool $notifyOnNewAvis = null;

    #[Assert\Type('bool')]
    public ?bool $notifyOnFavoriUpdate = null;

    // ==================== CONFIDENTIALITÉ ====================

    #[Assert\Choice(
        choices: ['public', 'verified_only', 'private'],
        message: 'La visibilité du profil doit être: public, verified_only ou private'
    )]
    public ?string $profileVisibility = null;

    #[Assert\Type('bool')]
    public ?bool $showPhone = null;

    #[Assert\Type('bool')]
    public ?bool $showEmail = null;

    #[Assert\Type('bool')]
    public ?bool $showStats = null;

    #[Assert\Choice(
        choices: ['everyone', 'verified_only', 'no_one'],
        message: 'Les permissions de message doivent être: everyone, verified_only ou no_one'
    )]
    public ?string $messagePermission = null;

    #[Assert\Type('bool')]
    public ?bool $showInSearchResults = null;

    #[Assert\Type('bool')]
    public ?bool $showLastSeen = null;

    // ==================== PRÉFÉRENCES ====================

    #[Assert\Choice(
        choices: ['fr', 'en'],
        message: 'La langue doit être: fr ou en'
    )]
    public ?string $langue = null;

    #[Assert\Choice(
        choices: ['XAF', 'EUR', 'USD'],
        message: 'La devise doit être: XAF, EUR ou USD'
    )]
    public ?string $devise = null;

    #[Assert\Timezone]
    public ?string $timezone = null;

    #[Assert\Choice(
        choices: ['dd/MM/yyyy', 'MM/dd/yyyy', 'yyyy-MM-dd'],
        message: 'Le format de date doit être: dd/MM/yyyy, MM/dd/yyyy ou yyyy-MM-dd'
    )]
    public ?string $dateFormat = null;

    // ==================== RGPD ====================

    #[Assert\Type('bool')]
    public ?bool $cookiesConsent = null;

    #[Assert\Type('bool')]
    public ?bool $analyticsConsent = null;

    #[Assert\Type('bool')]
    public ?bool $marketingConsent = null;

    #[Assert\Type('bool')]
    public ?bool $dataShareConsent = null;

    // ==================== SÉCURITÉ ====================

    #[Assert\Type('bool')]
    public ?bool $twoFactorEnabled = null;

    #[Assert\Type('bool')]
    public ?bool $loginNotifications = null;
}
