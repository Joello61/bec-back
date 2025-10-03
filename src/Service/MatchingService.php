<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Demande;
use App\Entity\Voyage;
use App\Repository\DemandeRepository;
use App\Repository\VoyageRepository;

readonly class MatchingService
{
    public function __construct(
        private VoyageRepository $voyageRepository,
        private DemandeRepository $demandeRepository
    ) {}

    public function findMatchingVoyages(Demande $demande): array
    {
        return $this->voyageRepository->findMatchingDemande(
            $demande->getVilleDepart(),
            $demande->getVilleArrivee(),
            $demande->getDateLimite()
        );
    }

    public function findMatchingDemandes(Voyage $voyage): array
    {
        return $this->demandeRepository->findMatchingVoyage(
            $voyage->getVilleDepart(),
            $voyage->getVilleArrivee(),
            $voyage->getDateDepart()
        );
    }

    public function calculateMatchScore(Voyage $voyage, Demande $demande): int
    {
        $score = 0;

        // Correspondance exacte des villes (50 points)
        if (stripos($voyage->getVilleDepart(), $demande->getVilleDepart()) !== false) {
            $score += 25;
        }
        if (stripos($voyage->getVilleArrivee(), $demande->getVilleArrivee()) !== false) {
            $score += 25;
        }

        // Correspondance de dates (30 points)
        if ($demande->getDateLimite()) {
            $diff = $voyage->getDateDepart()->diff($demande->getDateLimite())->days;
            if ($diff <= 7) {
                $score += 30;
            } elseif ($diff <= 14) {
                $score += 20;
            } elseif ($diff <= 30) {
                $score += 10;
            }
        } else {
            $score += 15; // Pas de limite de date = flexible
        }

        // Correspondance de poids (20 points)
        $voyagePoids = (float) $voyage->getPoidsDisponible();
        $demandePoids = (float) $demande->getPoidsEstime();

        if ($voyagePoids >= $demandePoids) {
            $score += 20;
        } elseif ($voyagePoids >= ($demandePoids * 0.7)) {
            $score += 10;
        }

        return $score;
    }

    public function findBestMatches(Demande $demande, int $limit = 5): array
    {
        $voyages = $this->findMatchingVoyages($demande);

        $matches = [];
        foreach ($voyages as $voyage) {
            $matches[] = [
                'voyage' => $voyage,
                'score' => $this->calculateMatchScore($voyage, $demande)
            ];
        }

        // Trier par score décroissant
        usort($matches, fn($a, $b) => $b['score'] <=> $a['score']);

        return array_slice($matches, 0, $limit);
    }
}
