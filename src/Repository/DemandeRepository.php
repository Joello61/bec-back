<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Demande;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Demande>
 */
class DemandeRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Demande::class);
    }

    /**
     * Filtres ville depart/arrivee/dateLimite communs a findPublicPaginated/
     * findPaginated/findAllPaginatedAdmin, appliques a l'identique sur la requete
     * principale et sur son COUNT - le statut et le join de visibilite restent geres
     * par chaque methode appelante, leur logique differant reellement entre elles.
     * @param array<string, mixed> $filters
     */
    private function applyFilters(QueryBuilder $qb, array $filters): void
    {
        if (!empty($filters['villeDepart'])) {
            $qb->andWhere('d.villeDepart LIKE :villeDepart')
                ->setParameter('villeDepart', '%' . $filters['villeDepart'] . '%');
        }

        if (!empty($filters['villeArrivee'])) {
            $qb->andWhere('d.villeArrivee LIKE :villeArrivee')
                ->setParameter('villeArrivee', '%' . $filters['villeArrivee'] . '%');
        }

        if (!empty($filters['dateLimite'])) {
            $qb->andWhere('d.dateLimite <= :today')
                ->setParameter('today', new \DateTime('today'));
        }

        // Recherche texte libre admin (Phase 13/Lot B5, plan-correction-cobage.md) : sur le
        // proprietaire (nom/prenom/email), pas sur les villes (deja des filtres dedies
        // villeDepart/villeArrivee, redondant). L'alias "u" (d.client) est deja joint par
        // les deux appelantes de applyFilters() (findPaginated/findPublicPaginated).
        if (!empty($filters['search'])) {
            $qb->andWhere('u.nom LIKE :search OR u.prenom LIKE :search OR u.email LIKE :search')
                ->setParameter('search', '%' . $filters['search'] . '%');
        }
    }

    /**
     * @param array<string, mixed> $filters
     * @return array{data: Demande[], pagination: array{page: int, limit: int, total: int, pages: int}}
     */
    public function findPublicPaginated(int $page = 1, int $limit = 10, array $filters = []): array
    {
        $offset = ($page - 1) * $limit;

        $qb = $this->createQueryBuilder('d')
            ->leftJoin('d.client', 'u')
            ->leftJoin('u.settings', 's')
            ->addSelect('u', 's')
            ->orderBy('d.createdAt', 'DESC')
            ->andWhere('d.statut = :statut')
            ->setParameter('statut', 'en_recherche')
            ->setFirstResult($offset)
            ->setMaxResults($limit);

        $this->applyFilters($qb, $filters);

        $demandes = $qb->getQuery()->getResult();

        $countQb = $this->createQueryBuilder('d')
            ->select('COUNT(d.id)')
            // "u" jamais utilise par un filtre reel ici (route publique, jamais de
            // parametre "search") - joint quand meme pour que applyFilters() reste valide
            // dans les deux QueryBuilder qu'elle recoit, meme si l'appel se fait un jour
            // avec "search" (Phase 13/Lot B5).
            ->leftJoin('d.client', 'u')
            ->andWhere('d.statut = :statut')
            ->setParameter('statut', 'en_recherche');

        $this->applyFilters($countQb, $filters);

        $total = $countQb->getQuery()->getSingleScalarResult();

        return [
            'data' => $demandes,
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
     * @return array{data: Demande[], pagination: array{page: int, limit: int, total: int, pages: int}}
     */
    public function findPaginated(int $page = 1, int $limit = 10, array $filters = [], ?User $excludeUser = null): array
    {
        $offset = ($page - 1) * $limit;

        $qb = $this->createQueryBuilder('d')
            ->leftJoin('d.client', 'u')
            ->leftJoin('u.settings', 's')
            ->addSelect('u', 's')
            // ==================== FILTRER PAR VISIBILITÉ ====================
            ->where('s.privacy.showInSearchResults = :visible OR s.id IS NULL')
            ->setParameter('visible', true)
            ->orderBy('d.createdAt', 'DESC')
            ->setFirstResult($offset)
            ->setMaxResults($limit);

        if ($excludeUser && !in_array('ROLE_ADMIN', $excludeUser->getRoles(), true)) {
            $qb->andWhere('d.client != :excludedUser')
                ->setParameter('excludedUser', $excludeUser);
        }

        $this->applyFilters($qb, $filters);

        if (!empty($filters['statut'])) {
            $qb->andWhere('d.statut = :statut')
                ->setParameter('statut', $filters['statut']);
        } else {
            $qb->andWhere('d.statut = :statut')
                ->setParameter('statut', 'en_recherche');
        }

        $demandes = $qb->getQuery()->getResult();

        $countQb = $this->createQueryBuilder('d')
            ->select('COUNT(d.id)')
            ->leftJoin('d.client', 'u')
            ->leftJoin('u.settings', 's')
            // ==================== MÊME FILTRE POUR LE COUNT ====================
            ->where('s.privacy.showInSearchResults = :visible OR s.id IS NULL')
            ->setParameter('visible', true);

        if ($excludeUser && !in_array('ROLE_ADMIN', $excludeUser->getRoles(), true)) {
            $countQb->andWhere('d.client != :excludedUser')
                ->setParameter('excludedUser', $excludeUser);
        }

        $this->applyFilters($countQb, $filters);

        if (!empty($filters['statut'])) {
            $countQb->andWhere('d.statut = :statut')
                ->setParameter('statut', $filters['statut']);
        } else {
            $countQb->andWhere('d.statut = :statut')
                ->setParameter('statut', 'en_recherche');
        }

        $total = $countQb->getQuery()->getSingleScalarResult();

        return [
            'data' => $demandes,
            'pagination' => [
                'page' => $page,
                'limit' => $limit,
                'total' => $total,
                'pages' => (int) ceil($total / $limit),
            ],
        ];
    }

    /**
     * @return Demande[]
     */
    public function findByClient(int $clientId): array
    {
        return $this->createQueryBuilder('d')
            ->where('d.client = :clientId')
            ->setParameter('clientId', $clientId)
            ->orderBy('d.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * @return Demande[]
     */
    public function findEnRecherche(): array
    {
        return $this->createQueryBuilder('d')
            ->leftJoin('d.client', 'u')
            ->leftJoin('u.settings', 's')
            ->addSelect('u', 's')
            ->where('d.statut = :statut')
            // ==================== FILTRER PAR VISIBILITÉ ====================
            ->andWhere('s.privacy.showInSearchResults = :visible OR s.id IS NULL')
            ->setParameter('statut', 'en_recherche')
            ->setParameter('visible', true)
            ->orderBy('d.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * @return Demande[]
     */
    public function findMatchingVoyage(string $villeDepart, string $villeArrivee, ?\DateTimeInterface $dateDepart = null, ?int $excludeUserId = null): array
    {
        $qb = $this->createQueryBuilder('d')
            ->leftJoin('d.client', 'u')
            ->leftJoin('u.settings', 's')
            ->addSelect('u', 's')
            ->where('d.statut = :statut')
            ->andWhere('d.villeDepart LIKE :villeDepart')
            ->andWhere('d.villeArrivee LIKE :villeArrivee')
            // ==================== FILTRER PAR VISIBILITÉ ====================
            ->andWhere('s.privacy.showInSearchResults = :visible OR s.id IS NULL')
            ->setParameter('statut', 'en_recherche')
            ->setParameter('villeDepart', '%' . $villeDepart . '%')
            ->setParameter('villeArrivee', '%' . $villeArrivee . '%')
            ->setParameter('visible', true);

        if ($excludeUserId !== null) {
            $qb->andWhere('u.id != :excludeUserId')
                ->setParameter('excludeUserId', $excludeUserId);
        }

        if ($dateDepart) {
            $qb->andWhere('(d.dateLimite IS NULL OR d.dateLimite >= :dateDepart)')
                ->setParameter('dateDepart', $dateDepart);
        }

        return $qb->orderBy('d.createdAt', 'DESC')
            ->setMaxResults(10)
            ->getQuery()
            ->getResult();
    }

    /**
     * Liste TOUTES les demandes (pour admin) sans filtre de visibilité
     * @param array<string, mixed> $filters
     * @return array{data: Demande[], pagination: array{page: int, limit: int, total: int, pages: int}}
     */
    public function findAllPaginatedAdmin(int $page, int $limit, array $filters = []): array
    {
        $offset = ($page - 1) * $limit;

        $qb = $this->createQueryBuilder('d')
            ->leftJoin('d.client', 'u')
            ->leftJoin('u.settings', 's')
            ->addSelect('u', 's')
            ->orderBy('d.createdAt', 'DESC')
            ->setFirstResult($offset)
            ->setMaxResults($limit);

        // Pas de filtre showInSearchResults pour admin

        $this->applyFilters($qb, $filters);

        if (!empty($filters['statut'])) {
            $qb->andWhere('d.statut = :statut')
                ->setParameter('statut', $filters['statut']);
        }

        $demandes = $qb->getQuery()->getResult();

        // "u" joint pour que applyFilters() (search sur u.nom/prenom/email, Phase 13/Lot B6)
        // reste valide - meme piege deja corrige sur findPublicPaginated (Lot B5).
        $countQb = $this->createQueryBuilder('d')
            ->select('COUNT(d.id)')
            ->leftJoin('d.client', 'u');

        $this->applyFilters($countQb, $filters);

        if (!empty($filters['statut'])) {
            $countQb->andWhere('d.statut = :statut')
                ->setParameter('statut', $filters['statut']);
        }

        $total = $countQb->getQuery()->getSingleScalarResult();

        return [
            'data' => $demandes,
            'pagination' => [
                'page' => $page,
                'limit' => $limit,
                'total' => $total,
                'pages' => (int) ceil($total / $limit),
            ],
        ];
    }

    /**
     * Compte les demandes par statut
     */
    public function countByStatut(string $statut): int
    {
        return $this->count(['statut' => $statut]);
    }

    /**
     * Compte les demandes actives d'un utilisateur (quota freemium, monétisation Lot 1)
     */
    public function countActiveByUser(User $user): int
    {
        return $this->count(['client' => $user, 'statut' => 'en_recherche']);
    }

    /**
     * Compte les demandes créées entre deux dates
     */
    public function countCreatedBetween(\DateTimeInterface $start, \DateTimeInterface $end): int
    {
        return (int) $this->createQueryBuilder('d')
            ->select('COUNT(d.id)')
            ->where('d.createdAt BETWEEN :start AND :end')
            ->setParameter('start', $start)
            ->setParameter('end', $end)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * @return Demande[]
     */
    public function findExpiredDemandes(\DateTimeInterface $today): array
    {
        return $this->createQueryBuilder('d')
            ->where('d.dateLimite < :today')
            ->andWhere('d.statut = :statut')
            ->setParameter('today', $today)
            ->setParameter('statut', 'en_recherche')
            ->getQuery()
            ->getResult();
    }
}
