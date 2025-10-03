<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\FavoriRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Annotation\Groups;

#[ORM\Entity(repositoryClass: FavoriRepository::class)]
#[ORM\Table(name: 'favoris')]
#[ORM\HasLifecycleCallbacks]
#[ORM\UniqueConstraint(name: 'unique_user_voyage', columns: ['user_id', 'voyage_id'])]
#[ORM\UniqueConstraint(name: 'unique_user_demande', columns: ['user_id', 'demande_id'])]
class Favori
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    #[Groups(['favori:read', 'favori:list'])]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class, inversedBy: 'favoris')]
    #[ORM\JoinColumn(nullable: false)]
    #[Groups(['favori:read', 'favori:list'])]
    private ?User $user = null;

    #[ORM\ManyToOne(targetEntity: Voyage::class, inversedBy: 'favoris')]
    #[ORM\JoinColumn(nullable: true, onDelete: 'CASCADE')]
    #[Groups(['favori:read', 'favori:list'])]
    private ?Voyage $voyage = null;

    #[ORM\ManyToOne(targetEntity: Demande::class, inversedBy: 'favoris')]
    #[ORM\JoinColumn(nullable: true, onDelete: 'CASCADE')]
    #[Groups(['favori:read', 'favori:list'])]
    private ?Demande $demande = null;

    #[ORM\Column(type: Types::DATETIME_MUTABLE)]
    #[Groups(['favori:read', 'favori:list'])]
    private ?\DateTimeInterface $createdAt = null;

    #[ORM\PrePersist]
    public function setCreatedAtValue(): void
    {
        $this->createdAt = new \DateTime();
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

    public function getCreatedAt(): ?\DateTimeInterface
    {
        return $this->createdAt;
    }
}
