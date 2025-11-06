<?php

declare(strict_types=1);

namespace App\Repository\Order;

use App\Entity\Order\Order;
use App\Entity\User\User;
use App\Entity\Site\Site;
use App\Enum\Order\OrderStatus;
use App\Repository\Core\AbstractRepository;
use Doctrine\Persistence\ManagerRegistry;
use Doctrine\ORM\QueryBuilder;

/**
 * Repository pour l'entité Order.
 * 
 * Responsabilités :
 * - Génération des références uniques (YYYY-MM-XXXXX)
 * - Recherche avancée avec filtres (statut, dates, client...)
 * - Statistiques (CA, nombre commandes...)
 * - Export données comptables
 */
class OrderRepository extends AbstractRepository
{
    protected array $sortableFields = [
        'id',
        'reference',
        'status',
        'grandTotal',
        'createdAt',
        'validatedAt',
    ];

    protected array $searchableFields = [
        'reference',
    ];

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Order::class);
        $this->defaultalias = 'o';
    }

    // ===============================================
    // GÉNÉRATION RÉFÉRENCE
    // ===============================================

    /**
     * Génère la prochaine référence de commande unique.
     * 
     * Format : YYYY-MM-XXXXX
     * Exemple : 2025-01-00142
     * 
     * Logique :
     * 1. Récupère la dernière commande du mois
     * 2. Incrémente le compteur
     * 3. Retourne la nouvelle référence
     * 
     * Thread-safe : Utilise une transaction pour éviter les doublons.
     */
    public function generateNextReference(?\DateTimeImmutable $date = null): string
    {
        $date ??= new \DateTimeImmutable();
        $prefix = $date->format('Y-m');

        // Récupérer la dernière référence du mois
        $qb = $this->createQueryBuilder('o')
            ->select('o.reference')
            ->where('o.reference LIKE :prefix')
            ->setParameter('prefix', $prefix . '-%')
            ->orderBy('o.reference', 'DESC')
            ->setMaxResults(1);

        $lastOrder = $qb->getQuery()->getOneOrNullResult();

        $sequence = 1;
        if ($lastOrder) {
            // Extraire le numéro de séquence (5 derniers caractères)
            $lastReference = $lastOrder['reference'];
            $lastSequence = (int)substr($lastReference, -5);
            $sequence = $lastSequence + 1;
        }

        return sprintf('%s-%05d', $prefix, $sequence);
    }

    /**
     * Vérifie si une référence existe déjà.
     */
    public function referenceExists(string $reference): bool
    {
        return $this->count(['reference' => $reference]) > 0;
    }

    /**
     * Génère une référence unique garantie (avec retry).
     * 
     * Utilisé en cas de race condition lors de génération parallèle.
     */
    public function generateUniqueReference(int $maxRetries = 5): string
    {
        $retries = 0;

        do {
            $reference = $this->generateNextReference();

            if (!$this->referenceExists($reference)) {
                return $reference;
            }

            $retries++;
            usleep(100000); // 100ms de pause entre tentatives

        } while ($retries < $maxRetries);

        throw new \RuntimeException('Impossible de générer une référence unique après ' . $maxRetries . ' tentatives.');
    }

    // ===============================================
    // RECHERCHE PAGINÉE
    // ===============================================

    /**
     * Recherche paginée avec filtres avancés.
     * 
     * Filtres disponibles :
     * - search : Recherche dans référence
     * - status : Statut (pending, confirmed, processing...)
     * - statuses : Tableau de statuts
     * - user_id : ID utilisateur
     * - site_id : ID site
     * - currency : Devise (EUR, USD...)
     * - min_total : Montant minimum
     * - max_total : Montant maximum
     * - created_from : Date création début
     * - created_to : Date création fin
     * - validated_from : Date validation début
     * - validated_to : Date validation fin
     * - sortBy : Champ de tri (voir $sortableFields)
     * - sortOrder : ASC ou DESC
     */
    public function findWithPagination(int $page, int $limit, array $filters = []): array
    {
        $qb = $this->createBaseQueryBuilder();

        // Recherche textuelle (référence)
        $this->applyTextSearch($qb, $filters);

        // Filtre statut unique
        if (!empty($filters['status'])) {
            $qb->andWhere('o.status = :status')
                ->setParameter('status', $filters['status']);
        }

        // Filtre statuts multiples
        if (!empty($filters['statuses']) && is_array($filters['statuses'])) {
            $qb->andWhere('o.status IN (:statuses)')
                ->setParameter('statuses', $filters['statuses']);
        }

        // Filtre utilisateur
        if (!empty($filters['user_id'])) {
            $qb->andWhere('o.user = :user_id')
                ->setParameter('user_id', $filters['user_id']);
        }

        // Filtre site
        if (!empty($filters['site_id'])) {
            $qb->andWhere('o.site = :site_id')
                ->setParameter('site_id', $filters['site_id']);
        }

        // Filtre devise
        if (!empty($filters['currency'])) {
            $qb->andWhere('o.currency = :currency')
                ->setParameter('currency', strtoupper($filters['currency']));
        }

        // Filtre type client
        if (!empty($filters['customer_type'])) {
            $qb->andWhere('o.customerType = :customer_type')
                ->setParameter('customer_type', $filters['customer_type']);
        }

        // Filtre montant total
        if (isset($filters['min_total'])) {
            $qb->andWhere('o.grandTotal >= :min_total')
                ->setParameter('min_total', $filters['min_total']);
        }

        if (isset($filters['max_total'])) {
            $qb->andWhere('o.grandTotal <= :max_total')
                ->setParameter('max_total', $filters['max_total']);
        }

        // Filtre plage de dates création
        $this->applyDateRangeFilter($qb, $filters, 'createdAt');

        // Filtre plage de dates validation
        if (isset($filters['validated_from'])) {
            $qb->andWhere('o.validatedAt >= :validated_from')
                ->setParameter('validated_from', $filters['validated_from']);
        }

        if (isset($filters['validated_to'])) {
            $qb->andWhere('o.validatedAt <= :validated_to')
                ->setParameter('validated_to', $filters['validated_to']);
        }

        // Tri
        $this->applySorting($qb, $filters);

        return $this->buildPaginatedResponse($qb, $page, $limit);
    }

    // ===============================================
    // RECHERCHES SPÉCIFIQUES
    // ===============================================

    /**
     * Trouve une commande par sa référence.
     */
    public function findByReference(string $reference): ?Order
    {
        return $this->findOneBy(['reference' => $reference]);
    }

    /**
     * Trouve les commandes d'un utilisateur.
     */
    public function findByUser(User $user, int $limit = 20): array
    {
        return $this->createQueryBuilder('o')
            ->where('o.user = :user')
            ->setParameter('user', $user)
            ->orderBy('o.createdAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * Trouve les commandes d'un site.
     */
    public function findBySite(Site $site, int $limit = 20): array
    {
        return $this->createQueryBuilder('o')
            ->where('o.site = :site')
            ->setParameter('site', $site)
            ->orderBy('o.createdAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * Trouve les commandes dans un état donné.
     */
    public function findByStatus(OrderStatus $status, ?Site $site = null, int $limit = 20): array
    {
        $qb = $this->createQueryBuilder('o')
            ->where('o.status = :status')
            ->setParameter('status', $status)
            ->orderBy('o.createdAt', 'DESC')
            ->setMaxResults($limit);

        if ($site) {
            $qb->andWhere('o.site = :site')
                ->setParameter('site', $site);
        }

        return $qb->getQuery()->getResult();
    }

    /**
     * Trouve les commandes en attente de validation (> X minutes).
     * 
     * Utile pour détecter les paiements abandonnés.
     */
    public function findPendingOrders(int $minutesOld = 30, ?Site $site = null): array
    {
        $threshold = new \DateTimeImmutable("-{$minutesOld} minutes");

        $qb = $this->createQueryBuilder('o')
            ->where('o.status = :status')
            ->andWhere('o.createdAt <= :threshold')
            ->setParameter('status', OrderStatus::PENDING)
            ->setParameter('threshold', $threshold)
            ->orderBy('o.createdAt', 'ASC');

        if ($site) {
            $qb->andWhere('o.site = :site')
                ->setParameter('site', $site);
        }

        return $qb->getQuery()->getResult();
    }

    /**
     * Trouve les commandes à expédier (status = PROCESSING).
     */
    public function findOrdersToShip(?Site $site = null): array
    {
        return $this->findByStatus(OrderStatus::PROCESSING, $site, 100);
    }

    // ===============================================
    // STATISTIQUES
    // ===============================================

    /**
     * Calcule le chiffre d'affaires sur une période.
     * 
     * @param \DateTimeImmutable $from Date début
     * @param \DateTimeImmutable $to Date fin
     * @param Site|null $site Site spécifique
     * @param array<OrderStatus>|null $statuses Statuts à inclure (null = tous les payés)
     */
    public function calculateRevenue(
        \DateTimeImmutable $from,
        \DateTimeImmutable $to,
        ?Site $site = null,
        ?array $statuses = null
    ): float {
        $qb = $this->createQueryBuilder('o')
            ->select('SUM(o.grandTotal)')
            ->where('o.createdAt >= :from')
            ->andWhere('o.createdAt <= :to')
            ->setParameter('from', $from)
            ->setParameter('to', $to);

        // Filtrer par statut (par défaut, statuts payés)
        $statuses ??= OrderStatus::paidStatuses();
        $qb->andWhere('o.status IN (:statuses)')
            ->setParameter('statuses', $statuses);

        if ($site) {
            $qb->andWhere('o.site = :site')
                ->setParameter('site', $site);
        }

        return (float)($qb->getQuery()->getSingleScalarResult() ?? 0.0);
    }

    /**
     * Compte le nombre de commandes sur une période.
     */
    public function countOrders(
        \DateTimeImmutable $from,
        \DateTimeImmutable $to,
        ?Site $site = null,
        ?OrderStatus $status = null
    ): int {
        $qb = $this->createQueryBuilder('o')
            ->select('COUNT(o.id)')
            ->where('o.createdAt >= :from')
            ->andWhere('o.createdAt <= :to')
            ->setParameter('from', $from)
            ->setParameter('to', $to);

        if ($site) {
            $qb->andWhere('o.site = :site')
                ->setParameter('site', $site);
        }

        if ($status) {
            $qb->andWhere('o.status = :status')
                ->setParameter('status', $status);
        }

        return (int)$qb->getQuery()->getSingleScalarResult();
    }

    /**
     * Calcule le panier moyen sur une période.
     */
    public function calculateAverageOrderValue(
        \DateTimeImmutable $from,
        \DateTimeImmutable $to,
        ?Site $site = null
    ): float {
        $qb = $this->createQueryBuilder('o')
            ->select('AVG(o.grandTotal)')
            ->where('o.createdAt >= :from')
            ->andWhere('o.createdAt <= :to')
            ->andWhere('o.status IN (:statuses)')
            ->setParameter('from', $from)
            ->setParameter('to', $to)
            ->setParameter('statuses', OrderStatus::paidStatuses());

        if ($site) {
            $qb->andWhere('o.site = :site')
                ->setParameter('site', $site);
        }

        return (float)($qb->getQuery()->getSingleScalarResult() ?? 0.0);
    }

    /**
     * Statistiques globales sur une période.
     * 
     * Retourne :
     * - total_orders : Nombre de commandes
     * - total_revenue : CA total
     * - average_order_value : Panier moyen
     * - total_items : Nombre d'articles vendus
     */
    public function getStatistics(
        \DateTimeImmutable $from,
        \DateTimeImmutable $to,
        ?Site $site = null
    ): array {
        $qb = $this->createQueryBuilder('o')
            ->select(
                'COUNT(o.id) as total_orders',
                'SUM(o.grandTotal) as total_revenue',
                'AVG(o.grandTotal) as average_order_value'
            )
            ->where('o.createdAt >= :from')
            ->andWhere('o.createdAt <= :to')
            ->andWhere('o.status IN (:statuses)')
            ->setParameter('from', $from)
            ->setParameter('to', $to)
            ->setParameter('statuses', OrderStatus::paidStatuses());

        if ($site) {
            $qb->andWhere('o.site = :site')
                ->setParameter('site', $site);
        }

        $result = $qb->getQuery()->getSingleResult();

        return [
            'total_orders' => (int)($result['total_orders'] ?? 0),
            'total_revenue' => (float)($result['total_revenue'] ?? 0.0),
            'average_order_value' => (float)($result['average_order_value'] ?? 0.0),
        ];
    }

    /**
     * Répartition des commandes par statut.
     */
    public function getStatusDistribution(
        ?Site $site = null,
        ?\DateTimeImmutable $from = null,
        ?\DateTimeImmutable $to = null,
        string $dateField = 'createdAt' // ou 'statusChangedAt' si tu as la colonne
    ): array {
        // Sécurité : empêcher l'injection sur le nom de champ
        $allowedDateFields = ['createdAt', 'statusChangedAt', 'paidAt', 'updatedAt'];
        if (!\in_array($dateField, $allowedDateFields, true)) {
            $dateField = 'createdAt';
        }

        $qb = $this->createQueryBuilder('o')
            ->select('o.status AS status', 'COUNT(o.id) AS cnt');

        if ($site) {
            $qb->andWhere('o.site = :site')
                ->setParameter('site', $site);
        }
        if ($from) {
            $qb->andWhere(sprintf('o.%s >= :from', $dateField))
                ->setParameter('from', $from);
        }
        if ($to) {
            $qb->andWhere(sprintf('o.%s <= :to', $dateField))
                ->setParameter('to', $to);
        }

        $qb->groupBy('o.status')
            ->orderBy('cnt', 'DESC');

        // Hydratation en array (plus léger)
        $rows = $qb->getQuery()->getArrayResult();

        // Initialise tous les statuts à 0 pour éviter les trous
        $distribution = [];
        foreach (OrderStatus::cases() as $case) {
            $distribution[$case->value] = [
                'status' => $case->value,
                'label'  => $case->getLabel(),
                'count'  => 0,
            ];
        }

        // Alimente avec les résultats réels
        foreach ($rows as $row) {
            // Selon le mapping, 'status' peut être string ou enum ; on harmonise
            $raw = $row['status'];
            $value = \is_string($raw)
                ? $raw
                : (is_object($raw) && property_exists($raw, 'value') ? $raw->value : (string) $raw);

            if ($enum = OrderStatus::tryFrom($value)) {
                $distribution[$enum->value]['count'] = (int) $row['cnt'];
            } else {
                // Statut inconnu (au cas où) : on peut l’ajouter dynamiquement
                $distribution[$value] = [
                    'status' => $value,
                    'label'  => (string) $value,
                    'count'  => (int) $row['cnt'],
                ];
            }
        }

        // (Optionnel) Ajoute un total global
        $distribution['_total'] = array_sum(array_column($distribution, 'count'));

        return $distribution;
    }


    // ===============================================
    // EXPORTS & ANALYTICS
    // ===============================================

    /**
     * Export des commandes pour comptabilité.
     * 
     * Retourne les commandes validées avec détails nécessaires.
     */
    public function findForAccountingExport(
        \DateTimeImmutable $from,
        \DateTimeImmutable $to,
        ?Site $site = null
    ): array {
        $qb = $this->createQueryBuilder('o')
            ->where('o.validatedAt >= :from')
            ->andWhere('o.validatedAt <= :to')
            ->andWhere('o.status IN (:statuses)')
            ->setParameter('from', $from)
            ->setParameter('to', $to)
            ->setParameter('statuses', OrderStatus::paidStatuses())
            ->orderBy('o.validatedAt', 'ASC');

        if ($site) {
            $qb->andWhere('o.site = :site')
                ->setParameter('site', $site);
        }

        return $qb->getQuery()->getResult();
    }
}
