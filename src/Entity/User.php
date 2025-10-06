<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\UserRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Serializer\Annotation\Groups;

#[ORM\Entity(repositoryClass: UserRepository::class)]
#[ORM\Table(name: 'users')]
#[ORM\HasLifecycleCallbacks]
class User implements UserInterface, PasswordAuthenticatedUserInterface
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    #[Groups(['user:read', 'voyage:read', 'voyage:list', 'demande:read', 'demande:list', 'message:read', 'avis:read', 'proposition:list', 'message:list', 'conversation:list', 'conversation:read'])]
    private ?int $id = null;

    #[ORM\Column(length: 180, unique: true)]
    #[Groups(['user:read', 'user:write', 'voyage:read', 'voyage:list', 'demande:read', 'demande:list'])]
    private ?string $email = null;

    #[ORM\Column]
    private array $roles = [];

    #[ORM\Column(nullable: true)]
    private ?string $password = null;

    #[ORM\Column(length: 100)]
    #[Groups(['user:read', 'user:write', 'voyage:read', 'voyage:list', 'demande:read', 'demande:list', 'message:read', 'proposition:list', 'message:list', 'conversation:list', 'conversation:read'])]
    private ?string $nom = null;

    #[ORM\Column(length: 100)]
    #[Groups(['user:read', 'user:write', 'voyage:read', 'voyage:list', 'demande:read', 'demande:list', 'message:read', 'proposition:list', 'message:list', 'conversation:list', 'conversation:read'])]
    private ?string $prenom = null;

    #[ORM\Column(length: 20, nullable: true)]
    #[Groups(['user:read', 'user:write', 'voyage:read', 'voyage:list', 'demande:read', 'demande:list'])]
    private ?string $telephone = null;

    #[ORM\Column(length: 255, nullable: true)]
    #[Groups(['user:read', 'user:write', 'voyage:read', 'voyage:list', 'demande:read', 'demande:list', 'proposition:list', 'message:list', 'conversation:read'])]
    private ?string $photo = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    #[Groups(['user:read', 'user:write', 'voyage:read', 'demande:read'])]
    private ?string $bio = null;

    #[ORM\Column]
    #[Groups(['user:read'])]
    private bool $emailVerifie = false;

    #[ORM\Column]
    #[Groups(['user:read'])]
    private bool $telephoneVerifie = false;

    // Champs OAuth
    #[ORM\Column(length: 20, nullable: true)]
    private ?string $authProvider = null; // 'local', 'google', 'facebook'

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $googleId = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $facebookId = null;

    #[ORM\Column(type: Types::DATETIME_MUTABLE)]
    #[Groups(['user:read', 'demande:read', 'voyage:read'])]
    private ?\DateTimeInterface $createdAt = null;

    #[ORM\Column(type: Types::DATETIME_MUTABLE)]
    #[Groups(['user:read'])]
    private ?\DateTimeInterface $updatedAt = null;

    // ==================== NOUVELLE RELATION SETTINGS ====================
    #[ORM\OneToOne(targetEntity: UserSettings::class, mappedBy: 'user', cascade: ['persist', 'remove'])]
    #[Groups(['user:read'])]
    private ?UserSettings $settings = null;

    #[ORM\OneToMany(targetEntity: Voyage::class, mappedBy: 'voyageur', cascade: ['remove'])]
    private Collection $voyages;

    #[ORM\OneToMany(targetEntity: Demande::class, mappedBy: 'client', cascade: ['remove'])]
    private Collection $demandes;

    #[ORM\OneToMany(targetEntity: Message::class, mappedBy: 'expediteur', cascade: ['remove'])]
    private Collection $messagesEnvoyes;

    #[ORM\OneToMany(targetEntity: Message::class, mappedBy: 'destinataire', cascade: ['remove'])]
    private Collection $messagesRecus;

    #[ORM\OneToMany(targetEntity: Notification::class, mappedBy: 'user', cascade: ['remove'])]
    private Collection $notifications;

    #[ORM\OneToMany(targetEntity: Favori::class, mappedBy: 'user', cascade: ['remove'])]
    private Collection $favoris;

    #[ORM\OneToMany(targetEntity: Avis::class, mappedBy: 'auteur', cascade: ['remove'])]
    private Collection $avisDonnes;

    #[ORM\OneToMany(targetEntity: Avis::class, mappedBy: 'cible', cascade: ['remove'])]
    private Collection $avisRecus;

    #[ORM\OneToMany(targetEntity: Signalement::class, mappedBy: 'signaleur', cascade: ['remove'])]
    private Collection $signalements;

    #[ORM\OneToMany(targetEntity: Conversation::class, mappedBy: 'participant1')]
    private Collection $conversationsAsParticipant1;

    #[ORM\OneToMany(targetEntity: Conversation::class, mappedBy: 'participant2')]
    private Collection $conversationsAsParticipant2;

    public function __construct()
    {
        $this->voyages = new ArrayCollection();
        $this->demandes = new ArrayCollection();
        $this->messagesEnvoyes = new ArrayCollection();
        $this->messagesRecus = new ArrayCollection();
        $this->notifications = new ArrayCollection();
        $this->favoris = new ArrayCollection();
        $this->avisDonnes = new ArrayCollection();
        $this->avisRecus = new ArrayCollection();
        $this->signalements = new ArrayCollection();
        $this->conversationsAsParticipant1 = new ArrayCollection();
        $this->conversationsAsParticipant2 = new ArrayCollection();
        $this->authProvider = 'local';
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

    public function getEmail(): ?string
    {
        return $this->email;
    }

    public function setEmail(string $email): static
    {
        $this->email = $email;
        return $this;
    }

    public function getUserIdentifier(): string
    {
        return (string) $this->email;
    }

    public function getRoles(): array
    {
        $roles = $this->roles;
        $roles[] = 'ROLE_USER';
        return array_unique($roles);
    }

    public function setRoles(array $roles): static
    {
        $this->roles = $roles;
        return $this;
    }

    public function getPassword(): ?string
    {
        return $this->password;
    }

    public function setPassword(?string $password): static
    {
        $this->password = $password;
        return $this;
    }

    public function eraseCredentials(): void
    {
    }

    public function getNom(): ?string
    {
        return $this->nom;
    }

    public function setNom(string $nom): static
    {
        $this->nom = $nom;
        return $this;
    }

    public function getPrenom(): ?string
    {
        return $this->prenom;
    }

    public function setPrenom(string $prenom): static
    {
        $this->prenom = $prenom;
        return $this;
    }

    public function getTelephone(): ?string
    {
        return $this->telephone;
    }

    public function setTelephone(?string $telephone): static
    {
        $this->telephone = $telephone;
        return $this;
    }

    public function getPhoto(): ?string
    {
        return $this->photo;
    }

    public function setPhoto(?string $photo): static
    {
        $this->photo = $photo;
        return $this;
    }

    public function getBio(): ?string
    {
        return $this->bio;
    }

    public function setBio(?string $bio): static
    {
        $this->bio = $bio;
        return $this;
    }

    public function isEmailVerifie(): bool
    {
        return $this->emailVerifie;
    }

    public function setEmailVerifie(bool $emailVerifie): static
    {
        $this->emailVerifie = $emailVerifie;
        return $this;
    }

    public function isTelephoneVerifie(): bool
    {
        return $this->telephoneVerifie;
    }

    public function setTelephoneVerifie(bool $telephoneVerifie): static
    {
        $this->telephoneVerifie = $telephoneVerifie;
        return $this;
    }

    public function getAuthProvider(): ?string
    {
        return $this->authProvider;
    }

    public function setAuthProvider(?string $authProvider): static
    {
        $this->authProvider = $authProvider;
        return $this;
    }

    public function getGoogleId(): ?string
    {
        return $this->googleId;
    }

    public function setGoogleId(?string $googleId): static
    {
        $this->googleId = $googleId;
        return $this;
    }

    public function getFacebookId(): ?string
    {
        return $this->facebookId;
    }

    public function setFacebookId(?string $facebookId): static
    {
        $this->facebookId = $facebookId;
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

    // ==================== SETTINGS ====================

    public function getSettings(): ?UserSettings
    {
        return $this->settings;
    }

    public function setSettings(UserSettings $settings): static
    {
        // Set the owning side of the relation if necessary
        if ($settings->getUser() !== $this) {
            $settings->setUser($this);
        }

        $this->settings = $settings;
        return $this;
    }

    public function getVoyages(): Collection
    {
        return $this->voyages;
    }

    public function getDemandes(): Collection
    {
        return $this->demandes;
    }

    public function getMessagesEnvoyes(): Collection
    {
        return $this->messagesEnvoyes;
    }

    public function getMessagesRecus(): Collection
    {
        return $this->messagesRecus;
    }

    public function getNotifications(): Collection
    {
        return $this->notifications;
    }

    public function getFavoris(): Collection
    {
        return $this->favoris;
    }

    public function getAvisDonnes(): Collection
    {
        return $this->avisDonnes;
    }

    public function getAvisRecus(): Collection
    {
        return $this->avisRecus;
    }

    public function getSignalements(): Collection
    {
        return $this->signalements;
    }

    public function getConversationsAsParticipant1(): Collection
    {
        return $this->conversationsAsParticipant1;
    }

    public function getConversationsAsParticipant2(): Collection
    {
        return $this->conversationsAsParticipant2;
    }

    public function getAllConversations(): array
    {
        return array_merge(
            $this->conversationsAsParticipant1->toArray(),
            $this->conversationsAsParticipant2->toArray()
        );
    }
}
