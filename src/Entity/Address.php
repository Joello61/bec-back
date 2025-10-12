<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\AddressRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Annotation\Groups;

#[ORM\Entity(repositoryClass: AddressRepository::class)]
#[ORM\Table(name: 'addresses')]
#[ORM\HasLifecycleCallbacks]
class Address
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    #[Groups(['address:read', 'user:read'])]
    private ?int $id = null;

    #[ORM\OneToOne(targetEntity: User::class, inversedBy: 'address')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?User $user = null;

    // ==================== CHAMPS COMMUNS ====================

    #[ORM\Column(length: 100)]
    #[Groups(['address:read', 'address:write', 'user:read', 'voyage:read', 'demande:read', 'admin:user:read'])]
    private ?string $pays = null;

    #[ORM\Column(length: 100)]
    #[Groups(['address:read', 'address:write', 'user:read', 'voyage:read', 'demande:read', 'admin:user:read'])]
    private ?string $ville = null;

    // ==================== FORMAT AFRIQUE ====================

    #[ORM\Column(length: 100, nullable: true)]
    #[Groups(['address:read', 'address:write', 'user:read', 'voyage:read', 'demande:read', 'admin:user:read'])]
    private ?string $quartier = null;

    // ==================== FORMAT DIASPORA ====================

    #[ORM\Column(length: 255, nullable: true)]
    #[Groups(['address:read', 'address:write', 'user:read', 'admin:user:read'])]
    private ?string $adresseLigne1 = null;

    #[ORM\Column(length: 255, nullable: true)]
    #[Groups(['address:read', 'address:write', 'user:read', 'admin:user:read'])]
    private ?string $adresseLigne2 = null;

    #[ORM\Column(length: 20, nullable: true)]
    #[Groups(['address:read', 'address:write', 'user:read', 'admin:user:read'])]
    private ?string $codePostal = null;

    // ==================== CONTRAINTE MODIFICATION (6 MOIS) ====================

    #[ORM\Column(type: Types::DATETIME_MUTABLE)]
    #[Groups(['address:read'])]
    private ?\DateTimeInterface $createdAt = null;

    #[ORM\Column(type: Types::DATETIME_MUTABLE)]
    #[Groups(['address:read'])]
    private ?\DateTimeInterface $updatedAt = null;

    /**
     * Date de la dernière modification manuelle par l'utilisateur
     * Utilisé pour la contrainte des 6 mois
     */
    #[ORM\Column(type: Types::DATETIME_MUTABLE, nullable: true)]
    #[Groups(['address:read'])]
    private ?\DateTimeInterface $lastModifiedAt = null;

    public function __construct()
    {
        $this->createdAt = new \DateTime();
        $this->updatedAt = new \DateTime();
    }

    #[ORM\PreUpdate]
    public function setUpdatedAtValue(): void
    {
        $this->updatedAt = new \DateTime();
    }

    // ==================== MÉTHODES CRITIQUES ====================

    /**
     * Vérifie si l'adresse peut être modifiée (contrainte des 6 mois)
     */
    public function canBeModified(): bool
    {
        // Première modification toujours autorisée
        if ($this->lastModifiedAt === null) {
            return true;
        }

        $sixMonthsAgo = new \DateTime('-6 months');
        return $this->lastModifiedAt <= $sixMonthsAgo;
    }

    /**
     * Calcule la prochaine date de modification possible
     */
    public function getNextModificationDate(): ?\DateTimeInterface
    {
        if ($this->lastModifiedAt === null) {
            return null;
        }

        return (clone $this->lastModifiedAt)->modify('+6 months');
    }

    /**
     * Nombre de jours restants avant modification possible
     */
    public function getDaysUntilModification(): int
    {
        if ($this->canBeModified()) {
            return 0;
        }

        $nextDate = $this->getNextModificationDate();
        $now = new \DateTime();
        $diff = $now->diff($nextDate);

        return (int) $diff->days;
    }

    /**
     * Détermine le type d'adresse utilisé
     */
    public function getAddressType(): ?string
    {
        if ($this->quartier !== null) {
            return 'african';
        }
        if ($this->adresseLigne1 !== null && $this->codePostal !== null) {
            return 'postal';
        }
        return null;
    }

    /**
     * Vérifie si l'adresse est valide (au moins un format complet)
     */
    public function isValid(): bool
    {
        $africanFormat = $this->quartier !== null;
        $diasporaFormat = $this->adresseLigne1 !== null && $this->codePostal !== null;

        return ($this->pays !== null && $this->ville !== null) && ($africanFormat || $diasporaFormat);
    }

    /**
     * Marque l'adresse comme modifiée (met à jour lastModifiedAt)
     */
    public function markAsModified(): void
    {
        $this->lastModifiedAt = new \DateTime();
    }

    // ==================== GETTERS & SETTERS ====================

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUser(): ?User
    {
        return $this->user;
    }

    public function setUser(User $user): static
    {
        $this->user = $user;
        return $this;
    }

    public function getPays(): ?string
    {
        return $this->pays;
    }

    public function setPays(string $pays): static
    {
        $this->pays = $pays;
        return $this;
    }

    public function getVille(): ?string
    {
        return $this->ville;
    }

    public function setVille(string $ville): static
    {
        $this->ville = $ville;
        return $this;
    }

    public function getQuartier(): ?string
    {
        return $this->quartier;
    }

    public function setQuartier(?string $quartier): static
    {
        $this->quartier = $quartier;
        return $this;
    }

    public function getAdresseLigne1(): ?string
    {
        return $this->adresseLigne1;
    }

    public function setAdresseLigne1(?string $adresseLigne1): static
    {
        $this->adresseLigne1 = $adresseLigne1;
        return $this;
    }

    public function getAdresseLigne2(): ?string
    {
        return $this->adresseLigne2;
    }

    public function setAdresseLigne2(?string $adresseLigne2): static
    {
        $this->adresseLigne2 = $adresseLigne2;
        return $this;
    }

    public function getCodePostal(): ?string
    {
        return $this->codePostal;
    }

    public function setCodePostal(?string $codePostal): static
    {
        $this->codePostal = $codePostal;
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

    public function getLastModifiedAt(): ?\DateTimeInterface
    {
        return $this->lastModifiedAt;
    }

    public function setLastModifiedAt(?\DateTimeInterface $lastModifiedAt): static
    {
        $this->lastModifiedAt = $lastModifiedAt;
        return $this;
    }
}
