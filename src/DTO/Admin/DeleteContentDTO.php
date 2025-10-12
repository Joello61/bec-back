<?php

declare(strict_types=1);

namespace App\DTO\Admin;

use Symfony\Component\Validator\Constraints as Assert;

class DeleteContentDTO
{
    /**
     * Raison de la suppression du contenu
     */
    #[Assert\NotBlank(message: 'La raison de la suppression est obligatoire')]
    #[Assert\Length(
        min: 10,
        max: 500,
        minMessage: 'La raison doit contenir au moins {{ limit }} caractères',
        maxMessage: 'La raison ne peut pas dépasser {{ limit }} caractères'
    )]
    public string $reason;

    /**
     * Motif prédéfini de suppression (optionnel)
     * Permet de catégoriser les suppressions
     */
    #[Assert\Choice(
        choices: [
            'spam',
            'contenu_inapproprie',
            'harcèlement',
            'fraude',
            'fausses_informations',
            'violation_cgu',
            'doublon',
            'demande_utilisateur',
            'autre'
        ],
        message: 'Le motif "{{ value }}" n\'est pas valide'
    )]
    public ?string $motif = 'autre';

    /**
     * Notifier l'utilisateur propriétaire du contenu
     */
    #[Assert\Type(type: 'bool')]
    public bool $notifyUser = true;

    /**
     * Bannir l'utilisateur en même temps que la suppression
     * Utile pour les cas graves (spam, fraude, etc.)
     */
    #[Assert\Type(type: 'bool')]
    public bool $banUser = false;

    /**
     * Raison du bannissement si banUser = true
     */
    #[Assert\Expression(
        expression: 'this.banUser === false or this.banReason !== null',
        message: 'La raison du bannissement est obligatoire si vous bannissez l\'utilisateur'
    )]
    #[Assert\Length(
        min: 10,
        max: 500,
        minMessage: 'La raison du ban doit contenir au moins {{ limit }} caractères',
        maxMessage: 'La raison du ban ne peut pas dépasser {{ limit }} caractères'
    )]
    public ?string $banReason = null;

    /**
     * Supprimer tous les contenus similaires de cet utilisateur
     * Par exemple : supprimer tous les voyages si on supprime un voyage
     */
    #[Assert\Type(type: 'bool')]
    public bool $deleteAllUserContent = false;

    /**
     * Sévérité de l'action (pour les statistiques)
     */
    #[Assert\Choice(
        choices: ['low', 'medium', 'high', 'critical'],
        message: 'La sévérité doit être : low, medium, high ou critical'
    )]
    public string $severity = 'medium';

    /**
     * Notes internes pour les autres admins (non visible par l'utilisateur)
     */
    #[Assert\Length(
        max: 1000,
        maxMessage: 'Les notes internes ne peuvent pas dépasser {{ limit }} caractères'
    )]
    public ?string $internalNotes = null;

    /**
     * Vérifie si la suppression est considérée comme grave
     */
    public function isSevere(): bool
    {
        return in_array($this->severity, ['high', 'critical'], true) || $this->banUser;
    }

    /**
     * Vérifie si c'est un cas de spam
     */
    public function isSpam(): bool
    {
        return $this->motif === 'spam';
    }

    /**
     * Vérifie si c'est une fraude
     */
    public function isFraud(): bool
    {
        return $this->motif === 'fraude';
    }

    /**
     * Vérifie si le contenu viole les CGU
     */
    public function violatesTOS(): bool
    {
        return $this->motif === 'violation_cgu';
    }

    /**
     * Retourne un message formaté pour la notification utilisateur
     */
    public function getNotificationMessage(string $contentType): string
    {
        $messages = [
            'spam' => 'Votre contenu a été supprimé car il a été identifié comme spam.',
            'contenu_inapproprie' => 'Votre contenu a été supprimé car il contient du contenu inapproprié.',
            'harcèlement' => 'Votre contenu a été supprimé car il constitue du harcèlement.',
            'fraude' => 'Votre contenu a été supprimé pour suspicion de fraude.',
            'fausses_informations' => 'Votre contenu a été supprimé car il contient de fausses informations.',
            'violation_cgu' => 'Votre contenu a été supprimé pour violation de nos Conditions Générales d\'Utilisation.',
            'doublon' => 'Votre contenu a été supprimé car il s\'agit d\'un doublon.',
            'demande_utilisateur' => 'Votre contenu a été supprimé suite à votre demande.',
            'autre' => 'Votre contenu a été supprimé par la modération.',
        ];

        $baseMessage = $messages[$this->motif] ?? $messages['autre'];

        return sprintf(
            '%s Raison : %s',
            $baseMessage,
            $this->reason
        );
    }

    /**
     * Retourne les données pour le log admin
     */
    public function getLogData(): array
    {
        return [
            'reason' => $this->reason,
            'motif' => $this->motif,
            'severity' => $this->severity,
            'banUser' => $this->banUser,
            'banReason' => $this->banReason,
            'deleteAllUserContent' => $this->deleteAllUserContent,
            'internalNotes' => $this->internalNotes,
        ];
    }
}
