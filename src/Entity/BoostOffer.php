<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\BoostOfferRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Annotation\Groups;

#[ORM\Entity(repositoryClass: BoostOfferRepository::class)]
#[ORM\Table(name: 'boost_offers')]
#[ORM\HasLifecycleCallbacks]
class BoostOffer
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    #[Groups(['boost_offer:read', 'boost_offer:list', 'admin:boost_offer:read'])]
    private ?int $id = null;

    #[ORM\Column(length: 100)]
    #[Groups(['boost_offer:read', 'boost_offer:list', 'admin:boost_offer:read'])]
    private ?string $name = null;

    #[ORM\Column(type: Types::INTEGER)]
    #[Groups(['boost_offer:read', 'boost_offer:list', 'admin:boost_offer:read'])]
    private ?int $durationDays = null;

    /**
     * Prix en euros (paiement carte, mode: payment inline via price_data - pas de Price
     * Stripe pré-créé, contrairement à SubscriptionPlan).
     */
    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 2)]
    #[Groups(['boost_offer:read', 'boost_offer:list', 'admin:boost_offer:read'])]
    private ?string $priceAmountEur = null;

    /**
     * Prix en XAF (paiement Mobile Money, Lot 3). Null tant que le tarif Mobile Money
     * n'a pas été fixé pour cette offre.
     */
    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 2, nullable: true)]
    #[Groups(['boost_offer:read', 'boost_offer:list', 'admin:boost_offer:read'])]
    private ?string $priceAmountXaf = null;

    #[ORM\Column(type: Types::BOOLEAN)]
    #[Groups(['boost_offer:read', 'boost_offer:list', 'admin:boost_offer:read'])]
    private bool $isActive = true;

    /**
     * Mise en avant visuelle sur la page tarifs (Lot 5), distincte de isActive
     * ("proposée à l'achat").
     */
    #[ORM\Column(type: Types::BOOLEAN)]
    #[Groups(['boost_offer:read', 'boost_offer:list', 'admin:boost_offer:read'])]
    private bool $isFeatured = false;

    /**
     * Soft-delete : une offre retirée du catalogue mais référencée par un Boost
     * historique doit rester en base (cf. ../CLAUDE.md §8, jamais de suppression physique).
     */
    #[ORM\Column(type: Types::DATETIME_MUTABLE, nullable: true)]
    #[Groups(['admin:boost_offer:read'])]
    private ?\DateTimeInterface $deletedAt = null;

    #[ORM\Column(type: Types::INTEGER)]
    #[Groups(['boost_offer:read', 'boost_offer:list', 'admin:boost_offer:read'])]
    private int $sortOrder = 0;

    #[ORM\Column(type: Types::DATETIME_MUTABLE)]
    #[Groups(['admin:boost_offer:read'])]
    private ?\DateTimeInterface $createdAt = null;

    #[ORM\Column(type: Types::DATETIME_MUTABLE)]
    #[Groups(['admin:boost_offer:read'])]
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

    public function getName(): ?string
    {
        return $this->name;
    }

    public function setName(string $name): static
    {
        $this->name = $name;
        return $this;
    }

    public function getDurationDays(): ?int
    {
        return $this->durationDays;
    }

    public function setDurationDays(int $durationDays): static
    {
        $this->durationDays = $durationDays;
        return $this;
    }

    public function getPriceAmountEur(): ?string
    {
        return $this->priceAmountEur;
    }

    public function setPriceAmountEur(string $priceAmountEur): static
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

    public function isActive(): bool
    {
        return $this->isActive;
    }

    public function setIsActive(bool $isActive): static
    {
        $this->isActive = $isActive;
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
