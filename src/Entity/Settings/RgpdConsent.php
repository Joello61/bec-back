<?php

declare(strict_types=1);

namespace App\Entity\Settings;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Embeddable]
class RgpdConsent
{
    #[ORM\Column(type: Types::BOOLEAN)]
    private bool $cookiesConsent = false;

    #[ORM\Column(type: Types::BOOLEAN)]
    private bool $analyticsConsent = false;

    #[ORM\Column(type: Types::BOOLEAN)]
    private bool $marketingConsent = false;

    #[ORM\Column(type: Types::BOOLEAN)]
    private bool $dataShareConsent = false;

    #[ORM\Column(type: Types::DATETIME_MUTABLE, nullable: true)]
    private ?\DateTimeInterface $consentDate = null;

    public function isCookiesConsent(): bool
    {
        return $this->cookiesConsent;
    }

    public function setCookiesConsent(bool $cookiesConsent): static
    {
        $this->cookiesConsent = $cookiesConsent;
        if ($cookiesConsent && !$this->consentDate) {
            $this->consentDate = new \DateTime();
        }
        return $this;
    }

    public function isAnalyticsConsent(): bool
    {
        return $this->analyticsConsent;
    }

    public function setAnalyticsConsent(bool $analyticsConsent): static
    {
        $this->analyticsConsent = $analyticsConsent;
        return $this;
    }

    public function isMarketingConsent(): bool
    {
        return $this->marketingConsent;
    }

    public function setMarketingConsent(bool $marketingConsent): static
    {
        $this->marketingConsent = $marketingConsent;
        return $this;
    }

    public function isDataShareConsent(): bool
    {
        return $this->dataShareConsent;
    }

    public function setDataShareConsent(bool $dataShareConsent): static
    {
        $this->dataShareConsent = $dataShareConsent;
        return $this;
    }

    public function getConsentDate(): ?\DateTimeInterface
    {
        return $this->consentDate;
    }
}
