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
#[ORM\Index(name: 'idx_users_deleted_at', columns: ['deleted_at'])]
#[ORM\HasLifecycleCallbacks]
class User implements UserInterface, PasswordAuthenticatedUserInterface
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    #[Groups(['user:read', 'user:read:public', 'voyage:read', 'voyage:list', 'demande:read', 'demande:list', 'message:read', 'avis:read', 'proposition:list', 'message:list', 'conversation:list', 'conversation:read', 'favori:read', 'favori:list', 'admin:user:list', 'admin:user:read', 'admin:log:list', 'admin:log:read'])]
    private ?int $id = null;

    #[ORM\Column(length: 180, unique: true)]
    #[Groups(['user:read', 'user:write', 'admin:user:list', 'admin:user:read', 'admin:log:list', 'admin:log:read'])]
    private ?string $email = null;

    /** @var list<string> */
    #[ORM\Column]
    #[Groups(['admin:user:list','admin:user:read'])]
    private array $roles = [];

    #[ORM\Column(nullable: true)]
    private ?string $password = null;

    #[ORM\Column(length: 100)]
    #[Groups(['user:read', 'user:read:public', 'user:write', 'voyage:read', 'voyage:list', 'demande:read', 'demande:list', 'message:read', 'proposition:list', 'message:list', 'conversation:list', 'conversation:read', 'favori:read', 'favori:list', 'signalement:list', 'admin:user:list', 'admin:user:read', 'admin:log:list', 'admin:log:read'])]
    private ?string $nom = null;

    #[ORM\Column(length: 100)]
    #[Groups(['user:read', 'user:read:public', 'user:write', 'voyage:read', 'voyage:list', 'demande:read', 'demande:list', 'message:read', 'proposition:list', 'message:list', 'conversation:list', 'conversation:read', 'favori:read', 'favori:list', 'signalement:list', 'admin:user:list', 'admin:user:read', 'admin:log:list', 'admin:log:read'])]
    private ?string $prenom = null;

    #[ORM\Column(length: 20, nullable: true)]
    #[Groups(['user:read', 'user:write', 'admin:user:read'])]
    private ?string $telephone = null;

    // ==================== RELATION ADRESSE ====================

    #[ORM\OneToOne(targetEntity: Address::class, mappedBy: 'user', cascade: ['persist', 'remove'])]
    #[Groups(['user:read', 'user:read:public', 'admin:user:read'])]
    private ?Address $address = null;

    #[ORM\Column(length: 255, nullable: true)]
    #[Groups(['user:read', 'user:read:public', 'user:write', 'voyage:read', 'voyage:list', 'demande:read', 'demande:list', 'proposition:list', 'message:list', 'conversation:read'])]
    private ?string $photo = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    #[Groups(['user:read', 'user:write', 'voyage:read', 'demande:read'])]
    private ?string $bio = null;

    #[ORM\Column]
    #[Groups(['user:read', 'admin:user:list'])]
    private bool $emailVerifie = false;

    #[ORM\Column]
    #[Groups(['user:read', 'admin:user:list'])]
    private bool $telephoneVerifie = false;

    // Champs OAuth
    #[ORM\Column(length: 20, nullable: true)]
    private ?string $authProvider = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $googleId = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $facebookId = null;

    #[ORM\Column(type: Types::DATETIME_MUTABLE)]
    #[Groups(['user:read', 'demande:read', 'voyage:read', 'admin:user:list', 'admin:user:read'])]
    private ?\DateTimeInterface $createdAt = null;

    #[ORM\Column(type: Types::DATETIME_MUTABLE)]
    #[Groups(['user:read'])]
    private ?\DateTimeInterface $updatedAt = null;

    #[ORM\Column(type: Types::BOOLEAN, options: ['default' => false])]
    #[Groups(['user:read', 'admin:user:list'])]
    private bool $isBanned = false;

    #[ORM\Column(type: Types::DATETIME_MUTABLE, nullable: true)]
    #[Groups(['user:read', 'admin:user:list'])]
    private ?\DateTimeInterface $bannedAt = null;

    #[ORM\Column(type: Types::DATETIME_MUTABLE, nullable: true)]
    #[Groups(['user:read', 'admin:user:list'])]
    private ?\DateTimeInterface $bannedUntil = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    #[Groups(['admin:user:list', 'admin:user:read'])]
    private ?\DateTimeImmutable $deletedAt = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    #[Groups(['admin:user:list'])]
    private ?string $banReason = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    #[Groups(['admin:user:list'])]
    private ?User $bannedBy = null;

    #[ORM\OneToOne(targetEntity: UserSettings::class, mappedBy: 'user', cascade: ['persist', 'remove'])]
    #[Groups(['user:read'])]
    private ?UserSettings $settings = null;

    /**
     * Pas de cascade remove : un compte n'est jamais physiquement supprimé (soft-delete via
     * deletedAt, cf. Phase 5 du plan de correction). Les voyages/demandes actifs de l'utilisateur
     * sont annulés explicitement (VoyageService::deleteVoyage/DemandeService::deleteDemande) au
     * moment de la suppression du compte, jamais retirés en cascade.
     * @var Collection<int, Voyage>
     */
    #[ORM\OneToMany(targetEntity: Voyage::class, mappedBy: 'voyageur')]
    private Collection $voyages;

    /** @var Collection<int, Demande> */
    #[ORM\OneToMany(targetEntity: Demande::class, mappedBy: 'client')]
    private Collection $demandes;

    /**
     * Pas de cascade remove : l'historique de conversation doit rester visible pour l'autre
     * participant après suppression du compte (cf. audit Backend-Qualité #2).
     * @var Collection<int, Message>
     */
    #[ORM\OneToMany(targetEntity: Message::class, mappedBy: 'expediteur')]
    private Collection $messagesEnvoyes;

    /** @var Collection<int, Message> */
    #[ORM\OneToMany(targetEntity: Message::class, mappedBy: 'destinataire')]
    private Collection $messagesRecus;

    /** @var Collection<int, Notification> */
    #[ORM\OneToMany(targetEntity: Notification::class, mappedBy: 'user')]
    private Collection $notifications;

    /** @var Collection<int, Favori> */
    #[ORM\OneToMany(targetEntity: Favori::class, mappedBy: 'user')]
    private Collection $favoris;

    /**
     * Pas de cascade remove : la réputation d'un tiers (avis reçus) ne doit pas disparaître
     * avec le compte de l'auteur (cf. audit Backend-Qualité #2).
     * @var Collection<int, Avis>
     */
    #[ORM\OneToMany(targetEntity: Avis::class, mappedBy: 'auteur')]
    private Collection $avisDonnes;

    /** @var Collection<int, Avis> */
    #[ORM\OneToMany(targetEntity: Avis::class, mappedBy: 'cible')]
    private Collection $avisRecus;

    /**
     * Pas de cascade remove : un signalement reste une trace de modération utile aux admins,
     * même après suppression du compte du signaleur.
     * @var Collection<int, Signalement>
     */
    #[ORM\OneToMany(targetEntity: Signalement::class, mappedBy: 'signaleur')]
    private Collection $signalements;

    /** @var Collection<int, Conversation> */
    #[ORM\OneToMany(targetEntity: Conversation::class, mappedBy: 'participant1')]
    private Collection $conversationsAsParticipant1;

    /** @var Collection<int, Conversation> */
    #[ORM\OneToMany(targetEntity: Conversation::class, mappedBy: 'participant2')]
    private Collection $conversationsAsParticipant2;

    /** @var Collection<int, Signalement> */
    #[ORM\OneToMany(targetEntity: Signalement::class, mappedBy: 'utilisateurSignale')]
    private Collection $signalementsRecus;

    /** @var Collection<int, RefreshToken> */
    #[ORM\OneToMany(targetEntity: RefreshToken::class, mappedBy: 'user', orphanRemoval: true)]
    private Collection $refreshTokens;

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
        $this->signalementsRecus = new ArrayCollection();
        $this->refreshTokens = new ArrayCollection();
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

    // ==================== MÉTHODE CRITIQUE : VÉRIFICATION PROFIL COMPLET ====================

    /**
     * Vérifie si le profil de l'utilisateur est complet
     * Requis pour créer des demandes, voyages, avis ou envoyer des messages
     */
    public function isProfileComplete(): bool
    {
        $baseComplete = $this->emailVerifie
            && $this->telephoneVerifie
            && $this->telephone !== null;

        if (!$baseComplete) {
            return false;
        }

        // Vérification via l'entité Address
        if (!$this->address) {
            return false;
        }

        return $this->address->isValid();
    }

    /**
     * Détermine le type d'adresse utilisé
     */
    public function getAddressType(): ?string
    {
        return $this->address?->getAddressType();
    }

    // ==================== GETTERS & SETTERS STANDARDS ====================

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

    /** @param list<string> $roles */
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

    // ==================== GETTER & SETTER ADRESSE ====================

    public function getAddress(): ?Address
    {
        return $this->address;
    }

    public function setAddress(?Address $address): static
    {
        $this->address = $address;

        if ($address !== null && $address->getUser() !== $this) {
            $address->setUser($this);
        }

        return $this;
    }

    // ==================== AUTRES GETTERS & SETTERS ====================

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

    public function getSettings(): ?UserSettings
    {
        return $this->settings;
    }

    public function setSettings(UserSettings $settings): static
    {
        if ($settings->getUser() !== $this) {
            $settings->setUser($this);
        }
        $this->settings = $settings;
        return $this;
    }

    /** @return Collection<int, Voyage> */
    public function getVoyages(): Collection
    {
        return $this->voyages;
    }

    /** @return Collection<int, Demande> */
    public function getDemandes(): Collection
    {
        return $this->demandes;
    }

    /** @return Collection<int, Message> */
    public function getMessagesEnvoyes(): Collection
    {
        return $this->messagesEnvoyes;
    }

    /** @return Collection<int, Message> */
    public function getMessagesRecus(): Collection
    {
        return $this->messagesRecus;
    }

    /** @return Collection<int, Notification> */
    public function getNotifications(): Collection
    {
        return $this->notifications;
    }

    /** @return Collection<int, Favori> */
    public function getFavoris(): Collection
    {
        return $this->favoris;
    }

    /** @return Collection<int, Avis> */
    public function getAvisDonnes(): Collection
    {
        return $this->avisDonnes;
    }

    /** @return Collection<int, Avis> */
    public function getAvisRecus(): Collection
    {
        return $this->avisRecus;
    }

    /** @return Collection<int, Signalement> */
    public function getSignalements(): Collection
    {
        return $this->signalements;
    }

    /** @return Collection<int, Signalement> */
    public function getSignalementsRecus(): Collection
    {
        return $this->signalementsRecus;
    }

    /** @return Collection<int, Conversation> */
    public function getConversationsAsParticipant1(): Collection
    {
        return $this->conversationsAsParticipant1;
    }

    /** @return Collection<int, Conversation> */
    public function getConversationsAsParticipant2(): Collection
    {
        return $this->conversationsAsParticipant2;
    }

    /** @return Conversation[] */
    public function getAllConversations(): array
    {
        return array_merge(
            $this->conversationsAsParticipant1->toArray(),
            $this->conversationsAsParticipant2->toArray()
        );
    }

    public function isBanned(): bool
    {
        return $this->isBanned;
    }

    public function setIsBanned(bool $isBanned): static
    {
        $this->isBanned = $isBanned;
        if (!$isBanned) {
            $this->bannedAt = null;
            $this->banReason = null;
            $this->bannedBy = null;
            $this->bannedUntil = null;
        }
        return $this;
    }

    public function getBannedAt(): ?\DateTimeInterface
    {
        return $this->bannedAt;
    }

    public function setBannedAt(?\DateTimeInterface $bannedAt): static
    {
        $this->bannedAt = $bannedAt;
        return $this;
    }

    /**
     * Date de fin d'un bannissement temporaire - null pour un bannissement permanent.
     * L'expiration reelle est verifiee en lecture (BannedUserListener, a chaque requete,
     * effet immediat) ; le nettoyage de isBanned lui-meme est asynchrone
     * (ModerationService::expireBan(), planifie via ExpirationScheduleProvider).
     */
    public function getBannedUntil(): ?\DateTimeInterface
    {
        return $this->bannedUntil;
    }

    public function setBannedUntil(?\DateTimeInterface $bannedUntil): static
    {
        $this->bannedUntil = $bannedUntil;
        return $this;
    }

    public function isBanExpired(): bool
    {
        return $this->bannedUntil !== null && $this->bannedUntil <= new \DateTime();
    }

    public function getBanReason(): ?string
    {
        return $this->banReason;
    }

    public function setBanReason(?string $banReason): static
    {
        $this->banReason = $banReason;
        return $this;
    }

    public function getBannedBy(): ?User
    {
        return $this->bannedBy;
    }

    public function setBannedBy(?User $bannedBy): static
    {
        $this->bannedBy = $bannedBy;
        return $this;
    }

    public function ban(User $admin, string $reason, ?\DateTimeInterface $bannedUntil = null): static
    {
        $this->isBanned = true;
        $this->bannedAt = new \DateTime();
        $this->banReason = $reason;
        $this->bannedBy = $admin;
        $this->bannedUntil = $bannedUntil;
        return $this;
    }

    public function unban(): static
    {
        $this->isBanned = false;
        $this->bannedAt = null;
        $this->banReason = null;
        $this->bannedBy = null;
        $this->bannedUntil = null;
        return $this;
    }

    public function getDeletedAt(): ?\DateTimeImmutable
    {
        return $this->deletedAt;
    }

    public function setDeletedAt(?\DateTimeImmutable $deletedAt): static
    {
        $this->deletedAt = $deletedAt;
        return $this;
    }

    public function isDeleted(): bool
    {
        return $this->deletedAt !== null;
    }

    /**
     * @return Collection<int, RefreshToken>
     */
    public function getRefreshTokens(): Collection
    {
        return $this->refreshTokens;
    }

    public function addRefreshToken(RefreshToken $refreshToken): static
    {
        if (!$this->refreshTokens->contains($refreshToken)) {
            $this->refreshTokens->add($refreshToken);
            $refreshToken->setUser($this);
        }
        return $this;
    }

    public function removeRefreshToken(RefreshToken $refreshToken): static
    {
        // set the owning side to null (unless already changed)
        if ($this->refreshTokens->removeElement($refreshToken) && $refreshToken->getUser() === $this) {
            $refreshToken->setUser(null);
        }
        return $this;
    }
}
