<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\SubscriptionPlanRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Annotation\Groups;

#[ORM\Entity(repositoryClass: SubscriptionPlanRepository::class)]
#[ORM\Table(name: 'subscription_plans')]
#[ORM\HasLifecycleCallbacks]
class SubscriptionPlan
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    #[Groups(['subscription_plan:read', 'subscription_plan:list', 'admin:subscription_plan:read'])]
    private ?int $id = null;

    /**
     * Identifiant technique du palier (free, plus, pro)
     */
    #[ORM\Column(length: 30, unique: true)]
    #[Groups(['subscription_plan:read', 'subscription_plan:list', 'admin:subscription_plan:read'])]
    private ?string $code = null;

    #[ORM\Column(length: 100)]
    #[Groups(['subscription_plan:read', 'subscription_plan:list', 'admin:subscription_plan:read'])]
    private ?string $name = null;

    /**
     * Prix en euros (paiement carte). Null pour le palier gratuit.
     */
    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 2, nullable: true)]
    #[Groups(['subscription_plan:read', 'subscription_plan:list', 'admin:subscription_plan:read'])]
    private ?string $priceAmountEur = null;

    /**
     * Prix en XAF (paiement Mobile Money, Lot 3). Null pour le palier gratuit ou tant
     * que le tarif Mobile Money n'a pas été fixé pour ce plan.
     */
    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 2, nullable: true)]
    #[Groups(['subscription_plan:read', 'subscription_plan:list', 'admin:subscription_plan:read'])]
    private ?string $priceAmountXaf = null;

    /**
     * Périodicité de facturation (mensuel uniquement en MVP, pas de plan annuel)
     */
    #[ORM\Column(length: 20)]
    #[Groups(['subscription_plan:read', 'subscription_plan:list', 'admin:subscription_plan:read'])]
    private string $billingPeriod = 'monthly';

    /**
     * Nombre maximum de voyages actifs simultanés. Null = illimité.
     */
    #[ORM\Column(type: Types::INTEGER, nullable: true)]
    #[Groups(['subscription_plan:read', 'subscription_plan:list', 'admin:subscription_plan:read'])]
    private ?int $maxActiveVoyages = null;

    /**
     * Nombre maximum de demandes actives simultanées. Null = illimité.
     */
    #[ORM\Column(type: Types::INTEGER, nullable: true)]
    #[Groups(['subscription_plan:read', 'subscription_plan:list', 'admin:subscription_plan:read'])]
    private ?int $maxActiveDemandes = null;

    #[ORM\Column(type: Types::BOOLEAN)]
    #[Groups(['subscription_plan:read', 'subscription_plan:list', 'admin:subscription_plan:read'])]
    private bool $hasBadge = false;

    /**
     * Mise en avant visuelle sur la page tarifs (Lot 5), distincte de isActive
     * ("proposé à la souscription") et de hasBadge ("Populaire").
     */
    #[ORM\Column(type: Types::BOOLEAN)]
    #[Groups(['subscription_plan:read', 'subscription_plan:list', 'admin:subscription_plan:read'])]
    private bool $isFeatured = false;

    /**
     * Identifiant du Price Stripe correspondant (mode subscription). Null tant que non
     * configuré côté Stripe Dashboard - le checkout échoue proprement dans ce cas.
     */
    #[ORM\Column(length: 255, nullable: true, unique: true)]
    #[Groups(['admin:subscription_plan:read'])]
    private ?string $stripePriceId = null;

    /**
     * Le plan est actuellement proposé à la souscription (levier admin "promouvoir/
     * rétrograder", distinct du soft-delete ci-dessous).
     */
    #[ORM\Column(type: Types::BOOLEAN)]
    #[Groups(['subscription_plan:read', 'subscription_plan:list', 'admin:subscription_plan:read'])]
    private bool $isActive = true;

    /**
     * Soft-delete : un plan retiré du catalogue mais référencé par des UserSubscription
     * historiques doit rester en base (cf. ../CLAUDE.md §8, jamais de suppression physique).
     */
    #[ORM\Column(type: Types::DATETIME_MUTABLE, nullable: true)]
    #[Groups(['admin:subscription_plan:read'])]
    private ?\DateTimeInterface $deletedAt = null;

    #[ORM\Column(type: Types::INTEGER)]
    #[Groups(['subscription_plan:read', 'subscription_plan:list', 'admin:subscription_plan:read'])]
    private int $sortOrder = 0;

    #[ORM\Column(type: Types::DATETIME_MUTABLE)]
    #[Groups(['admin:subscription_plan:read'])]
    private ?\DateTimeInterface $createdAt = null;

    #[ORM\Column(type: Types::DATETIME_MUTABLE)]
    #[Groups(['admin:subscription_plan:read'])]
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

    public function getCode(): ?string
    {
        return $this->code;
    }

    public function setCode(string $code): static
    {
        $this->code = $code;
        return $this;
    }

    public function getName(): ?string
    {
        return $this->name;
    }

    public function setName(string $name): static
    {
        $this->name = $name;
        return $this;
    }

    public function getPriceAmountEur(): ?string
    {
        return $this->priceAmountEur;
    }

    public function setPriceAmountEur(?string $priceAmountEur): static
    {
        $this->priceAmountEur = $priceAmountEur;
        return $this;
    }

    public function getPriceAmountXaf(): ?string
    {
        return $this->priceAmountXaf;
    }

    public function setPriceAmountXaf(?string $priceAmountXaf): static
    {
        $this->priceAmountXaf = $priceAmountXaf;
        return $this;
    }

    public function getBillingPeriod(): string
    {
        return $this->billingPeriod;
    }

    public function setBillingPeriod(string $billingPeriod): static
    {
        $this->billingPeriod = $billingPeriod;
        return $this;
    }

    public function getMaxActiveVoyages(): ?int
    {
        return $this->maxActiveVoyages;
    }

    public function setMaxActiveVoyages(?int $maxActiveVoyages): static
    {
        $this->maxActiveVoyages = $maxActiveVoyages;
        return $this;
    }

    public function getMaxActiveDemandes(): ?int
    {
        return $this->maxActiveDemandes;
    }

    public function setMaxActiveDemandes(?int $maxActiveDemandes): static
    {
        $this->maxActiveDemandes = $maxActiveDemandes;
        return $this;
    }

    public function hasBadge(): bool
    {
        return $this->hasBadge;
    }

    public function setHasBadge(bool $hasBadge): static
    {
        $this->hasBadge = $hasBadge;
        return $this;
    }

    public function isFeatured(): bool
    {
        return $this->isFeatured;
    }

    public function setIsFeatured(bool $isFeatured): static
    {
        $this->isFeatured = $isFeatured;
        return $this;
    }

    public function getStripePriceId(): ?string
    {
        return $this->stripePriceId;
    }

    public function setStripePriceId(?string $stripePriceId): static
    {
        $this->stripePriceId = $stripePriceId;
        return $this;
    }

    public function isActive(): bool
    {
        return $this->isActive;
    }

    public function setIsActive(bool $isActive): static
    {
        $this->isActive = $isActive;
        return $this;
    }

    public function getDeletedAt(): ?\DateTimeInterface
    {
        return $this->deletedAt;
    }

    public function setDeletedAt(?\DateTimeInterface $deletedAt): static
    {
        $this->deletedAt = $deletedAt;
        return $this;
    }

    public function isDeleted(): bool
    {
        return $this->deletedAt !== null;
    }

    public function getSortOrder(): int
    {
        return $this->sortOrder;
    }

    public function setSortOrder(int $sortOrder): static
    {
        $this->sortOrder = $sortOrder;
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
