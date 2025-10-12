<?php

declare(strict_types=1);

namespace App\DTO;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * DTO pour la mise à jour de l'adresse utilisateur
 * Soumis à la contrainte des 6 mois
 */
class UpdateAddressDTO
{
    #[Assert\NotBlank(message: 'Le pays est obligatoire')]
    #[Assert\Length(
        min: 2,
        max: 100,
        minMessage: 'Le pays doit contenir au moins {{ limit }} caractères',
        maxMessage: 'Le pays ne peut pas dépasser {{ limit }} caractères'
    )]
    public string $pays;

    #[Assert\NotBlank(message: 'La ville est obligatoire')]
    #[Assert\Length(
        min: 2,
        max: 100,
        minMessage: 'La ville doit contenir au moins {{ limit }} caractères',
        maxMessage: 'La ville ne peut pas dépasser {{ limit }} caractères'
    )]
    public string $ville;

    // ==================== FORMAT AFRIQUE ====================

    #[Assert\Length(
        min: 2,
        max: 100,
        minMessage: 'Le quartier doit contenir au moins {{ limit }} caractères',
        maxMessage: 'Le quartier ne peut pas dépasser {{ limit }} caractères'
    )]
    public ?string $quartier = null;

    // ==================== FORMAT DIASPORA ====================

    #[Assert\Length(
        max: 255,
        maxMessage: 'L\'adresse ne peut pas dépasser {{ limit }} caractères'
    )]
    public ?string $adresseLigne1 = null;

    #[Assert\Length(
        max: 255,
        maxMessage: 'L\'adresse (ligne 2) ne peut pas dépasser {{ limit }} caractères'
    )]
    public ?string $adresseLigne2 = null;

    #[Assert\Length(
        max: 20,
        maxMessage: 'Le code postal ne peut pas dépasser {{ limit }} caractères'
    )]
    public ?string $codePostal = null;

    /**
     * Validation personnalisée : au moins un format d'adresse doit être complet
     */
    #[Assert\IsTrue(
        message: 'Vous devez fournir soit un quartier (Afrique), soit une adresse complète (ligne 1 + code postal) pour la diaspora'
    )]
    public function isAddressValid(): bool
    {
        $africanFormat = !empty($this->quartier);
        $diasporaFormat = !empty($this->adresseLigne1) && !empty($this->codePostal);

        return $africanFormat || $diasporaFormat;
    }
}
