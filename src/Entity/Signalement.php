<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\SignalementRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Annotation\Groups;

#[ORM\Entity(repositoryClass: SignalementRepository::class)]
#[ORM\Table(name: 'signalements')]
#[ORM\HasLifecycleCallbacks]
class Signalement
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    #[Groups(['signalement:read', 'signalement:list'])]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class, inversedBy: 'signalements')]
    #[ORM\JoinColumn(nullable: false)]
    #[Groups(['signalement:read', 'signalement:list'])]
    private ?User $signaleur = null;

    #[ORM\ManyToOne(targetEntity: Voyage::class, inversedBy: 'signalements')]
    #[ORM\JoinColumn(nullable: true, onDelete: 'CASCADE')]
    #[Groups(['signalement:read', 'signalement:list'])]
    private ?Voyage $voyage = null;

    #[ORM\ManyToOne(targetEntity: Demande::class, inversedBy: 'signalements')]
    #[ORM\JoinColumn(nullable: true, onDelete: 'CASCADE')]
    #[Groups(['signalement:read', 'signalement:list'])]
    private ?Demande $demande = null;

    #[ORM\Column(length: 50)]
    #[Groups(['signalement:read', 'signalement:list', 'signalement:write'])]
    private ?string $motif = null;

    #[ORM\Column(type: Types::TEXT)]
    #[Groups(['signalement:read', 'signalement:write'])]
    private ?string $description = null;

    #[ORM\Column(length: 50)]
    #[Groups(['signalement:read', 'signalement:list'])]
    private string $statut = 'en_attente';

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    #[Groups(['signalement:read'])]
    private ?string $reponseAdmin = null;

    #[ORM\Column(type: Types::DATETIME_MUTABLE)]
    #[Groups(['signalement:read', 'signalement:list'])]
    private ?\DateTimeInterface $createdAt = null;

    #[ORM\Column(type: Types::DATETIME_MUTABLE)]
    #[Groups(['signalement:read'])]
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

    public function getSignaleur(): ?User
    {
        return $this->signaleur;
    }

    public function setSignaleur(?User $signaleur): static
    {
        $this->signaleur = $signaleur;
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

    public function getMotif(): ?string
    {
        return $this->motif;
    }

    public function setMotif(string $motif): static
    {
        $this->motif = $motif;
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

    public function getReponseAdmin(): ?string
    {
        return $this->reponseAdmin;
    }

    public function setReponseAdmin(?string $reponseAdmin): static
    {
        $this->reponseAdmin = $reponseAdmin;
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
