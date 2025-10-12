<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\DemandeRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Annotation\Groups;

#[ORM\Entity(repositoryClass: DemandeRepository::class)]
#[ORM\Table(name: 'demandes')]
#[ORM\HasLifecycleCallbacks]
class Demande
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    #[Groups(['demande:read', 'demande:list', 'favori:read', 'favori:list'])]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class, inversedBy: 'demandes')]
    #[ORM\JoinColumn(nullable: false)]
    #[Groups(['demande:read', 'demande:list', 'favori:list'])]
    private ?User $client = null;

    #[ORM\Column(length: 255)]
    #[Groups(['demande:read', 'demande:list', 'demande:write', 'favori:list', 'signalement:list'])]
    private ?string $villeDepart = null;

    #[ORM\Column(length: 255)]
    #[Groups(['demande:read', 'demande:list', 'demande:write', 'favori:list', 'signalement:list'])]
    private ?string $villeArrivee = null;

    #[ORM\Column(type: Types::DATE_MUTABLE, nullable: true)]
    #[Groups(['demande:read', 'demande:list', 'demande:write', 'favori:list'])]
    private ?\DateTimeInterface $dateLimite = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 5, scale: 2)]
    #[Groups(['demande:read', 'demande:list', 'demande:write', 'favori:list'])]
    private ?string $poidsEstime = null;

    // ==================== NOUVEAUX CHAMPS PRIX/COMMISSION ====================

    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 2, nullable: true)]
    #[Groups(['demande:read', 'demande:list', 'demande:write', 'favori:list'])]
    private ?string $prixParKilo = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 2, nullable: true)]
    #[Groups(['demande:read', 'demande:list', 'demande:write', 'favori:list'])]
    private ?string $commissionProposeePourUnBagage = null;

    // ==================== DEVISE ====================

    /**
     * Code ISO 4217 de la devise (EUR, USD, XAF, etc.)
     */
    #[ORM\Column(length: 3)]
    #[Groups(['demande:read', 'demande:list', 'demande:write', 'favori:list'])]
    private string $currency = 'EUR';

    #[ORM\Column(type: Types::TEXT)]
    #[Groups(['demande:read', 'demande:write'])]
    private ?string $description = null;

    #[ORM\Column(length: 50)]
    #[Groups(['demande:read', 'demande:list', 'favori:list'])]
    private string $statut = 'en_recherche';

    #[ORM\Column(type: Types::DATETIME_MUTABLE)]
    #[Groups(['demande:read'])]
    private ?\DateTimeInterface $createdAt = null;

    #[ORM\Column(type: Types::DATETIME_MUTABLE)]
    #[Groups(['demande:read'])]
    private ?\DateTimeInterface $updatedAt = null;

    #[ORM\OneToMany(targetEntity: Favori::class, mappedBy: 'demande', cascade: ['remove'])]
    private Collection $favoris;

    #[ORM\OneToMany(targetEntity: Signalement::class, mappedBy: 'demande', cascade: ['remove'])]
    private Collection $signalements;

    public function __construct()
    {
        $this->favoris = new ArrayCollection();
        $this->signalements = new ArrayCollection();
    }

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

    public function getClient(): ?User
    {
        return $this->client;
    }

    public function setClient(?User $client): static
    {
        $this->client = $client;
        return $this;
    }

    public function getVilleDepart(): ?string
    {
        return $this->villeDepart;
    }

    public function setVilleDepart(string $villeDepart): static
    {
        $this->villeDepart = $villeDepart;
        return $this;
    }

    public function getVilleArrivee(): ?string
    {
        return $this->villeArrivee;
    }

    public function setVilleArrivee(string $villeArrivee): static
    {
        $this->villeArrivee = $villeArrivee;
        return $this;
    }

    public function getDateLimite(): ?\DateTimeInterface
    {
        return $this->dateLimite;
    }

    public function setDateLimite(?\DateTimeInterface $dateLimite): static
    {
        $this->dateLimite = $dateLimite;
        return $this;
    }

    public function getPoidsEstime(): ?string
    {
        return $this->poidsEstime;
    }

    public function setPoidsEstime(string $poidsEstime): static
    {
        $this->poidsEstime = $poidsEstime;
        return $this;
    }

    // ==================== GETTERS/SETTERS PRIX/COMMISSION ====================

    public function getPrixParKilo(): ?string
    {
        return $this->prixParKilo;
    }

    public function setPrixParKilo(?string $prixParKilo): static
    {
        $this->prixParKilo = $prixParKilo;
        return $this;
    }

    public function getCommissionProposeePourUnBagage(): ?string
    {
        return $this->commissionProposeePourUnBagage;
    }

    public function setCommissionProposeePourUnBagage(?string $commissionProposeePourUnBagage): static
    {
        $this->commissionProposeePourUnBagage = $commissionProposeePourUnBagage;
        return $this;
    }

    // ==================== GETTER/SETTER CURRENCY ====================

    public function getCurrency(): string
    {
        return $this->currency;
    }

    public function setCurrency(string $currency): static
    {
        $this->currency = strtoupper($currency);
        return $this;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(string $description): static
    {
        $this->description = $description;
        return $this;
    }

    public function getStatut(): string
    {
        return $this->statut;
    }

    public function setStatut(string $statut): static
    {
        $this->statut = $statut;
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

    public function getFavoris(): Collection
    {
        return $this->favoris;
    }

    public function getSignalements(): Collection
    {
        return $this->signalements;
    }
}
