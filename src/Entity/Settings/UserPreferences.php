<?php

declare(strict_types=1);

namespace App\Entity\Settings;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Embeddable]
class UserPreferences
{
    #[ORM\Column(type: Types::STRING, length: 40)]
    private string $langue = 'fr'; // 'fr', 'en'

    #[ORM\Column(type: Types::STRING, length: 3)]
    private string $devise = 'EUR'; // 'XAF', 'EUR', 'USD'

    #[ORM\Column(type: Types::STRING, length: 50)]
    private string $timezone = 'Africa/Douala';

    #[ORM\Column(type: Types::STRING, length: 10)]
    private string $dateFormat = 'dd/MM/yyyy'; // 'dd/MM/yyyy', 'MM/dd/yyyy', 'yyyy-MM-dd'

    public function getLangue(): string
    {
        return $this->langue;
    }

    public function setLangue(string $langue): static
    {
        $this->langue = $langue;
        return $this;
    }

    public function getDevise(): string
    {
        return $this->devise;
    }

    public function setDevise(string $devise): static
    {
        $this->devise = $devise;
        return $this;
    }

    public function getTimezone(): string
    {
        return $this->timezone;
    }

    public function setTimezone(string $timezone): static
    {
        $this->timezone = $timezone;
        return $this;
    }

    public function getDateFormat(): string
    {
        return $this->dateFormat;
    }

    public function setDateFormat(string $dateFormat): static
    {
        $this->dateFormat = $dateFormat;
        return $this;
    }
}
