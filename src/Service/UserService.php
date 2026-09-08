<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Cycle de vie du compte utilisateur (Phase 5 du plan de correction).
 *
 * Un User n'est plus jamais physiquement supprime (App\Entity\User n'a plus de
 * cascade:['remove'] sur ses collections) : ce service centralise la suppression de compte
 * en soft-delete/anonymisation, utilise a la fois par le flux admin RGPD
 * (Service\Admin\ModerationService) et par le flux self-service (Controller\UserController),
 * pour eviter de dupliquer cette logique entre les deux appelants.
 */
class UserService
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly VoyageService $voyageService,
        private readonly DemandeService $demandeService,
        private readonly RefreshTokenManager $refreshTokenManager,
        private readonly AddressService $addressService,
        private readonly AvatarService $avatarService,
        private readonly UserPasswordHasherInterface $passwordHasher,
    ) {}

    /**
     * Verifie qu'une demande de suppression de compte self-service est legitime.
     *
     * @throws BadRequestHttpException si le mot de passe actuel est requis mais absent/incorrect,
     *                                  ou si le compte est un compte administrateur
     */
    public function verifySelfDeletionRequest(User $user, ?string $currentPassword): void
    {
        if (in_array('ROLE_ADMIN', $user->getRoles(), true)) {
            throw new BadRequestHttpException(
                'Un compte administrateur ne peut pas etre supprime via cette voie. Contactez un autre administrateur.'
            );
        }

        // Compte local (mot de passe defini) : le mot de passe actuel doit etre confirme.
        // Compte OAuth pur (pas de mot de passe) : aucune verification possible, l'appel
        // authentifie constitue la seule confirmation disponible.
        if ($user->getPassword() !== null) {
            if ($currentPassword === null || !$this->passwordHasher->isPasswordValid($user, $currentPassword)) {
                throw new BadRequestHttpException('Le mot de passe actuel est incorrect');
            }
        }
    }

    /**
     * Supprime un compte utilisateur en soft-delete/anonymisation.
     *
     * Conserve intacts les messages, avis et signalements lies a l'utilisateur (visibles/utiles
     * aux tiers), annule ses voyages/demandes actifs plutot que de les supprimer (protege les
     * Proposition/Demande de tiers qui leur sont liees), et supprime explicitement ses donnees
     * strictement privees (notifications, favoris, adresse, parametres, avatar).
     */
    public function anonymizeAndSoftDelete(User $user): void
    {
        foreach ($user->getVoyages() as $voyage) {
            $this->voyageService->deleteVoyage($voyage->getId());
        }

        foreach ($user->getDemandes() as $demande) {
            $this->demandeService->deleteDemande($demande->getId());
        }

        $this->refreshTokenManager->invalidateUserTokens($user);

        foreach ($user->getNotifications() as $notification) {
            $this->entityManager->remove($notification);
        }

        foreach ($user->getFavoris() as $favori) {
            $this->entityManager->remove($favori);
        }

        if ($user->getAddress() !== null) {
            $this->addressService->deleteAddress($user->getAddress());
        }

        if ($user->getSettings() !== null) {
            $this->entityManager->remove($user->getSettings());
        }

        $this->avatarService->deleteAvatar($user->getPhoto());

        $user->setEmail(sprintf('deleted-%d@deleted.cobage.invalid', $user->getId()));
        $user->setNom('Utilisateur');
        $user->setPrenom('supprime');
        $user->setTelephone(null);
        $user->setPhoto(null);
        $user->setBio(null);
        $user->setPassword(null);
        $user->setGoogleId(null);
        $user->setFacebookId(null);
        $user->setDeletedAt(new \DateTimeImmutable());

        $this->entityManager->flush();
    }
}
