<?php

declare(strict_types=1);

namespace App\Entity;

use App\Entity\Settings\NotificationPreferences;
use App\Entity\Settings\PrivacySettings;
use App\Entity\Settings\RgpdConsent;
use App\Entity\Settings\SecuritySettings;
use App\Entity\Settings\UserPreferences;
use App\Repository\UserSettingsRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Annotation\Groups;

/**
 * Regroupe 5 embeddables Doctrine par concept (notifications, confidentialite,
 * preferences, RGPD, securite) - decoupage purement interne (audit
 * Backend-Qualite #8, Phase 6). Le contrat JSON reste plat (aucun groupe sur
 * les embeddables eux-memes, uniquement sur les methodes deleguantes
 * ci-dessous) : aucun changement pour les consommateurs de l'API ni pour le
 * schema SQL (columnPrefix: false, memes noms de colonnes qu'avant).
 */
#[ORM\Entity(repositoryClass: UserSettingsRepository::class)]
#[ORM\Table(name: 'user_settings')]
#[ORM\HasLifecycleCallbacks]
class UserSettings
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    #[Groups(['settings:read'])]
    private ?int $id = null;

    #[ORM\OneToOne(targetEntity: User::class, inversedBy: 'settings')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?User $user = null;

    #[ORM\Embedded(class: NotificationPreferences::class, columnPrefix: false)]
    private NotificationPreferences $notifications;

    #[ORM\Embedded(class: PrivacySettings::class, columnPrefix: false)]
    private PrivacySettings $privacy;

    #[ORM\Embedded(class: UserPreferences::class, columnPrefix: false)]
    private UserPreferences $preferences;

    #[ORM\Embedded(class: RgpdConsent::class, columnPrefix: false)]
    private RgpdConsent $rgpdConsent;

    #[ORM\Embedded(class: SecuritySettings::class, columnPrefix: false)]
    private SecuritySettings $security;

    // ==================== TIMESTAMPS ====================

    #[ORM\Column(type: Types::DATETIME_MUTABLE)]
    #[Groups(['settings:read'])]
    private ?\DateTimeInterface $createdAt = null;

    #[ORM\Column(type: Types::DATETIME_MUTABLE)]
    #[Groups(['settings:read'])]
    private ?\DateTimeInterface $updatedAt = null;

    public function __construct()
    {
        $this->notifications = new NotificationPreferences();
        $this->privacy = new PrivacySettings();
        $this->preferences = new UserPreferences();
        $this->rgpdConsent = new RgpdConsent();
        $this->security = new SecuritySettings();
        $this->createdAt = new \DateTime();
        $this->updatedAt = new \DateTime();
    }

    #[ORM\PreUpdate]
    public function setUpdatedAtValue(): void
    {
        $this->updatedAt = new \DateTime();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUser(): ?User
    {
        return $this->user;
    }

    public function setUser(User $user): static
    {
        $this->user = $user;
        return $this;
    }

    // ==================== NOTIFICATIONS (delegation vers NotificationPreferences) ====================

    #[Groups(['settings:read', 'settings:write'])]
    public function isEmailNotificationsEnabled(): bool
    {
        return $this->notifications->isEmailNotificationsEnabled();
    }

    public function setEmailNotificationsEnabled(bool $emailNotificationsEnabled): static
    {
        $this->notifications->setEmailNotificationsEnabled($emailNotificationsEnabled);
        return $this;
    }

    #[Groups(['settings:read', 'settings:write'])]
    public function isSmsNotificationsEnabled(): bool
    {
        return $this->notifications->isSmsNotificationsEnabled();
    }

    public function setSmsNotificationsEnabled(bool $smsNotificationsEnabled): static
    {
        $this->notifications->setSmsNotificationsEnabled($smsNotificationsEnabled);
        return $this;
    }

    #[Groups(['settings:read', 'settings:write'])]
    public function isPushNotificationsEnabled(): bool
    {
        return $this->notifications->isPushNotificationsEnabled();
    }

    public function setPushNotificationsEnabled(bool $pushNotificationsEnabled): static
    {
        $this->notifications->setPushNotificationsEnabled($pushNotificationsEnabled);
        return $this;
    }

    #[Groups(['settings:read', 'settings:write'])]
    public function isNotifyOnNewMessage(): bool
    {
        return $this->notifications->isNotifyOnNewMessage();
    }

    public function setNotifyOnNewMessage(bool $notifyOnNewMessage): static
    {
        $this->notifications->setNotifyOnNewMessage($notifyOnNewMessage);
        return $this;
    }

    #[Groups(['settings:read', 'settings:write'])]
    public function isNotifyOnMatchingVoyage(): bool
    {
        return $this->notifications->isNotifyOnMatchingVoyage();
    }

    public function setNotifyOnMatchingVoyage(bool $notifyOnMatchingVoyage): static
    {
        $this->notifications->setNotifyOnMatchingVoyage($notifyOnMatchingVoyage);
        return $this;
    }

    #[Groups(['settings:read', 'settings:write'])]
    public function isNotifyOnMatchingDemande(): bool
    {
        return $this->notifications->isNotifyOnMatchingDemande();
    }

    public function setNotifyOnMatchingDemande(bool $notifyOnMatchingDemande): static
    {
        $this->notifications->setNotifyOnMatchingDemande($notifyOnMatchingDemande);
        return $this;
    }

    #[Groups(['settings:read', 'settings:write'])]
    public function isNotifyOnNewAvis(): bool
    {
        return $this->notifications->isNotifyOnNewAvis();
    }

    public function setNotifyOnNewAvis(bool $notifyOnNewAvis): static
    {
        $this->notifications->setNotifyOnNewAvis($notifyOnNewAvis);
        return $this;
    }

    #[Groups(['settings:read', 'settings:write'])]
    public function isNotifyOnFavoriUpdate(): bool
    {
        return $this->notifications->isNotifyOnFavoriUpdate();
    }

    public function setNotifyOnFavoriUpdate(bool $notifyOnFavoriUpdate): static
    {
        $this->notifications->setNotifyOnFavoriUpdate($notifyOnFavoriUpdate);
        return $this;
    }

    // ==================== CONFIDENTIALITÉ (delegation vers PrivacySettings) ====================

    #[Groups(['settings:read', 'settings:write'])]
    public function getProfileVisibility(): string
    {
        return $this->privacy->getProfileVisibility();
    }

    public function setProfileVisibility(string $profileVisibility): static
    {
        $this->privacy->setProfileVisibility($profileVisibility);
        return $this;
    }

    #[Groups(['settings:read', 'settings:write'])]
    public function isShowPhone(): bool
    {
        return $this->privacy->isShowPhone();
    }

    public function setShowPhone(bool $showPhone): static
    {
        $this->privacy->setShowPhone($showPhone);
        return $this;
    }

    #[Groups(['settings:read', 'settings:write'])]
    public function isShowEmail(): bool
    {
        return $this->privacy->isShowEmail();
    }

    public function setShowEmail(bool $showEmail): static
    {
        $this->privacy->setShowEmail($showEmail);
        return $this;
    }

    #[Groups(['settings:read', 'settings:write'])]
    public function isShowStats(): bool
    {
        return $this->privacy->isShowStats();
    }

    public function setShowStats(bool $showStats): static
    {
        $this->privacy->setShowStats($showStats);
        return $this;
    }

    #[Groups(['settings:read', 'settings:write'])]
    public function getMessagePermission(): string
    {
        return $this->privacy->getMessagePermission();
    }

    public function setMessagePermission(string $messagePermission): static
    {
        $this->privacy->setMessagePermission($messagePermission);
        return $this;
    }

    #[Groups(['settings:read', 'settings:write'])]
    public function isShowInSearchResults(): bool
    {
        return $this->privacy->isShowInSearchResults();
    }

    public function setShowInSearchResults(bool $showInSearchResults): static
    {
        $this->privacy->setShowInSearchResults($showInSearchResults);
        return $this;
    }

    #[Groups(['settings:read', 'settings:write'])]
    public function isShowLastSeen(): bool
    {
        return $this->privacy->isShowLastSeen();
    }

    public function setShowLastSeen(bool $showLastSeen): static
    {
        $this->privacy->setShowLastSeen($showLastSeen);
        return $this;
    }

    // ==================== PRÉFÉRENCES (delegation vers UserPreferences) ====================

    #[Groups(['settings:read', 'settings:write'])]
    public function getLangue(): string
    {
        return $this->preferences->getLangue();
    }

    public function setLangue(string $langue): static
    {
        $this->preferences->setLangue($langue);
        return $this;
    }

    #[Groups(['settings:read', 'settings:write'])]
    public function getDevise(): string
    {
        return $this->preferences->getDevise();
    }

    public function setDevise(string $devise): static
    {
        $this->preferences->setDevise($devise);
        return $this;
    }

    #[Groups(['settings:read', 'settings:write'])]
    public function getTimezone(): string
    {
        return $this->preferences->getTimezone();
    }

    public function setTimezone(string $timezone): static
    {
        $this->preferences->setTimezone($timezone);
        return $this;
    }

    #[Groups(['settings:read', 'settings:write'])]
    public function getDateFormat(): string
    {
        return $this->preferences->getDateFormat();
    }

    public function setDateFormat(string $dateFormat): static
    {
        $this->preferences->setDateFormat($dateFormat);
        return $this;
    }

    // ==================== RGPD (delegation vers RgpdConsent) ====================

    #[Groups(['settings:read', 'settings:write'])]
    public function isCookiesConsent(): bool
    {
        return $this->rgpdConsent->isCookiesConsent();
    }

    public function setCookiesConsent(bool $cookiesConsent): static
    {
        $this->rgpdConsent->setCookiesConsent($cookiesConsent);
        return $this;
    }

    #[Groups(['settings:read', 'settings:write'])]
    public function isAnalyticsConsent(): bool
    {
        return $this->rgpdConsent->isAnalyticsConsent();
    }

    public function setAnalyticsConsent(bool $analyticsConsent): static
    {
        $this->rgpdConsent->setAnalyticsConsent($analyticsConsent);
        return $this;
    }

    #[Groups(['settings:read', 'settings:write'])]
    public function isMarketingConsent(): bool
    {
        return $this->rgpdConsent->isMarketingConsent();
    }

    public function setMarketingConsent(bool $marketingConsent): static
    {
        $this->rgpdConsent->setMarketingConsent($marketingConsent);
        return $this;
    }

    #[Groups(['settings:read', 'settings:write'])]
    public function isDataShareConsent(): bool
    {
        return $this->rgpdConsent->isDataShareConsent();
    }

    public function setDataShareConsent(bool $dataShareConsent): static
    {
        $this->rgpdConsent->setDataShareConsent($dataShareConsent);
        return $this;
    }

    #[Groups(['settings:read'])]
    public function getConsentDate(): ?\DateTimeInterface
    {
        return $this->rgpdConsent->getConsentDate();
    }

    // ==================== SÉCURITÉ (delegation vers SecuritySettings) ====================

    #[Groups(['settings:read', 'settings:write'])]
    public function isTwoFactorEnabled(): bool
    {
        return $this->security->isTwoFactorEnabled();
    }

    public function setTwoFactorEnabled(bool $twoFactorEnabled): static
    {
        $this->security->setTwoFactorEnabled($twoFactorEnabled);
        return $this;
    }

    #[Groups(['settings:read', 'settings:write'])]
    public function isLoginNotifications(): bool
    {
        return $this->security->isLoginNotifications();
    }

    public function setLoginNotifications(bool $loginNotifications): static
    {
        $this->security->setLoginNotifications($loginNotifications);
        return $this;
    }

    // ==================== TIMESTAMPS ====================

    public function getCreatedAt(): ?\DateTimeInterface
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): ?\DateTimeInterface
    {
        return $this->updatedAt;
    }

    // ==================== HELPER METHODS (delegation) ====================

    public function canReceiveEmails(): bool
    {
        return $this->notifications->canReceiveEmails();
    }

    public function canReceiveSms(): bool
    {
        return $this->notifications->canReceiveSms();
    }

    public function canReceivePushNotifications(): bool
    {
        return $this->notifications->canReceivePushNotifications();
    }

    public function canReceiveMessageFrom(User $sender): bool
    {
        return $this->privacy->canReceiveMessageFrom($sender);
    }

    public function isProfileVisibleFor(?User $viewer): bool
    {
        return $this->privacy->isProfileVisibleFor($viewer);
    }
}
