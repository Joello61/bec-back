<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\PropositionRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Annotation\Groups;

#[ORM\Entity(repositoryClass: PropositionRepository::class)]
#[ORM\Table(name: 'propositions')]
#[ORM\HasLifecycleCallbacks]
class Proposition
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    #[Groups(['proposition:read', 'proposition:list'])]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Voyage::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    #[Groups(['proposition:read', 'proposition:list'])]
    private ?Voyage $voyage = null;

    #[ORM\ManyToOne(targetEntity: Demande::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    #[Groups(['proposition:read', 'proposition:list'])]
    private ?Demande $demande = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false)]
    #[Groups(['proposition:read', 'proposition:list'])]
    private ?User $client = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false)]
    #[Groups(['proposition:read', 'proposition:list'])]
    private ?User $voyageur = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 2)]
    #[Groups(['proposition:read', 'proposition:list', 'proposition:write'])]
    private ?string $prixParKilo = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 2)]
    #[Groups(['proposition:read', 'proposition:list', 'proposition:write'])]
    private ?string $commissionProposeePourUnBagage = null;

    // ==================== DEVISE ====================

    /**
     * Code ISO 4217 de la devise (EUR, USD, XAF, etc.)
     */
    #[ORM\Column(length: 3)]
    #[Groups(['proposition:read', 'proposition:list', 'proposition:write'])]
    private string $currency = 'EUR';

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    #[Groups(['proposition:read', 'proposition:write'])]
    private ?string $message = null;

    #[ORM\Column(length: 50)]
    #[Groups(['proposition:read', 'proposition:list'])]
    private string $statut = 'en_attente'; // en_attente, acceptee, refusee

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    #[Groups(['proposition:read'])]
    private ?string $messageRefus = null;

    #[ORM\Column(type: Types::DATETIME_MUTABLE)]
    #[Groups(['proposition:read', 'proposition:list'])]
    private ?\DateTimeInterface $createdAt = null;

    #[ORM\Column(type: Types::DATETIME_MUTABLE)]
    #[Groups(['proposition:read', 'proposition:list'])]
    private ?\DateTimeInterface $updatedAt = null;

    #[ORM\Column(type: Types::DATETIME_MUTABLE, nullable: true)]
    #[Groups(['proposition:read'])]
    private ?\DateTimeInterface $reponduAt = null;

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

    public function getClient(): ?User
    {
        return $this->client;
    }

    public function setClient(?User $client): static
    {
        $this->client = $client;
        return $this;
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

    public function getPrixParKilo(): ?string
    {
        return $this->prixParKilo;
    }

    public function setPrixParKilo(string $prixParKilo): static
    {
        $this->prixParKilo = $prixParKilo;
        return $this;
    }

    public function getCommissionProposeePourUnBagage(): ?string
    {
        return $this->commissionProposeePourUnBagage;
    }

    public function setCommissionProposeePourUnBagage(string $commissionProposeePourUnBagage): static
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

    public function getMessage(): ?string
    {
        return $this->message;
    }

    public function setMessage(?string $message): static
    {
        $this->message = $message;
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

    public function getMessageRefus(): ?string
    {
        return $this->messageRefus;
    }

    public function setMessageRefus(?string $messageRefus): static
    {
        $this->messageRefus = $messageRefus;
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

    public function getReponduAt(): ?\DateTimeInterface
    {
        return $this->reponduAt;
    }

    public function setReponduAt(?\DateTimeInterface $reponduAt): static
    {
        $this->reponduAt = $reponduAt;
        return $this;
    }
}
