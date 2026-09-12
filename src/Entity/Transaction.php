<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\TransactionRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Annotation\Groups;

/**
 * Table pivot indépendante du provider - point d'idempotence pour les webhooks de
 * paiement (UNIQUE(provider, provider_payment_id), cf. PaymentService).
 */
#[ORM\Entity(repositoryClass: TransactionRepository::class)]
#[ORM\Table(name: 'transactions')]
#[ORM\UniqueConstraint(name: 'uniq_provider_payment_id', columns: ['provider', 'provider_payment_id'])]
#[ORM\HasLifecycleCallbacks]
class Transaction
{
    public const TYPE_SUBSCRIPTION_INITIAL = 'subscription_initial';
    public const TYPE_SUBSCRIPTION_RENEWAL = 'subscription_renewal';
    public const TYPE_BOOST = 'boost';

    public const STATUS_PENDING = 'pending';
    public const STATUS_SUCCEEDED = 'succeeded';
    public const STATUS_FAILED = 'failed';
    public const STATUS_CANCELED = 'canceled';
    public const STATUS_REFUNDED = 'refunded';

    public const METHOD_FAMILY_CARD = 'card';
    public const METHOD_FAMILY_MOBILE_MONEY = 'mobile_money';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    #[Groups(['transaction:read', 'admin:transaction:list'])]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false)]
    #[Groups(['admin:transaction:list'])]
    private ?User $user = null;

    #[ORM\ManyToOne(targetEntity: UserSubscription::class)]
    #[ORM\JoinColumn(nullable: true)]
    private ?UserSubscription $subscription = null;

    #[ORM\ManyToOne(targetEntity: Boost::class)]
    #[ORM\JoinColumn(nullable: true)]
    private ?Boost $boost = null;

    #[ORM\Column(length: 30)]
    #[Groups(['transaction:read', 'admin:transaction:list'])]
    private ?string $type = null;

    #[ORM\Column(length: 20)]
    #[Groups(['transaction:read', 'admin:transaction:list'])]
    private ?string $provider = null;

    #[ORM\Column(length: 255)]
    private ?string $providerPaymentId = null;

    #[ORM\Column(length: 20)]
    #[Groups(['transaction:read', 'admin:transaction:list'])]
    private ?string $paymentMethodFamily = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 2)]
    #[Groups(['transaction:read', 'admin:transaction:list'])]
    private ?string $amount = null;

    #[ORM\Column(length: 3)]
    #[Groups(['transaction:read', 'admin:transaction:list'])]
    private ?string $currency = null;

    #[ORM\Column(length: 20)]
    #[Groups(['transaction:read', 'admin:transaction:list'])]
    private string $status = self::STATUS_PENDING;

    /**
     * Date d'exécution du remboursement (Lot 6.1) - jamais exposée cote utilisateur,
     * uniquement à l'admin. Raison et admin responsable tracés via AuditLogService,
     * jamais dupliqués ici.
     */
    #[ORM\Column(type: Types::DATETIME_MUTABLE, nullable: true)]
    #[Groups(['admin:transaction:list'])]
    private ?\DateTimeInterface $refundedAt = null;

    /**
     * Métadonnées provider uniquement (id, type d'événement, statut...) - jamais de
     * PAN/CVV ni de numéro de téléphone Mobile Money complet.
     */
    /** @var array<string, mixed>|null */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $rawPayload = null;

    #[ORM\Column(type: Types::DATETIME_MUTABLE)]
    #[Groups(['transaction:read', 'admin:transaction:list'])]
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

    public function getSubscription(): ?UserSubscription
    {
        return $this->subscription;
    }

    public function setSubscription(?UserSubscription $subscription): static
    {
        $this->subscription = $subscription;
        return $this;
    }

    public function getBoost(): ?Boost
    {
        return $this->boost;
    }

    public function setBoost(?Boost $boost): static
    {
        $this->boost = $boost;
        return $this;
    }

    public function getType(): ?string
    {
        return $this->type;
    }

    public function setType(string $type): static
    {
        $this->type = $type;
        return $this;
    }

    public function getProvider(): ?string
    {
        return $this->provider;
    }

    public function setProvider(string $provider): static
    {
        $this->provider = $provider;
        return $this;
    }

    public function getProviderPaymentId(): ?string
    {
        return $this->providerPaymentId;
    }

    public function setProviderPaymentId(string $providerPaymentId): static
    {
        $this->providerPaymentId = $providerPaymentId;
        return $this;
    }

    public function getPaymentMethodFamily(): ?string
    {
        return $this->paymentMethodFamily;
    }

    public function setPaymentMethodFamily(string $paymentMethodFamily): static
    {
        $this->paymentMethodFamily = $paymentMethodFamily;
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

    public function getStatus(): string
    {
        return $this->status;
    }

    public function setStatus(string $status): static
    {
        $this->status = $status;
        return $this;
    }

    public function getRefundedAt(): ?\DateTimeInterface
    {
        return $this->refundedAt;
    }

    public function setRefundedAt(?\DateTimeInterface $refundedAt): static
    {
        $this->refundedAt = $refundedAt;
        return $this;
    }

    /** @return array<string, mixed>|null */
    public function getRawPayload(): ?array
    {
        return $this->rawPayload;
    }

    /** @param array<string, mixed>|null $rawPayload */
    public function setRawPayload(?array $rawPayload): static
    {
        $this->rawPayload = $rawPayload;
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
