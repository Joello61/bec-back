<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\CityRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Annotation\Groups;

#[ORM\Entity(repositoryClass: CityRepository::class)]
#[ORM\Table(name: 'cities')]
#[ORM\Index(name: 'idx_city_name', columns: ['name'])]
#[ORM\Index(name: 'idx_city_country', columns: ['country_id'])]
#[ORM\Index(name: 'idx_city_search', columns: ['country_id', 'name'])]
class City
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    #[Groups(['city:read', 'city:list'])]
    private ?int $id = null;

    /**
     * ID GeoNames (pour référence)
     */
    #[ORM\Column(nullable: true)]
    #[Groups(['city:read'])]
    private ?int $geonameId = null;

    #[ORM\Column(length: 200)]
    #[Groups(['city:read', 'city:list'])]
    private string $name;

    /**
     * Nom alternatif (en français si disponible)
     */
    #[ORM\Column(length: 200, nullable: true)]
    #[Groups(['city:read', 'city:list'])]
    private ?string $alternateName = null;

    #[ORM\ManyToOne(targetEntity: Country::class, inversedBy: 'cities')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    #[Groups(['city:read', 'city:list'])]
    private ?Country $country = null;

    /**
     * Code région/état (admin1)
     */
    #[ORM\Column(length: 20, nullable: true)]
    #[Groups(['city:read'])]
    private ?string $admin1Code = null;

    /**
     * Nom de la région/état
     */
    #[ORM\Column(length: 100, nullable: true)]
    #[Groups(['city:read', 'city:list'])]
    private ?string $admin1Name = null;

    /**
     * Latitude
     */
    #[ORM\Column(type: 'decimal', precision: 10, scale: 7, nullable: true)]
    #[Groups(['city:read'])]
    private ?string $latitude = null;

    /**
     * Longitude
     */
    #[ORM\Column(type: 'decimal', precision: 10, scale: 7, nullable: true)]
    #[Groups(['city:read'])]
    private ?string $longitude = null;

    /**
     * Population (pour tri par pertinence)
     */
    #[ORM\Column(nullable: true)]
    #[Groups(['city:read'])]
    private ?int $population = null;

    /**
     * Timezone
     */
    #[ORM\Column(length: 50, nullable: true)]
    #[Groups(['city:read'])]
    private ?string $timezone = null;

    // ==================== GETTERS & SETTERS ====================

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getGeonameId(): ?int
    {
        return $this->geonameId;
    }

    public function setGeonameId(?int $geonameId): static
    {
        $this->geonameId = $geonameId;
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

    public function getAlternateName(): ?string
    {
        return $this->alternateName;
    }

    public function setAlternateName(?string $alternateName): static
    {
        $this->alternateName = $alternateName;
        return $this;
    }

    public function getCountry(): ?Country
    {
        return $this->country;
    }

    public function setCountry(?Country $country): static
    {
        $this->country = $country;
        return $this;
    }

    public function getAdmin1Code(): ?string
    {
        return $this->admin1Code;
    }

    public function setAdmin1Code(?string $admin1Code): static
    {
        $this->admin1Code = $admin1Code;
        return $this;
    }

    public function getAdmin1Name(): ?string
    {
        return $this->admin1Name;
    }

    public function setAdmin1Name(?string $admin1Name): static
    {
        $this->admin1Name = $admin1Name;
        return $this;
    }

    public function getLatitude(): ?string
    {
        return $this->latitude;
    }

    public function setLatitude(?string $latitude): static
    {
        $this->latitude = $latitude;
        return $this;
    }

    public function getLongitude(): ?string
    {
        return $this->longitude;
    }

    public function setLongitude(?string $longitude): static
    {
        $this->longitude = $longitude;
        return $this;
    }

    public function getPopulation(): ?int
    {
        return $this->population;
    }

    public function setPopulation(?int $population): static
    {
        $this->population = $population;
        return $this;
    }

    public function getTimezone(): ?string
    {
        return $this->timezone;
    }

    public function setTimezone(?string $timezone): static
    {
        $this->timezone = $timezone;
        return $this;
    }

    /**
     * Retourne le nom à afficher (alternatif si dispo, sinon original)
     */
    public function getDisplayName(): string
    {
        return $this->alternateName ?? $this->name;
    }
}
