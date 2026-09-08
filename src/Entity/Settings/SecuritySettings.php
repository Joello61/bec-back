<?php

declare(strict_types=1);

namespace App\Entity\Settings;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Embeddable]
class SecuritySettings
{
    #[ORM\Column(type: Types::BOOLEAN)]
    private bool $twoFactorEnabled = false;

    #[ORM\Column(type: Types::BOOLEAN)]
    private bool $loginNotifications = true;

    public function isTwoFactorEnabled(): bool
    {
        return $this->twoFactorEnabled;
    }

    public function setTwoFactorEnabled(bool $twoFactorEnabled): static
    {
        $this->twoFactorEnabled = $twoFactorEnabled;
        return $this;
    }

    public function isLoginNotifications(): bool
    {
        return $this->loginNotifications;
    }

    public function setLoginNotifications(bool $loginNotifications): static
    {
        $this->loginNotifications = $loginNotifications;
        return $this;
    }
}
