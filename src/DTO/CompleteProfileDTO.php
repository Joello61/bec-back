<?php

declare(strict_types=1);

namespace App\DTO;

use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * DTO pour la complétion du profil utilisateur
 * Utilisé après l'inscription et la vérification email
 *
 * Supporte 2 formats d'adresse :
 * - Format Afrique : quartier (obligatoire)
 * - Format Diaspora : adresse postale normalisée (ligne1 + code postal obligatoires)
 */
class CompleteProfileDTO
{
    #[Assert\NotBlank(message: 'Le numéro de téléphone est obligatoire')]
    #[Assert\Regex(
        pattern: '/^\+?[1-9]\d{1,14}$/',
        message: 'Le numéro de téléphone n\'est pas valide. Format attendu: +237612345678 ou +33612345678'
    )]
    public string $telephone;

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

    // ==================== CHAMPS OPTIONNELS ====================

    #[Assert\File(
        maxSize: '5M',
        mimeTypes: ['image/jpeg', 'image/png', 'image/webp'],
        mimeTypesMessage: 'Formats acceptés : JPEG, PNG, WebP uniquement'
    )]
    public ?UploadedFile $photo = null;


    #[Assert\Length(
        max: 500,
        maxMessage: 'La bio ne peut pas dépasser {{ limit }} caractères'
    )]
    public ?string $bio = null;

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

    /**
     * Convertit le DTO en tableau pour AddressService
     */
    public function toAddressArray(): array
    {
        $data = [
            'pays' => $this->pays,
            'ville' => $this->ville,
        ];

        if ($this->quartier !== null) {
            $data['quartier'] = $this->quartier;
        }

        if ($this->adresseLigne1 !== null) {
            $data['adresseLigne1'] = $this->adresseLigne1;
        }

        if ($this->adresseLigne2 !== null) {
            $data['adresseLigne2'] = $this->adresseLigne2;
        }

        if ($this->codePostal !== null) {
            $data['codePostal'] = $this->codePostal;
        }

        return $data;
    }
}
