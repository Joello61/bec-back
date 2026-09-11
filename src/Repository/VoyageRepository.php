<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\User;
use App\Entity\Voyage;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Voyage>
 */
class VoyageRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Voyage::class);
    }

    /**
     * Filtres ville depart/arrivee/date communs a findPublicPaginated/findPaginated/
     * findAllPaginatedAdmin, appliques a l'identique sur la requete principale et sur
     * son COUNT - le statut et le join de visibilite restent geres par chaque methode
     * appelante, leur logique differant reellement entre elles.
     * @param array<string, mixed> $filters
     */
    private function applyFilters(QueryBuilder $qb, array $filters): void
    {
        if (!empty($filters['villeDepart'])) {
            $qb->andWhere('v.villeDepart LIKE :villeDepart')
                ->setParameter('villeDepart', '%' . $filters['villeDepart'] . '%');
        }

        if (!empty($filters['villeArrivee'])) {
            $qb->andWhere('v.villeArrivee LIKE :villeArrivee')
                ->setParameter('villeArrivee', '%' . $filters['villeArrivee'] . '%');
        }

        if (!empty($filters['dateDepart'])) {
            $qb->andWhere('v.dateDepart >= :dateDepart')
                ->setParameter('dateDepart', new \DateTime($filters['dateDepart']));
        }

        // Recherche texte libre admin (Phase 13/Lot B5, plan-correction-cobage.md) : sur le
        // proprietaire (nom/prenom/email), pas sur les villes (deja des filtres dedies
        // villeDepart/villeArrivee, redondant). L'alias "u" (v.voyageur) est deja joint par
        // les deux appelantes de applyFilters() (findPaginated/findPublicPaginated).
        if (!empty($filters['search'])) {
            $qb->andWhere('u.nom LIKE :search OR u.prenom LIKE :search OR u.email LIKE :search')
                ->setParameter('search', '%' . $filters['search'] . '%');
        }
    }

    /**
     * @param array<string, mixed> $filters
     * @return array{data: Voyage[], pagination: array{page: int, limit: int, total: int, pages: int}}
     */
    public function findPublicPaginated(int $page = 1, int $limit = 10, array $filters = []): array
    {
        $offset = ($page - 1) * $limit;

        $qb = $this->createQueryBuilder('v')
            ->leftJoin('v.voyageur', 'u')
            ->leftJoin('u.settings', 's')
            ->addSelect('u', 's')
            ->orderBy('v.createdAt', 'DESC')
            ->andWhere('v.statut = :statut')
            ->setParameter('statut', 'actif')
            ->setFirstResult($offset)
            ->setMaxResults($limit);

        $this->applyFilters($qb, $filters);

        $voyages = $qb->getQuery()->getResult();

        $countQb = $this->createQueryBuilder('v')
            ->select('COUNT(v.id)')
            // "u" jamais utilise par un filtre reel ici (route publique, jamais de
            // parametre "search") - joint quand meme pour que applyFilters() reste valide
            // dans les deux QueryBuilder qu'elle recoit, meme si l'appel se fait un jour
            // avec "search" (Phase 13/Lot B5).
            ->leftJoin('v.voyageur', 'u')
            ->andWhere('v.statut = :statut')
            ->setParameter('statut', 'actif');

        $this->applyFilters($countQb, $filters);

        $total = $countQb->getQuery()->getSingleScalarResult();

        return [
            'data' => $voyages,
            'pagination' => [
                'page' => $page,
                'limit' => $limit,
                'total' => $total,
                'pages' => (int) ceil($total / $limit),
            ],
        ];
    }

    /**
     * @param array<string, mixed> $filters
     * @return array{data: Voyage[], pagination: array{page: int, limit: int, total: int, pages: int}}
     */
    public function findPaginated(int $page = 1, int $limit = 10, array $filters = [], ?User $excludeUser = null): array
    {
        $offset = ($page - 1) * $limit;

        $qb = $this->createQueryBuilder('v')
            ->leftJoin('v.voyageur', 'u')
            ->leftJoin('u.settings', 's')
            ->addSelect('u', 's')
            // ==================== FILTRER PAR VISIBILITÉ ====================
            ->where('s.privacy.showInSearchResults = :visible OR s.id IS NULL')
            ->setParameter('visible', true)
            ->orderBy('v.createdAt', 'DESC')
            ->setFirstResult($offset)
            ->setMaxResults($limit);

        if ($excludeUser && !in_array('ROLE_ADMIN', $excludeUser->getRoles(), true)) {
            $qb->andWhere('v.voyageur != :excludedUser')
                ->setParameter('excludedUser', $excludeUser);
        }

        $this->applyFilters($qb, $filters);

        if (!empty($filters['statut'])) {
            $qb->andWhere('v.statut = :statut')
                ->setParameter('statut', $filters['statut']);
        } else {
            $qb->andWhere('v.statut = :statut')
                ->setParameter('statut', 'actif');
        }

        $voyages = $qb->getQuery()->getResult();

        $countQb = $this->createQueryBuilder('v')
            ->select('COUNT(v.id)')
            ->leftJoin('v.voyageur', 'u')
            ->leftJoin('u.settings', 's')
            // ==================== MÊME FILTRE POUR LE COUNT ====================
            ->where('s.privacy.showInSearchResults = :visible OR s.id IS NULL')
            ->setParameter('visible', true);

        if ($excludeUser && !in_array('ROLE_ADMIN', $excludeUser->getRoles(), true)) {
            $countQb->andWhere('v.voyageur != :excludedUser')
                ->setParameter('excludedUser', $excludeUser);
        }

        $this->applyFilters($countQb, $filters);

        if (!empty($filters['statut'])) {
            $countQb->andWhere('v.statut = :statut')
                ->setParameter('statut', $filters['statut']);
        } else {
            $countQb->andWhere('v.statut = :statut')
                ->setParameter('statut', 'actif');
        }

        $total = $countQb->getQuery()->getSingleScalarResult();

        return [
            'data' => $voyages,
            'pagination' => [
                'page' => $page,
                'limit' => $limit,
                'total' => $total,
                'pages' => (int) ceil($total / $limit),
            ],
        ];
    }

    /**
     * @return Voyage[]
     */
    public function findByVoyageur(int $voyageurId): array
    {
        return $this->createQueryBuilder('v')
            ->where('v.voyageur = :voyageurId')
            ->setParameter('voyageurId', $voyageurId)
            ->orderBy('v.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * @return Voyage[]
     */
    public function findActifs(): array
    {
        return $this->createQueryBuilder('v')
            ->leftJoin('v.voyageur', 'u')
            ->leftJoin('u.settings', 's')
            ->addSelect('u', 's')
            ->where('v.statut = :statut')
            ->andWhere('v.dateDepart >= :today')
            // ==================== FILTRER PAR VISIBILITÉ ====================
            ->andWhere('s.privacy.showInSearchResults = :visible OR s.id IS NULL')
            ->setParameter('statut', 'actif')
            ->setParameter('today', new \DateTime())
            ->setParameter('visible', true)
            ->orderBy('v.dateDepart', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * @return Voyage[]
     */
    public function findMatchingDemande(string $villeDepart, string $villeArrivee, ?\DateTimeInterface $dateDepart = null, ?int $excludeUserId = null): array
    {
        $qb = $this->createQueryBuilder('v')
            ->leftJoin('v.voyageur', 'u')
            ->leftJoin('u.settings', 's')
            ->addSelect('u', 's')
            ->where('v.statut = :statut')
            ->andWhere('v.villeDepart LIKE :villeDepart')
            ->andWhere('v.villeArrivee LIKE :villeArrivee')
            // ==================== FILTRER PAR VISIBILITÉ ====================
            ->andWhere('s.privacy.showInSearchResults = :visible OR s.id IS NULL')
            ->setParameter('statut', 'actif')
            ->setParameter('villeDepart', '%' . $villeDepart . '%')
            ->setParameter('villeArrivee', '%' . $villeArrivee . '%')
            ->setParameter('visible', true);

        if ($excludeUserId !== null) {
            $qb->andWhere('u.id != :excludeUserId')
                ->setParameter('excludeUserId', $excludeUserId);
        }

        if ($dateDepart) {
            $qb->andWhere('v.dateDepart >= :dateDepart')
                ->setParameter('dateDepart', $dateDepart);
        }

        return $qb->orderBy('v.dateDepart', 'ASC')
            ->setMaxResults(10)
            ->getQuery()
            ->getResult();
    }

    /**
     * Liste TOUS les voyages (pour admin) sans filtre de visibilité
     * @param array<string, mixed> $filters
     * @return array{data: Voyage[], pagination: array{page: int, limit: int, total: int, pages: int}}
     */
    public function findAllPaginatedAdmin(int $page, int $limit, array $filters = []): array
    {
        $offset = ($page - 1) * $limit;

        $qb = $this->createQueryBuilder('v')
            ->leftJoin('v.voyageur', 'u')
            ->leftJoin('u.settings', 's')
            ->addSelect('u', 's')
            ->orderBy('v.createdAt', 'DESC')
            ->setFirstResult($offset)
            ->setMaxResults($limit);

        // Pas de filtre showInSearchResults pour admin

        $this->applyFilters($qb, $filters);

        if (!empty($filters['statut'])) {
            $qb->andWhere('v.statut = :statut')
                ->setParameter('statut', $filters['statut']);
        }

        $voyages = $qb->getQuery()->getResult();

        $countQb = $this->createQueryBuilder('v')
            ->select('COUNT(v.id)');

        $this->applyFilters($countQb, $filters);

        if (!empty($filters['statut'])) {
            $countQb->andWhere('v.statut = :statut')
                ->setParameter('statut', $filters['statut']);
        }

        $total = $countQb->getQuery()->getSingleScalarResult();

        return [
            'data' => $voyages,
            'pagination' => [
                'page' => $page,
                'limit' => $limit,
                'total' => $total,
                'pages' => (int) ceil($total / $limit),
            ],
        ];
    }

    /**
     * Compte les voyages par statut
     */
    public function countByStatut(string $statut): int
    {
        return $this->count(['statut' => $statut]);
    }

    /**
     * Compte les voyages créés entre deux dates
     */
    public function countCreatedBetween(\DateTimeInterface $start, \DateTimeInterface $end): int
    {
        return (int) $this->createQueryBuilder('v')
            ->select('COUNT(v.id)')
            ->where('v.createdAt BETWEEN :start AND :end')
            ->setParameter('start', $start)
            ->setParameter('end', $end)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * @return Voyage[]
     */
    public function findExpiredVoyages(\DateTimeInterface $today): array
    {
        return $this->createQueryBuilder('v')
            ->where('v.dateDepart < :today')
            ->andWhere('v.statut = :statut')
            ->setParameter('today', $today)
            ->setParameter('statut', 'actif')
            ->getQuery()
            ->getResult();
    }
}
