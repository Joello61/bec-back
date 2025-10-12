<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\VoyageRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Annotation\Groups;

#[ORM\Entity(repositoryClass: VoyageRepository::class)]
#[ORM\Table(name: 'voyages')]
#[ORM\HasLifecycleCallbacks]
class Voyage
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    #[Groups(['voyage:read', 'voyage:list', 'proposition:list', 'favori:read', 'favori:list'])]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class, inversedBy: 'voyages')]
    #[ORM\JoinColumn(nullable: false)]
    #[Groups(['voyage:read', 'voyage:list', 'favori:list'])]
    private ?User $voyageur = null;

    #[ORM\Column(length: 255)]
    #[Groups(['voyage:read', 'voyage:list', 'voyage:write', 'proposition:list', 'favori:list', 'signalement:list'])]
    private ?string $villeDepart = null;

    #[ORM\Column(length: 255)]
    #[Groups(['voyage:read', 'voyage:list', 'voyage:write', 'proposition:list', 'favori:list', 'signalement:list'])]
    private ?string $villeArrivee = null;

    #[ORM\Column(type: Types::DATE_MUTABLE)]
    #[Groups(['voyage:read', 'voyage:list', 'voyage:write', 'proposition:list', 'favori:list'])]
    private ?\DateTimeInterface $dateDepart = null;

    #[ORM\Column(type: Types::DATE_MUTABLE)]
    #[Groups(['voyage:read', 'voyage:list', 'voyage:write', 'proposition:list', 'favori:list'])]
    private ?\DateTimeInterface $dateArrivee = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 5, scale: 2)]
    #[Groups(['voyage:read', 'voyage:list', 'voyage:write', 'favori:list'])]
    private ?string $poidsDisponible = null;

    // ==================== NOUVEAUX CHAMPS PRIX/COMMISSION ====================

    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 2, nullable: true)]
    #[Groups(['voyage:read', 'voyage:list', 'voyage:write', 'favori:list'])]
    private ?string $prixParKilo = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 2, nullable: true)]
    #[Groups(['voyage:read', 'voyage:list', 'voyage:write', 'favori:list'])]
    private ?string $commissionProposeePourUnBagage = null;

    // ==================== DEVISE ====================

    /**
     * Code ISO 4217 de la devise (EUR, USD, XAF, etc.)
     */
    #[ORM\Column(length: 3)]
    #[Groups(['voyage:read', 'voyage:list', 'voyage:write', 'favori:list'])]
    private string $currency = 'EUR';

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    #[Groups(['voyage:read', 'voyage:write'])]
    private ?string $description = null;

    #[ORM\Column(length: 50)]
    #[Groups(['voyage:read', 'voyage:list', 'favori:list'])]
    private string $statut = 'actif';

    #[ORM\Column(type: Types::DATETIME_MUTABLE)]
    #[Groups(['voyage:read'])]
    private ?\DateTimeInterface $createdAt = null;

    #[ORM\Column(type: Types::DATETIME_MUTABLE)]
    #[Groups(['voyage:read'])]
    private ?\DateTimeInterface $updatedAt = null;

    #[ORM\OneToMany(targetEntity: Avis::class, mappedBy: 'voyage', cascade: ['remove'])]
    private Collection $avis;

    #[ORM\OneToMany(targetEntity: Favori::class, mappedBy: 'voyage', cascade: ['remove'])]
    private Collection $favoris;

    #[ORM\OneToMany(targetEntity: Signalement::class, mappedBy: 'voyage', cascade: ['remove'])]
    private Collection $signalements;

    public function __construct()
    {
        $this->avis = new ArrayCollection();
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

    public function getVoyageur(): ?User
    {
        return $this->voyageur;
    }

    public function setVoyageur(?User $voyageur): static
    {
        $this->voyageur = $voyageur;
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

    public function getDateDepart(): ?\DateTimeInterface
    {
        return $this->dateDepart;
    }

    public function setDateDepart(\DateTimeInterface $dateDepart): static
    {
        $this->dateDepart = $dateDepart;
        return $this;
    }

    public function getDateArrivee(): ?\DateTimeInterface
    {
        return $this->dateArrivee;
    }

    public function setDateArrivee(\DateTimeInterface $dateArrivee): static
    {
        $this->dateArrivee = $dateArrivee;
        return $this;
    }

    public function getPoidsDisponible(): ?string
    {
        return $this->poidsDisponible;
    }

    public function setPoidsDisponible(string $poidsDisponible): static
    {
        $this->poidsDisponible = $poidsDisponible;
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

    public function setDescription(?string $description): static
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

    public function getAvis(): Collection
    {
        return $this->avis;
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
