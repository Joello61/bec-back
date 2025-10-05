<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\UserSettingsRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Annotation\Groups;

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

    // ==================== NOTIFICATIONS ====================

    #[ORM\Column(type: Types::BOOLEAN)]
    #[Groups(['settings:read', 'settings:write'])]
    private bool $emailNotificationsEnabled = true;

    #[ORM\Column(type: Types::BOOLEAN)]
    #[Groups(['settings:read', 'settings:write'])]
    private bool $smsNotificationsEnabled = true;

    #[ORM\Column(type: Types::BOOLEAN)]
    #[Groups(['settings:read', 'settings:write'])]
    private bool $pushNotificationsEnabled = true;

    #[ORM\Column(type: Types::BOOLEAN)]
    #[Groups(['settings:read', 'settings:write'])]
    private bool $notifyOnNewMessage = true;

    #[ORM\Column(type: Types::BOOLEAN)]
    #[Groups(['settings:read', 'settings:write'])]
    private bool $notifyOnMatchingVoyage = true;

    #[ORM\Column(type: Types::BOOLEAN)]
    #[Groups(['settings:read', 'settings:write'])]
    private bool $notifyOnMatchingDemande = true;

    #[ORM\Column(type: Types::BOOLEAN)]
    #[Groups(['settings:read', 'settings:write'])]
    private bool $notifyOnNewAvis = true;

    #[ORM\Column(type: Types::BOOLEAN)]
    #[Groups(['settings:read', 'settings:write'])]
    private bool $notifyOnFavoriUpdate = true;

    // ==================== CONFIDENTIALITÉ ====================

    #[ORM\Column(type: Types::STRING, length: 20)]
    #[Groups(['settings:read', 'settings:write'])]
    private string $profileVisibility = 'public'; // 'public', 'verified_only', 'private'

    #[ORM\Column(type: Types::BOOLEAN)]
    #[Groups(['settings:read', 'settings:write'])]
    private bool $showPhone = true;

    #[ORM\Column(type: Types::BOOLEAN)]
    #[Groups(['settings:read', 'settings:write'])]
    private bool $showEmail = false;

    #[ORM\Column(type: Types::BOOLEAN)]
    #[Groups(['settings:read', 'settings:write'])]
    private bool $showStats = true;

    #[ORM\Column(type: Types::STRING, length: 20)]
    #[Groups(['settings:read', 'settings:write'])]
    private string $messagePermission = 'everyone'; // 'everyone', 'verified_only', 'no_one'

    #[ORM\Column(type: Types::BOOLEAN)]
    #[Groups(['settings:read', 'settings:write'])]
    private bool $showInSearchResults = true;

    #[ORM\Column(type: Types::BOOLEAN)]
    #[Groups(['settings:read', 'settings:write'])]
    private bool $showLastSeen = true;

    // ==================== PRÉFÉRENCES ====================

    #[ORM\Column(type: Types::STRING, length: 5)]
    #[Groups(['settings:read', 'settings:write'])]
    private string $langue = 'fr'; // 'fr', 'en'

    #[ORM\Column(type: Types::STRING, length: 3)]
    #[Groups(['settings:read', 'settings:write'])]
    private string $devise = 'XAF'; // 'XAF', 'EUR', 'USD'

    #[ORM\Column(type: Types::STRING, length: 50)]
    #[Groups(['settings:read', 'settings:write'])]
    private string $timezone = 'Africa/Douala';

    #[ORM\Column(type: Types::STRING, length: 10)]
    #[Groups(['settings:read', 'settings:write'])]
    private string $dateFormat = 'dd/MM/yyyy'; // 'dd/MM/yyyy', 'MM/dd/yyyy', 'yyyy-MM-dd'

    // ==================== RGPD ====================

    #[ORM\Column(type: Types::BOOLEAN)]
    #[Groups(['settings:read', 'settings:write'])]
    private bool $cookiesConsent = false;

    #[ORM\Column(type: Types::BOOLEAN)]
    #[Groups(['settings:read', 'settings:write'])]
    private bool $analyticsConsent = false;

    #[ORM\Column(type: Types::BOOLEAN)]
    #[Groups(['settings:read', 'settings:write'])]
    private bool $marketingConsent = false;

    #[ORM\Column(type: Types::BOOLEAN)]
    #[Groups(['settings:read', 'settings:write'])]
    private bool $dataShareConsent = false;

    #[ORM\Column(type: Types::DATETIME_MUTABLE, nullable: true)]
    #[Groups(['settings:read'])]
    private ?\DateTimeInterface $consentDate = null;

    // ==================== SÉCURITÉ ====================

    #[ORM\Column(type: Types::BOOLEAN)]
    #[Groups(['settings:read', 'settings:write'])]
    private bool $twoFactorEnabled = false;

    #[ORM\Column(type: Types::BOOLEAN)]
    #[Groups(['settings:read', 'settings:write'])]
    private bool $loginNotifications = true;

    // ==================== TIMESTAMPS ====================

    #[ORM\Column(type: Types::DATETIME_MUTABLE)]
    #[Groups(['settings:read'])]
    private ?\DateTimeInterface $createdAt = null;

    #[ORM\Column(type: Types::DATETIME_MUTABLE)]
    #[Groups(['settings:read'])]
    private ?\DateTimeInterface $updatedAt = null;

    public function __construct()
    {
        $this->createdAt = new \DateTime();
        $this->updatedAt = new \DateTime();
    }

    #[ORM\PreUpdate]
    public function setUpdatedAtValue(): void
    {
        $this->updatedAt = new \DateTime();
    }

    // ==================== GETTERS & SETTERS ====================

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

    // Notifications
    public function isEmailNotificationsEnabled(): bool
    {
        return $this->emailNotificationsEnabled;
    }

    public function setEmailNotificationsEnabled(bool $emailNotificationsEnabled): static
    {
        $this->emailNotificationsEnabled = $emailNotificationsEnabled;
        return $this;
    }

    public function isSmsNotificationsEnabled(): bool
    {
        return $this->smsNotificationsEnabled;
    }

    public function setSmsNotificationsEnabled(bool $smsNotificationsEnabled): static
    {
        $this->smsNotificationsEnabled = $smsNotificationsEnabled;
        return $this;
    }

    public function isPushNotificationsEnabled(): bool
    {
        return $this->pushNotificationsEnabled;
    }

    public function setPushNotificationsEnabled(bool $pushNotificationsEnabled): static
    {
        $this->pushNotificationsEnabled = $pushNotificationsEnabled;
        return $this;
    }

    public function isNotifyOnNewMessage(): bool
    {
        return $this->notifyOnNewMessage;
    }

    public function setNotifyOnNewMessage(bool $notifyOnNewMessage): static
    {
        $this->notifyOnNewMessage = $notifyOnNewMessage;
        return $this;
    }

    public function isNotifyOnMatchingVoyage(): bool
    {
        return $this->notifyOnMatchingVoyage;
    }

    public function setNotifyOnMatchingVoyage(bool $notifyOnMatchingVoyage): static
    {
        $this->notifyOnMatchingVoyage = $notifyOnMatchingVoyage;
        return $this;
    }

    public function isNotifyOnMatchingDemande(): bool
    {
        return $this->notifyOnMatchingDemande;
    }

    public function setNotifyOnMatchingDemande(bool $notifyOnMatchingDemande): static
    {
        $this->notifyOnMatchingDemande = $notifyOnMatchingDemande;
        return $this;
    }

    public function isNotifyOnNewAvis(): bool
    {
        return $this->notifyOnNewAvis;
    }

    public function setNotifyOnNewAvis(bool $notifyOnNewAvis): static
    {
        $this->notifyOnNewAvis = $notifyOnNewAvis;
        return $this;
    }

    public function isNotifyOnFavoriUpdate(): bool
    {
        return $this->notifyOnFavoriUpdate;
    }

    public function setNotifyOnFavoriUpdate(bool $notifyOnFavoriUpdate): static
    {
        $this->notifyOnFavoriUpdate = $notifyOnFavoriUpdate;
        return $this;
    }

    // Confidentialité
    public function getProfileVisibility(): string
    {
        return $this->profileVisibility;
    }

    public function setProfileVisibility(string $profileVisibility): static
    {
        $this->profileVisibility = $profileVisibility;
        return $this;
    }

    public function isShowPhone(): bool
    {
        return $this->showPhone;
    }

    public function setShowPhone(bool $showPhone): static
    {
        $this->showPhone = $showPhone;
        return $this;
    }

    public function isShowEmail(): bool
    {
        return $this->showEmail;
    }

    public function setShowEmail(bool $showEmail): static
    {
        $this->showEmail = $showEmail;
        return $this;
    }

    public function isShowStats(): bool
    {
        return $this->showStats;
    }

    public function setShowStats(bool $showStats): static
    {
        $this->showStats = $showStats;
        return $this;
    }

    public function getMessagePermission(): string
    {
        return $this->messagePermission;
    }

    public function setMessagePermission(string $messagePermission): static
    {
        $this->messagePermission = $messagePermission;
        return $this;
    }

    public function isShowInSearchResults(): bool
    {
        return $this->showInSearchResults;
    }

    public function setShowInSearchResults(bool $showInSearchResults): static
    {
        $this->showInSearchResults = $showInSearchResults;
        return $this;
    }

    public function isShowLastSeen(): bool
    {
        return $this->showLastSeen;
    }

    public function setShowLastSeen(bool $showLastSeen): static
    {
        $this->showLastSeen = $showLastSeen;
        return $this;
    }

    // Préférences
    public function getLangue(): string
    {
        return $this->langue;
    }

    public function setLangue(string $langue): static
    {
        $this->langue = $langue;
        return $this;
    }

    public function getDevise(): string
    {
        return $this->devise;
    }

    public function setDevise(string $devise): static
    {
        $this->devise = $devise;
        return $this;
    }

    public function getTimezone(): string
    {
        return $this->timezone;
    }

    public function setTimezone(string $timezone): static
    {
        $this->timezone = $timezone;
        return $this;
    }

    public function getDateFormat(): string
    {
        return $this->dateFormat;
    }

    public function setDateFormat(string $dateFormat): static
    {
        $this->dateFormat = $dateFormat;
        return $this;
    }

    // RGPD
    public function isCookiesConsent(): bool
    {
        return $this->cookiesConsent;
    }

    public function setCookiesConsent(bool $cookiesConsent): static
    {
        $this->cookiesConsent = $cookiesConsent;
        if ($cookiesConsent && !$this->consentDate) {
            $this->consentDate = new \DateTime();
        }
        return $this;
    }

    public function isAnalyticsConsent(): bool
    {
        return $this->analyticsConsent;
    }

    public function setAnalyticsConsent(bool $analyticsConsent): static
    {
        $this->analyticsConsent = $analyticsConsent;
        return $this;
    }

    public function isMarketingConsent(): bool
    {
        return $this->marketingConsent;
    }

    public function setMarketingConsent(bool $marketingConsent): static
    {
        $this->marketingConsent = $marketingConsent;
        return $this;
    }

    public function isDataShareConsent(): bool
    {
        return $this->dataShareConsent;
    }

    public function setDataShareConsent(bool $dataShareConsent): static
    {
        $this->dataShareConsent = $dataShareConsent;
        return $this;
    }

    public function getConsentDate(): ?\DateTimeInterface
    {
        return $this->consentDate;
    }

    // Sécurité
    public function isTwoFactorEnabled(): bool
    {
        return $this->twoFactorEnabled;
    }

    public function setTwoFactorEnabled(bool $twoFactorEnabled): static
    {
        $this->twoFactorEnabled = $twoFactorEnabled;
        return $this;
    }

    public function isLoginNotifications(): bool
    {
        return $this->loginNotifications;
    }

    public function setLoginNotifications(bool $loginNotifications): static
    {
        $this->loginNotifications = $loginNotifications;
        return $this;
    }

    // Timestamps
    public function getCreatedAt(): ?\DateTimeInterface
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): ?\DateTimeInterface
    {
        return $this->updatedAt;
    }

    // ==================== HELPER METHODS ====================

    /**
     * Vérifie si l'utilisateur accepte les emails
     */
    public function canReceiveEmails(): bool
    {
        return $this->emailNotificationsEnabled;
    }

    /**
     * Vérifie si l'utilisateur accepte les SMS
     */
    public function canReceiveSms(): bool
    {
        return $this->smsNotificationsEnabled;
    }

    /**
     * Vérifie si l'utilisateur accepte les notifications push
     */
    public function canReceivePushNotifications(): bool
    {
        return $this->pushNotificationsEnabled;
    }

    /**
     * Vérifie si un utilisateur peut envoyer un message
     */
    public function canReceiveMessageFrom(User $sender): bool
    {
        if ($this->messagePermission === 'no_one') {
            return false;
        }

        if ($this->messagePermission === 'verified_only') {
            return $sender->isEmailVerifie() && $sender->isTelephoneVerifie();
        }

        return true; // everyone
    }

    /**
     * Vérifie si le profil est visible pour un utilisateur donné
     */
    public function isProfileVisibleFor(?User $viewer): bool
    {
        if ($this->profileVisibility === 'public') {
            return true;
        }

        if (!$viewer) {
            return false;
        }

        if ($this->profileVisibility === 'verified_only') {
            return $viewer->isEmailVerifie() && $viewer->isTelephoneVerifie();
        }

        return false; // private
    }
}
