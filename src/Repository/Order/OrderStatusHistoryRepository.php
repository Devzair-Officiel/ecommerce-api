<?php

declare(strict_types=1);

namespace App\Repository\Order;

use App\Entity\Order\Order;
use App\Entity\Order\OrderStatusHistory;
use App\Enum\Order\OrderStatus;
use App\Repository\Core\AbstractRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Repository pour l'entité OrderStatusHistory.
 * 
 * Responsabilités :
 * - Recherche d'historique par commande
 * - Analytics sur les délais de traitement
 * - Statistiques de performance
 */
class OrderStatusHistoryRepository extends AbstractRepository
{
    protected array $sortableFields = [
        'id',
        'createdAt',
    ];

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, OrderStatusHistory::class);
        $this->defaultalias = 'osh';
    }

    /**
     * Méthode de pagination basique.
     */
    public function findWithPagination(int $page, int $limit, array $filters = []): array
    {
        $qb = $this->createBaseQueryBuilder();

        // Filtre par commande
        if (!empty($filters['order_id'])) {
            $qb->andWhere('osh.order = :order_id')
                ->setParameter('order_id', $filters['order_id']);
        }

        // Filtre par statut destination
        if (!empty($filters['to_status'])) {
            $qb->andWhere('osh.toStatus = :to_status')
                ->setParameter('to_status', $filters['to_status']);
        }

        // Filtre par type d'acteur
        if (!empty($filters['changed_by_type'])) {
            $qb->andWhere('osh.changedByType = :changed_by_type')
                ->setParameter('changed_by_type', $filters['changed_by_type']);
        }

        $this->applySorting($qb, $filters);

        return $this->buildPaginatedResponse($qb, $page, $limit);
    }

    // ===============================================
    // RECHERCHES SPÉCIFIQUES
    // ===============================================

    /**
     * Récupère l'historique complet d'une commande.
     */
    public function findByOrder(Order $order): array
    {
        return $this->createQueryBuilder('osh')
            ->where('osh.order = :order')
            ->setParameter('order', $order)
            ->orderBy('osh.createdAt', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Récupère la dernière transition d'une commande.
     */
    public function findLastTransition(Order $order): ?OrderStatusHistory
    {
        return $this->createQueryBuilder('osh')
            ->where('osh.order = :order')
            ->setParameter('order', $order)
            ->orderBy('osh.createdAt', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Trouve toutes les transitions vers un statut donné.
     */
    public function findByToStatus(OrderStatus $status, int $limit = 50): array
    {
        return $this->createQueryBuilder('osh')
            ->where('osh.toStatus = :status')
            ->setParameter('status', $status)
            ->orderBy('osh.createdAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * Compte les transitions d'un statut vers un autre.
     * 
     * Utile pour analyser les flux les plus courants.
     */
    public function countTransitions(OrderStatus $from, OrderStatus $to): int
    {
        return (int)$this->createQueryBuilder('osh')
            ->select('COUNT(osh.id)')
            ->where('osh.fromStatus = :from')
            ->andWhere('osh.toStatus = :to')
            ->setParameter('from', $from)
            ->setParameter('to', $to)
            ->getQuery()
            ->getSingleScalarResult();
    }

    // ===============================================
    // ANALYTICS DÉLAIS
    // ===============================================

    /**
     * Calcule le délai moyen entre deux statuts.
     * 
     * Exemple : Délai moyen entre CONFIRMED et SHIPPED
     * 
     * @return float Délai en heures
     */
    public function getAverageDelayBetweenStatuses(
        OrderStatus $fromStatus,
        OrderStatus $toStatus,
        ?\DateTimeImmutable $since = null
    ): float {
        // Requête SQL native pour calculer la différence de temps
        $sql = "
            SELECT AVG(TIMESTAMPDIFF(HOUR, h1.created_at, h2.created_at)) as avg_delay
            FROM order_status_history h1
            JOIN order_status_history h2 ON h1.order_id = h2.order_id
            WHERE h1.to_status = :from_status
            AND h2.to_status = :to_status
            AND h2.created_at > h1.created_at
        ";

        if ($since) {
            $sql .= " AND h1.created_at >= :since";
        }

        $conn = $this->_em->getConnection();
        $stmt = $conn->prepare($sql);
        $stmt->bindValue('from_status', $fromStatus->value);
        $stmt->bindValue('to_status', $toStatus->value);

        if ($since) {
            $stmt->bindValue('since', $since->format('Y-m-d H:i:s'));
        }

        $result = $stmt->executeQuery()->fetchAssociative();

        return (float)($result['avg_delay'] ?? 0.0);
    }

    /**
     * Statistiques de performance globales.
     * 
     * Calcule les délais moyens pour chaque étape du processus.
     */
    public function getPerformanceMetrics(?\DateTimeImmutable $since = null): array
    {
        return [
            'pending_to_confirmed' => $this->getAverageDelayBetweenStatuses(
                OrderStatus::PENDING,
                OrderStatus::CONFIRMED,
                $since
            ),
            'confirmed_to_processing' => $this->getAverageDelayBetweenStatuses(
                OrderStatus::CONFIRMED,
                OrderStatus::PROCESSING,
                $since
            ),
            'processing_to_shipped' => $this->getAverageDelayBetweenStatuses(
                OrderStatus::PROCESSING,
                OrderStatus::SHIPPED,
                $since
            ),
            'shipped_to_delivered' => $this->getAverageDelayBetweenStatuses(
                OrderStatus::SHIPPED,
                OrderStatus::DELIVERED,
                $since
            ),
        ];
    }
}
