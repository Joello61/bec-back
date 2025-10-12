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
    #[Groups(['user:read', 'voyage:read', 'voyage:list', 'demande:read', 'demande:list', 'message:read', 'avis:read', 'proposition:list', 'message:list', 'conversation:list', 'conversation:read', 'favori:read', 'favori:list', 'admin:user:list', 'admin:user:read'])]
    private ?int $id = null;

    #[ORM\Column(length: 180, unique: true)]
    #[Groups(['user:read', 'user:write', 'voyage:read', 'voyage:list', 'demande:read', 'demande:list', 'admin:user:list', 'admin:user:read'])]
    private ?string $email = null;

    #[ORM\Column]
    #[Groups(['admin:user:list','admin:user:read'])]
    private array $roles = [];

    #[ORM\Column(nullable: true)]
    private ?string $password = null;

    #[ORM\Column(length: 100)]
    #[Groups(['user:read', 'user:write', 'voyage:read', 'voyage:list', 'demande:read', 'demande:list', 'message:read', 'proposition:list', 'message:list', 'conversation:list', 'conversation:read', 'favori:read', 'favori:list', 'signalement:list', 'admin:user:list', 'admin:user:read'])]
    private ?string $nom = null;

    #[ORM\Column(length: 100)]
    #[Groups(['user:read', 'user:write', 'voyage:read', 'voyage:list', 'demande:read', 'demande:list', 'message:read', 'proposition:list', 'message:list', 'conversation:list', 'conversation:read', 'favori:read', 'favori:list', 'signalement:list', 'admin:user:list', 'admin:user:read'])]
    private ?string $prenom = null;

    #[ORM\Column(length: 20, nullable: true)]
    #[Groups(['user:read', 'user:write', 'voyage:read', 'voyage:list', 'demande:read', 'demande:list', 'admin:user:read'])]
    private ?string $telephone = null;

    // ==================== NOUVEAUX CHAMPS ADRESSE ====================

    #[ORM\Column(length: 100, nullable: true)]
    #[Groups(['user:read', 'user:write', 'voyage:read', 'demande:read', 'admin:user:read'])]
    private ?string $pays = null;

    #[ORM\Column(length: 100, nullable: true)]
    #[Groups(['user:read', 'user:write', 'voyage:read', 'demande:read', 'admin:user:read'])]
    private ?string $ville = null;

    // Format Afrique : Quartier (ex: Bastos, Bonanjo)
    #[ORM\Column(length: 100, nullable: true)]
    #[Groups(['user:read', 'user:write', 'voyage:read', 'demande:read', 'admin:user:read'])]
    private ?string $quartier = null;

    // Format Diaspora : Adresse postale normalisée
    #[ORM\Column(length: 255, nullable: true)]
    #[Groups(['user:read', 'user:write', 'admin:user:read'])]
    private ?string $adresseLigne1 = null; // Ex: "21 rue du Cher"

    #[ORM\Column(length: 255, nullable: true)]
    #[Groups(['user:read', 'user:write', 'admin:user:read'])]
    private ?string $adresseLigne2 = null; // Ex: "Appartement 3B"

    #[ORM\Column(length: 20, nullable: true)]
    #[Groups(['user:read', 'user:write', 'admin:user:read'])]
    private ?string $codePostal = null; // Ex: "31100"

    // ==================== FIN NOUVEAUX CHAMPS ====================

    #[ORM\Column(length: 255, nullable: true)]
    #[Groups(['user:read', 'user:write', 'voyage:read', 'voyage:list', 'demande:read', 'demande:list', 'proposition:list', 'message:list', 'conversation:read'])]
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

    #[ORM\OneToMany(targetEntity: Signalement::class, mappedBy: 'utilisateurSignale')]
    private Collection $signalementsRecus;

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
     *
     * Adresse valide = SOIT format Afrique (quartier) SOIT format Diaspora (adresse postale)
     */
    public function isProfileComplete(): bool
    {
        $baseComplete = $this->emailVerifie
            && $this->telephoneVerifie
            && $this->telephone !== null
            && $this->pays !== null
            && $this->ville !== null;

        if (!$baseComplete) {
            return false;
        }

        // Vérifier qu'au moins un format d'adresse est complet
        $africanFormat = $this->quartier !== null;
        $diasporaFormat = $this->adresseLigne1 !== null && $this->codePostal !== null;

        return $africanFormat || $diasporaFormat;
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

    // ==================== GETTERS & SETTERS ADRESSE ====================

    public function getPays(): ?string
    {
        return $this->pays;
    }

    public function setPays(?string $pays): static
    {
        $this->pays = $pays;
        return $this;
    }

    public function getVille(): ?string
    {
        return $this->ville;
    }

    public function setVille(?string $ville): static
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

    public function getSignalementsRecus(): Collection
    {
        return $this->signalementsRecus;
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

    public function ban(User $admin, string $reason): static
    {
        $this->isBanned = true;
        $this->bannedAt = new \DateTime();
        $this->banReason = $reason;
        $this->bannedBy = $admin;
        return $this;
    }

    public function unban(): static
    {
        $this->isBanned = false;
        $this->bannedAt = null;
        $this->banReason = null;
        $this->bannedBy = null;
        return $this;
    }
}
