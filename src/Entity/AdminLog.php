<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\AdminLogRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Annotation\Groups;

#[ORM\Entity(repositoryClass: AdminLogRepository::class)]
#[ORM\Table(name: 'admin_logs')]
#[ORM\Index(name: 'idx_action', columns: ['action'])]
#[ORM\Index(name: 'idx_target_type', columns: ['target_type'])]
#[ORM\Index(name: 'idx_created_at', columns: ['created_at'])]
#[ORM\HasLifecycleCallbacks]
class AdminLog
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    #[Groups(['admin:log:read', 'admin:log:list'])]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    #[Groups(['admin:log:read', 'admin:log:list'])]
    private ?User $admin = null;

    /**
     * Type d'action effectuée
     * Exemples : 'ban_user', 'unban_user', 'delete_voyage', 'update_roles', 'approve_signalement'
     */
    #[ORM\Column(length: 100)]
    #[Groups(['admin:log:read', 'admin:log:list'])]
    private ?string $action = null;

    /**
     * Type de la cible de l'action
     * Exemples : 'user', 'voyage', 'demande', 'avis', 'message', 'signalement'
     */
    #[ORM\Column(length: 50)]
    #[Groups(['admin:log:read', 'admin:log:list'])]
    private ?string $targetType = null;

    /**
     * ID de la cible de l'action
     */
    #[ORM\Column]
    #[Groups(['admin:log:read', 'admin:log:list'])]
    private ?int $targetId = null;

    /**
     * Détails supplémentaires sous forme JSON
     * Exemple : {"reason": "Contenu inapproprié", "oldValue": "...", "newValue": "..."}
     */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    #[Groups(['admin:log:read'])]
    private ?array $details = null;

    /**
     * Adresse IP de l'admin qui a effectué l'action
     */
    #[ORM\Column(length: 45, nullable: true)]
    #[Groups(['admin:log:read'])]
    private ?string $ipAddress = null;

    /**
     * User-Agent de l'admin
     */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    #[Groups(['admin:log:read'])]
    private ?string $userAgent = null;

    #[ORM\Column(type: Types::DATETIME_MUTABLE)]
    #[Groups(['admin:log:read', 'admin:log:list'])]
    private ?\DateTimeInterface $createdAt = null;

    #[ORM\PrePersist]
    public function setCreatedAtValue(): void
    {
        $this->createdAt = new \DateTime();
    }

    // ==================== GETTERS / SETTERS ====================

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getAdmin(): ?User
    {
        return $this->admin;
    }

    public function setAdmin(?User $admin): static
    {
        $this->admin = $admin;
        return $this;
    }

    public function getAction(): ?string
    {
        return $this->action;
    }

    public function setAction(string $action): static
    {
        $this->action = $action;
        return $this;
    }

    public function getTargetType(): ?string
    {
        return $this->targetType;
    }

    public function setTargetType(string $targetType): static
    {
        $this->targetType = $targetType;
        return $this;
    }

    public function getTargetId(): ?int
    {
        return $this->targetId;
    }

    public function setTargetId(int $targetId): static
    {
        $this->targetId = $targetId;
        return $this;
    }

    public function getDetails(): ?array
    {
        return $this->details;
    }

    public function setDetails(?array $details): static
    {
        $this->details = $details;
        return $this;
    }

    public function getIpAddress(): ?string
    {
        return $this->ipAddress;
    }

    public function setIpAddress(?string $ipAddress): static
    {
        $this->ipAddress = $ipAddress;
        return $this;
    }

    public function getUserAgent(): ?string
    {
        return $this->userAgent;
    }

    public function setUserAgent(?string $userAgent): static
    {
        $this->userAgent = $userAgent;
        return $this;
    }

    public function getCreatedAt(): ?\DateTimeInterface
    {
        return $this->createdAt;
    }

    // ==================== MÉTHODES UTILITAIRES ====================

    /**
     * Retourne une description lisible de l'action
     */
    public function getActionDescription(): string
    {
        return match($this->action) {
            'ban_user' => 'A banni un utilisateur',
            'unban_user' => 'A débanni un utilisateur',
            'delete_voyage' => 'A supprimé un voyage',
            'delete_demande' => 'A supprimé une demande',
            'delete_avis' => 'A supprimé un avis',
            'delete_message' => 'A supprimé un message',
            'update_roles' => 'A modifié les rôles d\'un utilisateur',
            'approve_signalement' => 'A traité un signalement',
            'reject_signalement' => 'A rejeté un signalement',
            'delete_user' => 'A supprimé un utilisateur',
            default => 'Action inconnue',
        };
    }

    /**
     * Retourne une représentation textuelle du log
     */
    public function __toString(): string
    {
        return sprintf(
            '[%s] %s (%s #%d) par %s',
            $this->createdAt?->format('Y-m-d H:i:s'),
            $this->getActionDescription(),
            $this->targetType,
            $this->targetId,
            $this->admin?->getEmail() ?? 'Unknown'
        );
    }
}
