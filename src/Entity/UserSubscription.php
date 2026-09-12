<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\UserSubscriptionRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Annotation\Groups;

#[ORM\Entity(repositoryClass: UserSubscriptionRepository::class)]
#[ORM\Table(name: 'user_subscriptions')]
#[ORM\HasLifecycleCallbacks]
class UserSubscription
{
    public const STATUS_INCOMPLETE = 'incomplete';
    public const STATUS_ACTIVE = 'active';
    public const STATUS_PAST_DUE = 'past_due';
    public const STATUS_CANCELED = 'canceled';
    public const STATUS_EXPIRED = 'expired';

    public const PROVIDER_STRIPE = 'stripe';
    public const PROVIDER_NOTCHPAY = 'notchpay';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    #[Groups(['user_subscription:read'])]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false)]
    private ?User $user = null;

    #[ORM\ManyToOne(targetEntity: SubscriptionPlan::class)]
    #[ORM\JoinColumn(nullable: false)]
    #[Groups(['user_subscription:read'])]
    private ?SubscriptionPlan $plan = null;

    #[ORM\Column(length: 20)]
    #[Groups(['user_subscription:read'])]
    private string $status = self::STATUS_INCOMPLETE;

    #[ORM\Column(length: 20)]
    #[Groups(['user_subscription:read'])]
    private string $provider = self::PROVIDER_STRIPE;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $providerCustomerId = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $providerSubscriptionId = null;

    #[ORM\Column(type: Types::DATETIME_MUTABLE, nullable: true)]
    #[Groups(['user_subscription:read'])]
    private ?\DateTimeInterface $currentPeriodStart = null;

    #[ORM\Column(type: Types::DATETIME_MUTABLE, nullable: true)]
    #[Groups(['user_subscription:read'])]
    private ?\DateTimeInterface $currentPeriodEnd = null;

    #[ORM\Column(type: Types::BOOLEAN)]
    #[Groups(['user_subscription:read'])]
    private bool $cancelAtPeriodEnd = false;

    /**
     * Montant figé au moment du checkout, copié depuis SubscriptionPlan - jamais
     * recalculé (CurrencyService::convert() n'intervient jamais sur ce montant).
     */
    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 2)]
    #[Groups(['user_subscription:read'])]
    private ?string $amount = null;

    #[ORM\Column(length: 3)]
    #[Groups(['user_subscription:read'])]
    private ?string $currency = null;

    /**
     * Horodatage du double consentement (accès immédiat + renonciation expresse au
     * droit de rétractation) au moment du checkout - cf. art. L.221-28 13° Code conso.
     */
    #[ORM\Column(type: Types::DATETIME_MUTABLE, nullable: true)]
    private ?\DateTimeInterface $withdrawalWaiverConsentedAt = null;

    /**
     * Date d'envoi du dernier rappel de renouvellement (Mobile Money uniquement, Lot 3 -
     * Notch Pay n'a pas de récurrence native, cf. SendRenewalReminderHandler).
     */
    #[ORM\Column(type: Types::DATETIME_MUTABLE, nullable: true)]
    private ?\DateTimeInterface $renewalReminderSentAt = null;

    #[ORM\Column(type: Types::DATETIME_MUTABLE)]
    private ?\DateTimeInterface $createdAt = null;

    #[ORM\Column(type: Types::DATETIME_MUTABLE)]
    private ?\DateTimeInterface $updatedAt = null;

    #[ORM\PrePersist]
    public function setCreatedAtValue(): void
    {
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

    public function setUser(?User $user): static
    {
        $this->user = $user;
        return $this;
    }

    public function getPlan(): ?SubscriptionPlan
    {
        return $this->plan;
    }

    public function setPlan(?SubscriptionPlan $plan): static
    {
        $this->plan = $plan;
        return $this;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function setStatus(string $status): static
    {
        $this->status = $status;
        return $this;
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }

    public function getProvider(): string
    {
        return $this->provider;
    }

    public function setProvider(string $provider): static
    {
        $this->provider = $provider;
        return $this;
    }

    public function getProviderCustomerId(): ?string
    {
        return $this->providerCustomerId;
    }

    public function setProviderCustomerId(?string $providerCustomerId): static
    {
        $this->providerCustomerId = $providerCustomerId;
        return $this;
    }

    public function getProviderSubscriptionId(): ?string
    {
        return $this->providerSubscriptionId;
    }

    public function setProviderSubscriptionId(?string $providerSubscriptionId): static
    {
        $this->providerSubscriptionId = $providerSubscriptionId;
        return $this;
    }

    public function getCurrentPeriodStart(): ?\DateTimeInterface
    {
        return $this->currentPeriodStart;
    }

    public function setCurrentPeriodStart(?\DateTimeInterface $currentPeriodStart): static
    {
        $this->currentPeriodStart = $currentPeriodStart;
        return $this;
    }

    public function getCurrentPeriodEnd(): ?\DateTimeInterface
    {
        return $this->currentPeriodEnd;
    }

    public function setCurrentPeriodEnd(?\DateTimeInterface $currentPeriodEnd): static
    {
        $this->currentPeriodEnd = $currentPeriodEnd;
        return $this;
    }

    public function isCancelAtPeriodEnd(): bool
    {
        return $this->cancelAtPeriodEnd;
    }

    public function setCancelAtPeriodEnd(bool $cancelAtPeriodEnd): static
    {
        $this->cancelAtPeriodEnd = $cancelAtPeriodEnd;
        return $this;
    }

    public function getAmount(): ?string
    {
        return $this->amount;
    }

    public function setAmount(string $amount): static
    {
        $this->amount = $amount;
        return $this;
    }

    public function getCurrency(): ?string
    {
        return $this->currency;
    }

    public function setCurrency(string $currency): static
    {
        $this->currency = $currency;
        return $this;
    }

    public function getWithdrawalWaiverConsentedAt(): ?\DateTimeInterface
    {
        return $this->withdrawalWaiverConsentedAt;
    }

    public function setWithdrawalWaiverConsentedAt(?\DateTimeInterface $withdrawalWaiverConsentedAt): static
    {
        $this->withdrawalWaiverConsentedAt = $withdrawalWaiverConsentedAt;
        return $this;
    }

    public function getRenewalReminderSentAt(): ?\DateTimeInterface
    {
        return $this->renewalReminderSentAt;
    }

    public function setRenewalReminderSentAt(?\DateTimeInterface $renewalReminderSentAt): static
    {
        $this->renewalReminderSentAt = $renewalReminderSentAt;
        return $this;
    }

    public function getCreatedAt(): ?\DateTimeInterface
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): ?\DateTimeInterface
    {
        return $this->updatedAt;
    }
}
