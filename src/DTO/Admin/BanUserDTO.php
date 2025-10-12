<?php

declare(strict_types=1);

namespace App\DTO\Admin;

use Exception;
use Symfony\Component\Validator\Constraints as Assert;

class BanUserDTO
{
    /**
     * Raison du bannissement
     */
    #[Assert\NotBlank(message: 'La raison du bannissement est obligatoire')]
    #[Assert\Length(
        min: 10,
        max: 500,
        minMessage: 'La raison doit contenir au moins {{ limit }} caractères',
        maxMessage: 'La raison ne peut pas dépasser {{ limit }} caractères'
    )]
    public string $reason;

    /**
     * Type de bannissement
     * - permanent : bannissement définitif
     * - temporary : bannissement temporaire avec date de fin
     */
    #[Assert\NotBlank(message: 'Le type de bannissement est obligatoire')]
    #[Assert\Choice(
        choices: ['permanent', 'temporary'],
        message: 'Le type doit être "permanent" ou "temporary"'
    )]
    public string $type = 'permanent';

    /**
     * Date de fin du bannissement (uniquement si type = temporary)
     * Format ISO 8601 : 2025-12-31T23:59:59+00:00
     */
    #[Assert\Expression(
        expression: 'this.type !== "temporary" or this.bannedUntil !== null',
        message: 'La date de fin est obligatoire pour un bannissement temporaire'
    )]
    #[Assert\Expression(
        expression: 'this.bannedUntil === null or this.bannedUntil > this.now()',
        message: 'La date de fin doit être dans le futur'
    )]
    public ?\DateTimeInterface $bannedUntil = null;

    /**
     * Notifier l'utilisateur par email
     */
    #[Assert\Type(type: 'bool')]
    public bool $notifyUser = true;

    /**
     * Supprimer tous les contenus de l'utilisateur
     */
    #[Assert\Type(type: 'bool')]
    public bool $deleteContent = false;

    /**
     * Méthode helper pour la validation
     */
    public function now(): \DateTimeInterface
    {
        return new \DateTime();
    }

    /**
     * Convertit la date string en DateTimeInterface si nécessaire
     * @throws Exception
     */
    public function setBannedUntil(string|\DateTimeInterface|null $date): void
    {
        if (is_string($date)) {
            $this->bannedUntil = new \DateTime($date);
        } else {
            $this->bannedUntil = $date;
        }
    }
}
