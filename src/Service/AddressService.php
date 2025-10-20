<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Address;
use App\Entity\User;
use App\Repository\AddressRepository;
use App\Repository\CountryRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Psr\Log\LoggerInterface;

readonly class AddressService
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private AddressRepository $addressRepository,
        private CurrencyService $currencyService,
        private LoggerInterface $logger,
        private GeoDataService $geoDataService,
        private CountryRepository $countryRepository,
    ) {}

    /**
     * Crée une adresse pour un utilisateur
     */
    public function createAddress(User $user, array $data): Address
    {
        // Vérifier si l'utilisateur a déjà une adresse
        $existingAddress = $this->addressRepository->findByUser($user);

        if ($existingAddress) {
            $this->logger->info('Adresse existante trouvée, retour de l\'adresse existante', [
                'user_id' => $user->getId(),
                'address_id' => $existingAddress->getId()
            ]);

            // ⚡ RETOURNER L'ADRESSE EXISTANTE au lieu de lever une erreur
            return $existingAddress;
        }

        // Valider les données
        $this->validateAddressData($data);

        $address = new Address();
        $address->setUser($user)
            ->setPays($data['pays'])
            ->setVille($data['ville']);

        // Format Afrique
        if (isset($data['quartier'])) {
            $address->setQuartier($data['quartier']);
        }

        // Format Diaspora
        if (isset($data['adresseLigne1'])) {
            $address->setAdresseLigne1($data['adresseLigne1']);
        }
        if (isset($data['adresseLigne2'])) {
            $address->setAdresseLigne2($data['adresseLigne2']);
        }
        if (isset($data['codePostal'])) {
            $address->setCodePostal($data['codePostal']);
        }

        // ==================== DÉTECTION AUTOMATIQUE DE LA DEVISE ====================
        $pays = $this->countryRepository->findOneBy(['nameFr' => $data['pays']]);
        $detectedCurrency = $this->currencyService->getCurrencyAndLangByCountry($data['pays'])['currency'];
        $detectedLang = $this->currencyService->getCurrencyAndLangByCountry($data['pays'])['languages'];
        $detectedTimeZone = $this->geoDataService->getTimeZoneByCityAndPays($data['ville'], $pays->getId());

        // Mettre à jour la devise de l'utilisateur dans ses settings
        $user->getSettings()?->setDevise($detectedCurrency);
        $user->getSettings()?->setLangue($detectedLang);
        $user->getSettings()?->setTimeZone($detectedTimeZone);

        $this->logger->info('Devise détectée automatiquement', [
            'user_id' => $user->getId(),
            'pays' => $data['pays'],
            'devise' => $detectedCurrency
        ]);

        $user->setAddress($address);

        // Marquer comme créée (lastModifiedAt reste null pour première création)
        $this->entityManager->persist($address);
        $this->entityManager->flush();

        $this->logger->info('Adresse créée', [
            'user_id' => $user->getId(),
            'address_id' => $address->getId()
        ]);

        return $address;
    }

    /**
     * Met à jour une adresse (avec vérification des 6 mois)
     */
    public function updateAddress(Address $address, array $data): Address
    {
        // ==================== VÉRIFICATION CONTRAINTE 6 MOIS ====================
        if (!$address->canBeModified()) {
            $nextDate = $address->getNextModificationDate();
            $daysRemaining = $address->getDaysUntilModification();

            throw new BadRequestHttpException(
                sprintf(
                    'Vous ne pouvez modifier votre adresse que tous les 6 mois. Prochaine modification possible le %s (dans %d jours)',
                    $nextDate->format('d/m/Y'),
                    $daysRemaining
                )
            );
        }

        // Valider les nouvelles données
        $this->validateAddressData($data);

        $oldCountry = $address->getPays();
        $newCountry = $data['pays'];

        // Mettre à jour les champs
        $address->setPays($newCountry)
            ->setVille($data['ville']);

        // Reset des champs de format
        $address->setQuartier(null)
            ->setAdresseLigne1(null)
            ->setAdresseLigne2(null)
            ->setCodePostal(null);

        // Format Afrique
        if (isset($data['quartier'])) {
            $address->setQuartier($data['quartier']);
        }

        // Format Diaspora
        if (isset($data['adresseLigne1'])) {
            $address->setAdresseLigne1($data['adresseLigne1']);
        }
        if (isset($data['adresseLigne2'])) {
            $address->setAdresseLigne2($data['adresseLigne2']);
        }
        if (isset($data['codePostal'])) {
            $address->setCodePostal($data['codePostal']);
        }

        // ==================== MISE À JOUR DE LA DEVISE SI LE PAYS A CHANGÉ ====================
        if ($oldCountry !== $newCountry) {
            $newCurrency = $this->currencyService->getCurrencyAndLangByCountry($newCountry)['currency'];
            $newLang = $this->currencyService->getCurrencyAndLangByCountry($newCountry)['lang'];

            $address->getUser()->getSettings()?->setDevise($newCurrency);
            $address->getUser()->getSettings()?->setLangue($newLang);

            $this->logger->info('Devise mise à jour suite au changement de pays', [
                'user_id' => $address->getUser()->getId(),
                'old_country' => $oldCountry,
                'new_country' => $newCountry,
                'new_currency' => $newCurrency,
                'new_lang' => $newLang,
            ]);
        }

        // ==================== MARQUER COMME MODIFIÉE ====================
        $address->markAsModified();

        $this->entityManager->flush();

        $this->logger->info('Adresse mise à jour', [
            'user_id' => $address->getUser()->getId(),
            'address_id' => $address->getId()
        ]);

        return $address;
    }

    /**
     * Récupère l'adresse d'un utilisateur
     */
    public function getUserAddress(User $user): ?Address
    {
        return $this->addressRepository->findByUser($user);
    }

    /**
     * Vérifie si l'utilisateur peut modifier son adresse
     */
    public function canUserModifyAddress(User $user): bool
    {
        $address = $this->getUserAddress($user);

        if (!$address) {
            return true; // Peut créer une adresse
        }

        return $address->canBeModified();
    }

    /**
     * Récupère les informations de modification pour l'utilisateur
     */
    public function getModificationInfo(User $user): array
    {
        $address = $this->getUserAddress($user);

        if (!$address) {
            return [
                'canModify' => true,
                'hasAddress' => false,
                'message' => 'Aucune adresse enregistrée'
            ];
        }

        $canModify = $address->canBeModified();

        if ($canModify) {
            return [
                'canModify' => true,
                'hasAddress' => true,
                'lastModifiedAt' => $address->getLastModifiedAt()?->format('c'),
                'message' => 'Vous pouvez modifier votre adresse'
            ];
        }

        return [
            'canModify' => false,
            'hasAddress' => true,
            'lastModifiedAt' => $address->getLastModifiedAt()?->format('c'),
            'nextModificationDate' => $address->getNextModificationDate()?->format('c'),
            'daysRemaining' => $address->getDaysUntilModification(),
            'message' => sprintf(
                'Prochaine modification possible le %s (dans %d jours)',
                $address->getNextModificationDate()->format('d/m/Y'),
                $address->getDaysUntilModification()
            )
        ];
    }

    /**
     * Valide les données d'adresse
     */
    private function validateAddressData(array $data): void
    {
        if (empty($data['pays']) || empty($data['ville'])) {
            throw new BadRequestHttpException('Le pays et la ville sont obligatoires');
        }

        // Vérifier qu'au moins un format est fourni
        $africanFormat = !empty($data['quartier']);
        $diasporaFormat = !empty($data['adresseLigne1']) && !empty($data['codePostal']);

        if (!$africanFormat && !$diasporaFormat) {
            throw new BadRequestHttpException(
                'Vous devez fournir soit un quartier (format Afrique), soit une adresse postale complète (ligne 1 + code postal)'
            );
        }
    }

    /**
     * Supprime une adresse
     */
    public function deleteAddress(Address $address): void
    {
        $userId = $address->getUser()->getId();

        $this->entityManager->remove($address);
        $this->entityManager->flush();

        $this->logger->info('Adresse supprimée', [
            'user_id' => $userId,
            'address_id' => $address->getId()
        ]);
    }
}
