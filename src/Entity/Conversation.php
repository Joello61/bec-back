<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\ConversationRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Annotation\Groups;

#[ORM\Entity(repositoryClass: ConversationRepository::class)]
#[ORM\Table(name: 'conversations')]
#[ORM\HasLifecycleCallbacks]
class Conversation
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    #[Groups(['conversation:read', 'conversation:list'])]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false)]
    #[Groups(['conversation:read', 'conversation:list'])]
    private ?User $participant1 = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false)]
    #[Groups(['conversation:read', 'conversation:list'])]
    private ?User $participant2 = null;

    #[ORM\OneToMany(targetEntity: Message::class, mappedBy: 'conversation', cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['createdAt' => 'ASC'])]
    #[Groups(['conversation:read'])]
    private Collection $messages;

    #[ORM\Column(type: Types::DATETIME_MUTABLE)]
    #[Groups(['conversation:read', 'conversation:list'])]
    private ?\DateTimeInterface $createdAt = null;

    #[ORM\Column(type: Types::DATETIME_MUTABLE)]
    #[Groups(['conversation:read', 'conversation:list'])]
    private ?\DateTimeInterface $updatedAt = null;

    // Dernier message pour affichage dans la liste des conversations
    #[Groups(['conversation:list'])]
    private ?Message $dernierMessage = null;

    // Compteur de messages non lus pour l'utilisateur courant
    #[Groups(['conversation:list'])]
    private int $messagesNonLus = 0;

    public function __construct()
    {
        $this->messages = new ArrayCollection();
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

    public function getParticipant1(): ?User
    {
        return $this->participant1;
    }

    public function setParticipant1(?User $participant1): static
    {
        $this->participant1 = $participant1;
        return $this;
    }

    public function getParticipant2(): ?User
    {
        return $this->participant2;
    }

    public function setParticipant2(?User $participant2): static
    {
        $this->participant2 = $participant2;
        return $this;
    }

    public function getMessages(): Collection
    {
        return $this->messages;
    }

    public function addMessage(Message $message): static
    {
        if (!$this->messages->contains($message)) {
            $this->messages->add($message);
            $message->setConversation($this);
            $this->setUpdatedAtValue();
        }

        return $this;
    }

    public function removeMessage(Message $message): static
    {
        if ($this->messages->removeElement($message)) {
            if ($message->getConversation() === $this) {
                $message->setConversation(null);
            }
        }

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

    /**
     * Vérifie si un utilisateur est participant de cette conversation
     */
    public function hasParticipant(User $user): bool
    {
        return $this->participant1 === $user || $this->participant2 === $user;
    }

    /**
     * Retourne l'autre participant de la conversation par rapport à l'utilisateur donné
     */
    public function getOtherParticipant(User $user): ?User
    {
        if ($this->participant1 === $user) {
            return $this->participant2;
        }

        if ($this->participant2 === $user) {
            return $this->participant1;
        }

        return null;
    }

    /**
     * Récupère le dernier message de la conversation
     */
    public function getDernierMessage(): ?Message
    {
        if ($this->dernierMessage !== null) {
            return $this->dernierMessage;
        }

        if ($this->messages->isEmpty()) {
            return null;
        }

        $messagesArray = $this->messages->toArray();
        return end($messagesArray);
    }

    public function setDernierMessage(?Message $message): static
    {
        $this->dernierMessage = $message;
        return $this;
    }

    public function getMessagesNonLus(): int
    {
        return $this->messagesNonLus;
    }

    public function setMessagesNonLus(int $count): static
    {
        $this->messagesNonLus = $count;
        return $this;
    }

    /**
     * Compte les messages non lus pour un utilisateur spécifique
     */
    public function countMessagesNonLusPour(User $user): int
    {
        return $this->messages->filter(function (Message $message) use ($user) {
            return $message->getDestinataire() === $user && !$message->isLu();
        })->count();
    }
}
