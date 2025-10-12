<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\CurrencyRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Annotation\Groups;

#[ORM\Entity(repositoryClass: CurrencyRepository::class)]
#[ORM\Table(name: 'currencies')]
#[ORM\HasLifecycleCallbacks]
class Currency
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    #[Groups(['currency:read'])]
    private ?int $id = null;

    /**
     * Code ISO 4217 de la devise (EUR, USD, XAF, CAD, etc.)
     */
    #[ORM\Column(length: 3, unique: true)]
    #[Groups(['currency:read', 'demande:read', 'voyage:read', 'proposition:read'])]
    private ?string $code = null;

    /**
     * Nom complet de la devise
     */
    #[ORM\Column(length: 100)]
    #[Groups(['currency:read'])]
    private ?string $name = null;

    /**
     * Symbole de la devise (€, $, FCFA, etc.)
     */
    #[ORM\Column(length: 10)]
    #[Groups(['currency:read', 'demande:read', 'voyage:read', 'proposition:read'])]
    private ?string $symbol = null;

    /**
     * Nombre de décimales utilisées (2 pour EUR/USD, 0 pour XAF)
     */
    #[ORM\Column(type: Types::SMALLINT)]
    #[Groups(['currency:read'])]
    private int $decimals = 2;

    /**
     * Taux de change par rapport à la devise de référence (EUR = 1)
     * Mis à jour quotidiennement via API
     */
    #[ORM\Column(type: Types::DECIMAL, precision: 12, scale: 6)]
    #[Groups(['currency:read'])]
    private ?string $exchangeRate = '1.000000';

    /**
     * Date de la dernière mise à jour du taux de change
     */
    #[ORM\Column(type: Types::DATETIME_MUTABLE, nullable: true)]
    #[Groups(['currency:read'])]
    private ?\DateTimeInterface $rateUpdatedAt = null;

    /**
     * Indique si la devise est active/supportée
     */
    #[ORM\Column(type: Types::BOOLEAN)]
    #[Groups(['currency:read'])]
    private bool $isActive = true;

    /**
     * Liste des pays utilisant cette devise (JSON)
     * Ex: ["FR", "BE", "LU"] pour EUR
     */
    #[ORM\Column(type: Types::JSON)]
    #[Groups(['currency:read'])]
    private array $countries = [];

    #[ORM\Column(type: Types::DATETIME_MUTABLE)]
    #[Groups(['currency:read'])]
    private ?\DateTimeInterface $createdAt = null;

    #[ORM\Column(type: Types::DATETIME_MUTABLE)]
    #[Groups(['currency:read'])]
    private ?\DateTimeInterface $updatedAt = null;

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

    // ==================== MÉTHODES UTILES ====================

    /**
     * Vérifie si le taux de change est récent (< 24h)
     */
    public function isExchangeRateFresh(): bool
    {
        if (!$this->rateUpdatedAt) {
            return false;
        }

        $yesterday = new \DateTime('-24 hours');
        return $this->rateUpdatedAt > $yesterday;
    }

    /**
     * Formate un montant selon la devise
     */
    public function formatAmount(float $amount): string
    {
        if ($this->decimals === 0) {
            return number_format($amount, 0, '', ' ') . ' ' . $this->symbol;
        }

        return number_format($amount, $this->decimals, ',', ' ') . ' ' . $this->symbol;
    }

    /**
     * Vérifie si un pays utilise cette devise
     */
    public function isUsedInCountry(string $countryCode): bool
    {
        return in_array(strtoupper($countryCode), $this->countries, true);
    }

    // ==================== GETTERS & SETTERS ====================

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getCode(): ?string
    {
        return $this->code;
    }

    public function setCode(string $code): static
    {
        $this->code = strtoupper($code);
        return $this;
    }

    public function getName(): ?string
    {
        return $this->name;
    }

    public function setName(string $name): static
    {
        $this->name = $name;
        return $this;
    }

    public function getSymbol(): ?string
    {
        return $this->symbol;
    }

    public function setSymbol(string $symbol): static
    {
        $this->symbol = $symbol;
        return $this;
    }

    public function getDecimals(): int
    {
        return $this->decimals;
    }

    public function setDecimals(int $decimals): static
    {
        $this->decimals = $decimals;
        return $this;
    }

    public function getExchangeRate(): ?string
    {
        return $this->exchangeRate;
    }

    public function setExchangeRate(string $exchangeRate): static
    {
        $this->exchangeRate = $exchangeRate;
        return $this;
    }

    public function getRateUpdatedAt(): ?\DateTimeInterface
    {
        return $this->rateUpdatedAt;
    }

    public function setRateUpdatedAt(?\DateTimeInterface $rateUpdatedAt): static
    {
        $this->rateUpdatedAt = $rateUpdatedAt;
        return $this;
    }

    public function isActive(): bool
    {
        return $this->isActive;
    }

    public function setIsActive(bool $isActive): static
    {
        $this->isActive = $isActive;
        return $this;
    }

    public function getCountries(): array
    {
        return $this->countries;
    }

    public function setCountries(array $countries): static
    {
        $this->countries = array_map('strtoupper', $countries);
        return $this;
    }

    public function addCountry(string $countryCode): static
    {
        $code = strtoupper($countryCode);
        if (!in_array($code, $this->countries, true)) {
            $this->countries[] = $code;
        }
        return $this;
    }

    public function removeCountry(string $countryCode): static
    {
        $this->countries = array_filter(
            $this->countries,
            fn($c) => $c !== strtoupper($countryCode)
        );
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
