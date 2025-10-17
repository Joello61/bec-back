<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\CountryRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Annotation\Groups;

#[ORM\Entity(repositoryClass: CountryRepository::class)]
#[ORM\Table(name: 'countries')]
#[ORM\Index(name: 'idx_country_code', columns: ['code'])]
#[ORM\Index(name: 'idx_country_name', columns: ['name'])]
class Country
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    #[Groups(['country:read', 'city:read'])]
    private ?int $id = null;

    /**
     * Code ISO 3166-1 alpha-2 (ex: FR, CM, US)
     */
    #[ORM\Column(length: 2, unique: true)]
    #[Groups(['country:read', 'country:list', 'city:read'])]
    private string $code;

    /**
     * Nom du pays en anglais
     */
    #[ORM\Column(length: 100)]
    #[Groups(['country:read', 'city:read'])]
    private string $name;

    /**
     * Nom du pays en français
     */
    #[ORM\Column(length: 100, nullable: true)]
    #[Groups(['country:read', 'country:list', 'city:read'])]
    private ?string $nameFr = null;

    /**
     * Code ISO 3166-1 alpha-3 (ex: FRA, CMR, USA)
     */
    #[ORM\Column(length: 3, nullable: true)]
    #[Groups(['country:read'])]
    private ?string $iso3 = null;

    /**
     * Continent (AF, EU, AS, NA, SA, OC, AN)
     */
    #[ORM\Column(length: 2, nullable: true)]
    #[Groups(['country:read'])]
    private ?string $continent = null;

    /**
     * Capitale
     */
    #[ORM\Column(length: 100, nullable: true)]
    #[Groups(['country:read'])]
    private ?string $capital = null;

    /**
     * Langues parlées (codes ISO 639, séparés par virgule)
     */
    #[ORM\Column(length: 200, nullable: true)]
    #[Groups(['country:read'])]
    private ?string $languages = null;

    /**
     * Code de devise (ISO 4217)
     */
    #[ORM\Column(length: 3, nullable: true)]
    #[Groups(['country:read'])]
    private ?string $currencyCode = null;

    /**
     * Indicatif téléphonique
     */
    #[ORM\Column(length: 20, nullable: true)]
    #[Groups(['country:read'])]
    private ?string $phoneCode = null;

    #[ORM\OneToMany(targetEntity: City::class, mappedBy: 'country', cascade: ['remove'])]
    private Collection $cities;

    public function __construct()
    {
        $this->cities = new ArrayCollection();
    }

    // ==================== GETTERS & SETTERS ====================

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getCode(): string
    {
        return $this->code;
    }

    public function setCode(string $code): static
    {
        $this->code = strtoupper($code);
        return $this;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): static
    {
        $this->name = $name;
        return $this;
    }

    public function getNameFr(): ?string
    {
        return $this->nameFr;
    }

    public function setNameFr(?string $nameFr): static
    {
        $this->nameFr = $nameFr;
        return $this;
    }

    public function getIso3(): ?string
    {
        return $this->iso3;
    }

    public function setIso3(?string $iso3): static
    {
        $this->iso3 = $iso3 ? strtoupper($iso3) : null;
        return $this;
    }

    public function getContinent(): ?string
    {
        return $this->continent;
    }

    public function setContinent(?string $continent): static
    {
        $this->continent = $continent;
        return $this;
    }

    public function getCapital(): ?string
    {
        return $this->capital;
    }

    public function setCapital(?string $capital): static
    {
        $this->capital = $capital;
        return $this;
    }

    public function getLanguages(): ?string
    {
        return $this->languages;
    }

    public function setLanguages(?string $languages): static
    {
        $this->languages = $languages;
        return $this;
    }

    public function getCurrencyCode(): ?string
    {
        return $this->currencyCode;
    }

    public function setCurrencyCode(?string $currencyCode): static
    {
        $this->currencyCode = $currencyCode;
        return $this;
    }

    public function getPhoneCode(): ?string
    {
        return $this->phoneCode;
    }

    public function setPhoneCode(?string $phoneCode): static
    {
        $this->phoneCode = $phoneCode;
        return $this;
    }

    public function getCities(): Collection
    {
        return $this->cities;
    }

    public function addCity(City $city): static
    {
        if (!$this->cities->contains($city)) {
            $this->cities->add($city);
            $city->setCountry($this);
        }

        return $this;
    }

    public function removeCity(City $city): static
    {
        if ($this->cities->removeElement($city)) {
            if ($city->getCountry() === $this) {
                $city->setCountry(null);
            }
        }

        return $this;
    }
}
