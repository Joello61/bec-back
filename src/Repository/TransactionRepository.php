<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Transaction;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Transaction>
 */
class TransactionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Transaction::class);
    }

    /**
     * Point de lecture de l'idempotence webhook : UNIQUE(provider, provider_payment_id) en base.
     */
    public function findByProviderPaymentId(string $provider, string $providerPaymentId): ?Transaction
    {
        return $this->findOneBy([
            'provider' => $provider,
            'providerPaymentId' => $providerPaymentId,
        ]);
    }

    /**
     * Liste paginee pour l'admin (Lot 6.1) - necessaire pour retrouver la transaction a
     * rembourser. Filtres optionnels : status, type, provider.
     * @param array<string, mixed> $filters
     * @return array{data: Transaction[], pagination: array{page: int, limit: int, total: int, pages: int}}
     */
    public function findAllPaginatedAdmin(int $page, int $limit, array $filters = []): array
    {
        $offset = ($page - 1) * $limit;

        $qb = $this->createQueryBuilder('t')
            ->orderBy('t.createdAt', 'DESC')
            ->setFirstResult($offset)
            ->setMaxResults($limit);

        $countQb = $this->createQueryBuilder('t')
            ->select('COUNT(t.id)');

        foreach (['status', 'type', 'provider'] as $field) {
            if (isset($filters[$field])) {
                $qb->andWhere("t.{$field} = :{$field}")->setParameter($field, $filters[$field]);
                $countQb->andWhere("t.{$field} = :{$field}")->setParameter($field, $filters[$field]);
            }
        }

        $transactions = $qb->getQuery()->getResult();
        $total = $countQb->getQuery()->getSingleScalarResult();

        return [
            'data' => $transactions,
            'pagination' => [
                'page' => $page,
                'limit' => $limit,
                'total' => $total,
                'pages' => (int) ceil($total / $limit),
            ],
        ];
    }

    /**
     * Liste paginee des transactions d'un seul utilisateur (Lot N3, historique de ses
     * propres paiements) - copie de findAllPaginatedAdmin() sans les filtres admin,
     * filtree sur le proprietaire au lieu d'etre ouverte a toute la table.
     * @return array{data: Transaction[], pagination: array{page: int, limit: int, total: int, pages: int}}
     */
    public function findAllPaginatedForUser(User $user, int $page, int $limit): array
    {
        $offset = ($page - 1) * $limit;

        $transactions = $this->createQueryBuilder('t')
            ->andWhere('t.user = :user')
            ->setParameter('user', $user)
            ->orderBy('t.createdAt', 'DESC')
            ->setFirstResult($offset)
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();

        $total = (int) $this->createQueryBuilder('t')
            ->select('COUNT(t.id)')
            ->andWhere('t.user = :user')
            ->setParameter('user', $user)
            ->getQuery()
            ->getSingleScalarResult();

        return [
            'data' => $transactions,
            'pagination' => [
                'page' => $page,
                'limit' => $limit,
                'total' => $total,
                'pages' => (int) ceil($total / $limit),
            ],
        ];
    }

    /**
     * Nombre de factures deja numerotees pour une annee donnee (Lot N4) - sert de base
     * au compteur sequentiel provisoire INV-{annee}-{sequence}. Best-effort, pas de
     * verrou : une collision concurrente sur la meme seconde reste possible (rejetee par
     * la contrainte UNIQUE sur invoiceNumber), acceptable pour un schema explicitement
     * provisoire en attendant une validation comptable/juridique.
     */
    public function countInvoicedTransactionsForYear(int $year): int
    {
        return (int) $this->createQueryBuilder('t')
            ->select('COUNT(t.id)')
            ->andWhere('t.invoiceNumber LIKE :prefix')
            ->setParameter('prefix', sprintf('INV-%d-%%', $year))
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Somme des montants par devise (EUR/XAF ne se cumulent jamais entre eux) pour un
     * statut donné, optionnellement bornée dans le temps - réutilisée pour le total, le
     * "ce mois-ci" et le calcul jour par jour (Lot 5, AdminStatsService::getRevenueStats).
     * @return array<string, string> devise => somme
     */
    public function sumAmountByCurrency(string $status, ?\DateTimeInterface $start = null, ?\DateTimeInterface $end = null): array
    {
        $qb = $this->createQueryBuilder('t')
            ->select('t.currency AS currency', 'SUM(t.amount) AS total')
            ->where('t.status = :status')
            ->groupBy('t.currency')
            ->setParameter('status', $status);

        if ($start !== null) {
            $qb->andWhere('t.createdAt >= :start')->setParameter('start', $start);
        }
        if ($end !== null) {
            $qb->andWhere('t.createdAt <= :end')->setParameter('end', $end);
        }

        $rows = $qb->getQuery()->getResult();

        return array_combine(
            array_column($rows, 'currency'),
            array_column($rows, 'total')
        );
    }

    /**
     * Somme des montants par devise, groupée par type de transaction (abonnement initial/
     * renouvellement, boost) - Lot 5.
     * @return array<string, array<string, string>> type => (devise => somme)
     */
    public function sumAmountByCurrencyGroupedByType(string $status): array
    {
        $rows = $this->createQueryBuilder('t')
            ->select('t.type AS type', 't.currency AS currency', 'SUM(t.amount) AS total')
            ->where('t.status = :status')
            ->groupBy('t.type', 't.currency')
            ->setParameter('status', $status)
            ->getQuery()
            ->getResult();

        $result = [];
        foreach ($rows as $row) {
            $result[$row['type']][$row['currency']] = $row['total'];
        }

        return $result;
    }

    /**
     * Somme des montants par devise, groupée par famille de moyen de paiement (carte/
     * Mobile Money) - Lot 5.
     * @return array<string, array<string, string>> famille => (devise => somme)
     */
    public function sumAmountByCurrencyGroupedByPaymentMethod(string $status): array
    {
        $rows = $this->createQueryBuilder('t')
            ->select('t.paymentMethodFamily AS family', 't.currency AS currency', 'SUM(t.amount) AS total')
            ->where('t.status = :status')
            ->groupBy('t.paymentMethodFamily', 't.currency')
            ->setParameter('status', $status)
            ->getQuery()
            ->getResult();

        $result = [];
        foreach ($rows as $row) {
            $result[$row['family']][$row['currency']] = $row['total'];
        }

        return $result;
    }
}
