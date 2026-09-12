<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\BoostRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Annotation\Groups;

#[ORM\Entity(repositoryClass: BoostRepository::class)]
#[ORM\Table(name: 'boosts')]
#[ORM\HasLifecycleCallbacks]
class Boost
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_ACTIVE = 'active';
    public const STATUS_EXPIRED = 'expired';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    #[Groups(['boost:read'])]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false)]
    private ?User $user = null;

    /**
     * Exactement une des deux cibles (voyage/demande) doit être renseignée - validé au
     * niveau du DTO de checkout (#[Assert\Callback]), pas ici : permet un leftJoin
     * propre depuis VoyageRepository/DemandeRepository plutôt qu'un couple polymorphe
     * targetType/targetId.
     */
    #[ORM\ManyToOne(targetEntity: Voyage::class)]
    #[ORM\JoinColumn(nullable: true)]
    #[Groups(['boost:read'])]
    private ?Voyage $voyage = null;

    #[ORM\ManyToOne(targetEntity: Demande::class)]
    #[ORM\JoinColumn(nullable: true)]
    #[Groups(['boost:read'])]
    private ?Demande $demande = null;

    #[ORM\ManyToOne(targetEntity: BoostOffer::class)]
    #[ORM\JoinColumn(nullable: false)]
    #[Groups(['boost:read'])]
    private ?BoostOffer $offer = null;

    #[ORM\Column(type: Types::DATETIME_MUTABLE, nullable: true)]
    #[Groups(['boost:read'])]
    private ?\DateTimeInterface $startAt = null;

    #[ORM\Column(type: Types::DATETIME_MUTABLE, nullable: true)]
    #[Groups(['boost:read'])]
    private ?\DateTimeInterface $endAt = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 2)]
    #[Groups(['boost:read'])]
    private ?string $amount = null;

    #[ORM\Column(length: 3)]
    #[Groups(['boost:read'])]
    private ?string $currency = null;

    #[ORM\Column(length: 20)]
    #[Groups(['boost:read'])]
    private string $status = self::STATUS_PENDING;

    /**
     * Horodatage du double consentement (accès immédiat + renonciation expresse au
     * droit de rétractation) au moment du checkout - même exigence légale que
     * UserSubscription (art. L.221-28 13° Code conso).
     */
    #[ORM\Column(type: Types::DATETIME_MUTABLE, nullable: true)]
    private ?\DateTimeInterface $withdrawalWaiverConsentedAt = null;

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

    public function getVoyage(): ?Voyage
    {
        return $this->voyage;
    }

    public function setVoyage(?Voyage $voyage): static
    {
        $this->voyage = $voyage;
        return $this;
    }

    public function getDemande(): ?Demande
    {
        return $this->demande;
    }

    public function setDemande(?Demande $demande): static
    {
        $this->demande = $demande;
        return $this;
    }

    public function getOffer(): ?BoostOffer
    {
        return $this->offer;
    }

    public function setOffer(?BoostOffer $offer): static
    {
        $this->offer = $offer;
        return $this;
    }

    public function getStartAt(): ?\DateTimeInterface
    {
        return $this->startAt;
    }

    public function setStartAt(?\DateTimeInterface $startAt): static
    {
        $this->startAt = $startAt;
        return $this;
    }

    public function getEndAt(): ?\DateTimeInterface
    {
        return $this->endAt;
    }

    public function setEndAt(?\DateTimeInterface $endAt): static
    {
        $this->endAt = $endAt;
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

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
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

    public function getCreatedAt(): ?\DateTimeInterface
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): ?\DateTimeInterface
    {
        return $this->updatedAt;
    }
}
