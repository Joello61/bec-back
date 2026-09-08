<?php

declare(strict_types=1);

namespace App\Entity\Settings;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Embeddable]
class NotificationPreferences
{
    #[ORM\Column(type: Types::BOOLEAN)]
    private bool $emailNotificationsEnabled = true;

    #[ORM\Column(type: Types::BOOLEAN)]
    private bool $smsNotificationsEnabled = true;

    #[ORM\Column(type: Types::BOOLEAN)]
    private bool $pushNotificationsEnabled = true;

    #[ORM\Column(type: Types::BOOLEAN)]
    private bool $notifyOnNewMessage = true;

    #[ORM\Column(type: Types::BOOLEAN)]
    private bool $notifyOnMatchingVoyage = true;

    #[ORM\Column(type: Types::BOOLEAN)]
    private bool $notifyOnMatchingDemande = true;

    #[ORM\Column(type: Types::BOOLEAN)]
    private bool $notifyOnNewAvis = true;

    #[ORM\Column(type: Types::BOOLEAN)]
    private bool $notifyOnFavoriUpdate = true;

    public function isEmailNotificationsEnabled(): bool
    {
        return $this->emailNotificationsEnabled;
    }

    public function setEmailNotificationsEnabled(bool $emailNotificationsEnabled): static
    {
        $this->emailNotificationsEnabled = $emailNotificationsEnabled;
        return $this;
    }

    public function isSmsNotificationsEnabled(): bool
    {
        return $this->smsNotificationsEnabled;
    }

    public function setSmsNotificationsEnabled(bool $smsNotificationsEnabled): static
    {
        $this->smsNotificationsEnabled = $smsNotificationsEnabled;
        return $this;
    }

    public function isPushNotificationsEnabled(): bool
    {
        return $this->pushNotificationsEnabled;
    }

    public function setPushNotificationsEnabled(bool $pushNotificationsEnabled): static
    {
        $this->pushNotificationsEnabled = $pushNotificationsEnabled;
        return $this;
    }

    public function isNotifyOnNewMessage(): bool
    {
        return $this->notifyOnNewMessage;
    }

    public function setNotifyOnNewMessage(bool $notifyOnNewMessage): static
    {
        $this->notifyOnNewMessage = $notifyOnNewMessage;
        return $this;
    }

    public function isNotifyOnMatchingVoyage(): bool
    {
        return $this->notifyOnMatchingVoyage;
    }

    public function setNotifyOnMatchingVoyage(bool $notifyOnMatchingVoyage): static
    {
        $this->notifyOnMatchingVoyage = $notifyOnMatchingVoyage;
        return $this;
    }

    public function isNotifyOnMatchingDemande(): bool
    {
        return $this->notifyOnMatchingDemande;
    }

    public function setNotifyOnMatchingDemande(bool $notifyOnMatchingDemande): static
    {
        $this->notifyOnMatchingDemande = $notifyOnMatchingDemande;
        return $this;
    }

    public function isNotifyOnNewAvis(): bool
    {
        return $this->notifyOnNewAvis;
    }

    public function setNotifyOnNewAvis(bool $notifyOnNewAvis): static
    {
        $this->notifyOnNewAvis = $notifyOnNewAvis;
        return $this;
    }

    public function isNotifyOnFavoriUpdate(): bool
    {
        return $this->notifyOnFavoriUpdate;
    }

    public function setNotifyOnFavoriUpdate(bool $notifyOnFavoriUpdate): static
    {
        $this->notifyOnFavoriUpdate = $notifyOnFavoriUpdate;
        return $this;
    }

    public function canReceiveEmails(): bool
    {
        return $this->emailNotificationsEnabled;
    }

    public function canReceiveSms(): bool
    {
        return $this->smsNotificationsEnabled;
    }

    public function canReceivePushNotifications(): bool
    {
        return $this->pushNotificationsEnabled;
    }
}
