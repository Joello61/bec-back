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
    #[Groups(['voyage:read', 'voyage:list', 'proposition:list'])]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class, inversedBy: 'voyages')]
    #[ORM\JoinColumn(nullable: false)]
    #[Groups(['voyage:read', 'voyage:list'])]
    private ?User $voyageur = null;

    #[ORM\Column(length: 255)]
    #[Groups(['voyage:read', 'voyage:list', 'voyage:write', 'proposition:list'])]
    private ?string $villeDepart = null;

    #[ORM\Column(length: 255)]
    #[Groups(['voyage:read', 'voyage:list', 'voyage:write', 'proposition:list'])]
    private ?string $villeArrivee = null;

    #[ORM\Column(type: Types::DATE_MUTABLE)]
    #[Groups(['voyage:read', 'voyage:list', 'voyage:write', 'proposition:list'])]
    private ?\DateTimeInterface $dateDepart = null;

    #[ORM\Column(type: Types::DATE_MUTABLE)]
    #[Groups(['voyage:read', 'voyage:list', 'voyage:write', 'proposition:list'])]
    private ?\DateTimeInterface $dateArrivee = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 5, scale: 2)]
    #[Groups(['voyage:read', 'voyage:list', 'voyage:write'])]
    private ?string $poidsDisponible = null;

    // ==================== NOUVEAUX CHAMPS PRIX/COMMISSION ====================

    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 2, nullable: true)]
    #[Groups(['voyage:read', 'voyage:list', 'voyage:write'])]
    private ?string $prixParKilo = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 2, nullable: true)]
    #[Groups(['voyage:read', 'voyage:list', 'voyage:write'])]
    private ?string $commissionProposeePourUnBagage = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    #[Groups(['voyage:read', 'voyage:write'])]
    private ?string $description = null;

    #[ORM\Column(length: 50)]
    #[Groups(['voyage:read', 'voyage:list'])]
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
