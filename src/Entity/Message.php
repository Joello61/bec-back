<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\MessageRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Annotation\Groups;

#[ORM\Entity(repositoryClass: MessageRepository::class)]
#[ORM\Table(name: 'messages')]
#[ORM\HasLifecycleCallbacks]
class Message
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    #[Groups(['message:read', 'message:list'])]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class, inversedBy: 'messagesEnvoyes')]
    #[ORM\JoinColumn(nullable: false)]
    #[Groups(['message:read', 'message:list'])]
    private ?User $expediteur = null;

    #[ORM\ManyToOne(targetEntity: User::class, inversedBy: 'messagesRecus')]
    #[ORM\JoinColumn(nullable: false)]
    #[Groups(['message:read', 'message:list'])]
    private ?User $destinataire = null;

    #[ORM\Column(type: Types::TEXT)]
    #[Groups(['message:read', 'message:list', 'message:write'])]
    private ?string $contenu = null;

    #[ORM\Column]
    #[Groups(['message:read', 'message:list'])]
    private bool $lu = false;

    #[ORM\Column(type: Types::DATETIME_MUTABLE)]
    #[Groups(['message:read', 'message:list'])]
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

    public function getExpediteur(): ?User
    {
        return $this->expediteur;
    }

    public function setExpediteur(?User $expediteur): static
    {
        $this->expediteur = $expediteur;
        return $this;
    }

    public function getDestinataire(): ?User
    {
        return $this->destinataire;
    }

    public function setDestinataire(?User $destinataire): static
    {
        $this->destinataire = $destinataire;
        return $this;
    }

    public function getContenu(): ?string
    {
        return $this->contenu;
    }

    public function setContenu(string $contenu): static
    {
        $this->contenu = $contenu;
        return $this;
    }

    public function isLu(): bool
    {
        return $this->lu;
    }

    public function setLu(bool $lu): static
    {
        $this->lu = $lu;
        return $this;
    }

    public function getCreatedAt(): ?\DateTimeInterface
    {
        return $this->createdAt;
    }
}
