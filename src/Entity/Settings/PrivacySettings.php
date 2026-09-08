<?php

declare(strict_types=1);

namespace App\Entity\Settings;

use App\Entity\User;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Embeddable]
class PrivacySettings
{
    #[ORM\Column(type: Types::STRING, length: 20)]
    private string $profileVisibility = 'public'; // 'public', 'verified_only', 'private'

    #[ORM\Column(type: Types::BOOLEAN)]
    private bool $showPhone = true;

    #[ORM\Column(type: Types::BOOLEAN)]
    private bool $showEmail = false;

    #[ORM\Column(type: Types::BOOLEAN)]
    private bool $showStats = true;

    #[ORM\Column(type: Types::STRING, length: 20)]
    private string $messagePermission = 'everyone'; // 'everyone', 'verified_only', 'no_one'

    #[ORM\Column(type: Types::BOOLEAN)]
    private bool $showInSearchResults = true;

    #[ORM\Column(type: Types::BOOLEAN)]
    private bool $showLastSeen = true;

    public function getProfileVisibility(): string
    {
        return $this->profileVisibility;
    }

    public function setProfileVisibility(string $profileVisibility): static
    {
        $this->profileVisibility = $profileVisibility;
        return $this;
    }

    public function isShowPhone(): bool
    {
        return $this->showPhone;
    }

    public function setShowPhone(bool $showPhone): static
    {
        $this->showPhone = $showPhone;
        return $this;
    }

    public function isShowEmail(): bool
    {
        return $this->showEmail;
    }

    public function setShowEmail(bool $showEmail): static
    {
        $this->showEmail = $showEmail;
        return $this;
    }

    public function isShowStats(): bool
    {
        return $this->showStats;
    }

    public function setShowStats(bool $showStats): static
    {
        $this->showStats = $showStats;
        return $this;
    }

    public function getMessagePermission(): string
    {
        return $this->messagePermission;
    }

    public function setMessagePermission(string $messagePermission): static
    {
        $this->messagePermission = $messagePermission;
        return $this;
    }

    public function isShowInSearchResults(): bool
    {
        return $this->showInSearchResults;
    }

    public function setShowInSearchResults(bool $showInSearchResults): static
    {
        $this->showInSearchResults = $showInSearchResults;
        return $this;
    }

    public function isShowLastSeen(): bool
    {
        return $this->showLastSeen;
    }

    public function setShowLastSeen(bool $showLastSeen): static
    {
        $this->showLastSeen = $showLastSeen;
        return $this;
    }

    public function canReceiveMessageFrom(User $sender): bool
    {
        if ($this->messagePermission === 'no_one') {
            return false;
        }

        if ($this->messagePermission === 'verified_only') {
            return $sender->isEmailVerifie() && $sender->isTelephoneVerifie();
        }

        return true; // everyone
    }

    public function isProfileVisibleFor(?User $viewer): bool
    {
        if ($this->profileVisibility === 'public') {
            return true;
        }

        if (!$viewer) {
            return false;
        }

        if ($this->profileVisibility === 'verified_only') {
            return $viewer->isEmailVerifie() && $viewer->isTelephoneVerifie();
        }

        return false; // private
    }
}
