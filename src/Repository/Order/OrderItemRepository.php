<?php

declare(strict_types=1);

namespace App\Repository\Order;

use App\Entity\Order\Order;
use App\Entity\Order\OrderItem;
use App\Entity\Product\Product;
use App\Entity\Product\ProductVariant;
use App\Repository\Core\AbstractRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Repository pour l'entité OrderItem.
 * 
 * Responsabilités :
 * - Recherche d'items par commande, produit, variante
 * - Statistiques produits (best-sellers, CA par produit)
 * - Analytics (produits populaires, tendances)
 */
class OrderItemRepository extends AbstractRepository
{
    protected array $sortableFields = [
        'id',
        'quantity',
        'unitPrice',
    ];

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, OrderItem::class);
        $this->defaultalias = 'oi';
    }

    /**
     * Méthode de pagination (implémentation basique).
     * 
     * Pour des recherches avancées, étendre selon besoins.
     */
    public function findWithPagination(int $page, int $limit, array $filters = []): array
    {
        $qb = $this->createBaseQueryBuilder();

        // Filtre par commande
        if (!empty($filters['order_id'])) {
            $qb->andWhere('oi.order = :order_id')
                ->setParameter('order_id', $filters['order_id']);
        }

        // Filtre par produit
        if (!empty($filters['product_id'])) {
            $qb->andWhere('oi.product = :product_id')
                ->setParameter('product_id', $filters['product_id']);
        }

        // Filtre par variante
        if (!empty($filters['variant_id'])) {
            $qb->andWhere('oi.variant = :variant_id')
                ->setParameter('variant_id', $filters['variant_id']);
        }

        $this->applySorting($qb, $filters);

        return $this->buildPaginatedResponse($qb, $page, $limit);
    }

    // ===============================================
    // RECHERCHES SPÉCIFIQUES
    // ===============================================

    /**
     * Trouve tous les items d'une commande.
     */
    public function findByOrder(Order $order): array
    {
        return $this->createQueryBuilder('oi')
            ->where('oi.order = :order')
            ->setParameter('order', $order)
            ->orderBy('oi.id', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Trouve les commandes contenant un produit spécifique.
     */
    public function findByProduct(Product $product, int $limit = 50): array
    {
        return $this->createQueryBuilder('oi')
            ->where('oi.product = :product')
            ->setParameter('product', $product)
            ->orderBy('oi.id', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * Trouve les commandes contenant une variante spécifique.
     */
    public function findByVariant(ProductVariant $variant, int $limit = 50): array
    {
        return $this->createQueryBuilder('oi')
            ->where('oi.variant = :variant')
            ->setParameter('variant', $variant)
            ->orderBy('oi.id', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * Compte le nombre de fois qu'un produit a été commandé.
     */
    public function countOrdersByProduct(Product $product): int
    {
        return (int)$this->createQueryBuilder('oi')
            ->select('COUNT(DISTINCT oi.order)')
            ->where('oi.product = :product')
            ->setParameter('product', $product)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Compte le nombre de fois qu'une variante a été commandée.
     */
    public function countOrdersByVariant(ProductVariant $variant): int
    {
        return (int)$this->createQueryBuilder('oi')
            ->select('COUNT(DISTINCT oi.order)')
            ->where('oi.variant = :variant')
            ->setParameter('variant', $variant)
            ->getQuery()
            ->getSingleScalarResult();
    }

    // ===============================================
    // STATISTIQUES PRODUITS
    // ===============================================

    /**
     * Calcule la quantité totale vendue pour un produit.
     * 
     * @param Product $product
     * @param \DateTimeImmutable|null $from Date début (optionnel)
     * @param \DateTimeImmutable|null $to Date fin (optionnel)
     */
    public function getTotalQuantitySold(
        Product $product,
        ?\DateTimeImmutable $from = null,
        ?\DateTimeImmutable $to = null
    ): int {
        $qb = $this->createQueryBuilder('oi')
            ->select('SUM(oi.quantity)')
            ->join('oi.order', 'o')
            ->where('oi.product = :product')
            ->andWhere('o.status IN (:statuses)')
            ->setParameter('product', $product)
            ->setParameter('statuses', \App\Enum\Order\OrderStatus::paidStatuses());

        if ($from) {
            $qb->andWhere('o.validatedAt >= :from')
                ->setParameter('from', $from);
        }

        if ($to) {
            $qb->andWhere('o.validatedAt <= :to')
                ->setParameter('to', $to);
        }

        return (int)($qb->getQuery()->getSingleScalarResult() ?? 0);
    }

    /**
     * Calcule le chiffre d'affaires d'un produit.
     * 
     * Somme des (unitPrice × quantity) pour toutes les commandes payées.
     */
    public function getProductRevenue(
        Product $product,
        ?\DateTimeImmutable $from = null,
        ?\DateTimeImmutable $to = null
    ): float {
        $qb = $this->createQueryBuilder('oi')
            ->select('SUM(oi.unitPrice * oi.quantity)')
            ->join('oi.order', 'o')
            ->where('oi.product = :product')
            ->andWhere('o.status IN (:statuses)')
            ->setParameter('product', $product)
            ->setParameter('statuses', \App\Enum\Order\OrderStatus::paidStatuses());

        if ($from) {
            $qb->andWhere('o.validatedAt >= :from')
                ->setParameter('from', $from);
        }

        if ($to) {
            $qb->andWhere('o.validatedAt <= :to')
                ->setParameter('to', $to);
        }

        return (float)($qb->getQuery()->getSingleScalarResult() ?? 0.0);
    }

    /**
     * Top produits les plus vendus (en quantité).
     * 
     * @param int $limit Nombre de produits à retourner
     * @param \DateTimeImmutable|null $from Date début
     * @param \DateTimeImmutable|null $to Date fin
     * @return array Format : [['product_id' => X, 'product_name' => Y, 'total_quantity' => Z], ...]
     */
    public function getBestSellersByQuantity(
        int $limit = 10,
        ?\DateTimeImmutable $from = null,
        ?\DateTimeImmutable $to = null
    ): array {
        $qb = $this->createQueryBuilder('oi')
            ->select(
                'IDENTITY(oi.product) as product_id',
                'SUM(oi.quantity) as total_quantity'
            )
            ->join('oi.order', 'o')
            ->where('oi.product IS NOT NULL')
            ->andWhere('o.status IN (:statuses)')
            ->setParameter('statuses', \App\Enum\Order\OrderStatus::paidStatuses())
            ->groupBy('oi.product')
            ->orderBy('total_quantity', 'DESC')
            ->setMaxResults($limit);

        if ($from) {
            $qb->andWhere('o.validatedAt >= :from')
                ->setParameter('from', $from);
        }

        if ($to) {
            $qb->andWhere('o.validatedAt <= :to')
                ->setParameter('to', $to);
        }

        $results = $qb->getQuery()->getResult();

        // Enrichir avec les infos produit
        $enriched = [];
        foreach ($results as $result) {
            $product = $this->_em->getRepository(Product::class)->find($result['product_id']);

            if ($product) {
                $enriched[] = [
                    'product_id' => $result['product_id'],
                    'product_name' => $product->getName(),
                    'product_slug' => $product->getSlug(),
                    'total_quantity' => (int)$result['total_quantity'],
                ];
            }
        }

        return $enriched;
    }

    /**
     * Top produits les plus vendus (en CA).
     * 
     * @param int $limit Nombre de produits à retourner
     * @param \DateTimeImmutable|null $from Date début
     * @param \DateTimeImmutable|null $to Date fin
     * @return array Format : [['product_id' => X, 'product_name' => Y, 'total_revenue' => Z], ...]
     */
    public function getBestSellersByRevenue(
        int $limit = 10,
        ?\DateTimeImmutable $from = null,
        ?\DateTimeImmutable $to = null
    ): array {
        $qb = $this->createQueryBuilder('oi')
            ->select(
                'IDENTITY(oi.product) as product_id',
                'SUM(oi.unitPrice * oi.quantity) as total_revenue'
            )
            ->join('oi.order', 'o')
            ->where('oi.product IS NOT NULL')
            ->andWhere('o.status IN (:statuses)')
            ->setParameter('statuses', \App\Enum\Order\OrderStatus::paidStatuses())
            ->groupBy('oi.product')
            ->orderBy('total_revenue', 'DESC')
            ->setMaxResults($limit);

        if ($from) {
            $qb->andWhere('o.validatedAt >= :from')
                ->setParameter('from', $from);
        }

        if ($to) {
            $qb->andWhere('o.validatedAt <= :to')
                ->setParameter('to', $to);
        }

        $results = $qb->getQuery()->getResult();

        // Enrichir avec les infos produit
        $enriched = [];
        foreach ($results as $result) {
            $product = $this->_em->getRepository(Product::class)->find($result['product_id']);

            if ($product) {
                $enriched[] = [
                    'product_id' => $result['product_id'],
                    'product_name' => $product->getName(),
                    'product_slug' => $product->getSlug(),
                    'total_revenue' => (float)$result['total_revenue'],
                ];
            }
        }

        return $enriched;
    }

    /**
     * Variantes les plus populaires d'un produit.
     * 
     * Utile pour analyser quels formats se vendent le mieux.
     */
    public function getPopularVariantsForProduct(Product $product, int $limit = 5): array
    {
        $qb = $this->createQueryBuilder('oi')
            ->select(
                'IDENTITY(oi.variant) as variant_id',
                'SUM(oi.quantity) as total_quantity',
                'COUNT(DISTINCT oi.order) as order_count'
            )
            ->join('oi.order', 'o')
            ->where('oi.product = :product')
            ->andWhere('oi.variant IS NOT NULL')
            ->andWhere('o.status IN (:statuses)')
            ->setParameter('product', $product)
            ->setParameter('statuses', \App\Enum\Order\OrderStatus::paidStatuses())
            ->groupBy('oi.variant')
            ->orderBy('total_quantity', 'DESC')
            ->setMaxResults($limit);

        $results = $qb->getQuery()->getResult();

        // Enrichir avec les infos variante
        $enriched = [];
        foreach ($results as $result) {
            $variant = $this->_em->getRepository(ProductVariant::class)->find($result['variant_id']);

            if ($variant) {
                $enriched[] = [
                    'variant_id' => $result['variant_id'],
                    'variant_name' => $variant->getName(),
                    'variant_sku' => $variant->getSku(),
                    'total_quantity' => (int)$result['total_quantity'],
                    'order_count' => (int)$result['order_count'],
                ];
            }
        }

        return $enriched;
    }

    // ===============================================
    // ANALYTICS AVANCÉES
    // ===============================================

    /**
     * Analyse des ventes mensuelles d'un produit.
     * 
     * Retourne un tableau avec les ventes par mois sur une période.
     */
    public function getMonthlyProductSales(
        Product $product,
        \DateTimeImmutable $from,
        \DateTimeImmutable $to
    ): array {
        $qb = $this->createQueryBuilder('oi')
            ->select(
                "DATE_FORMAT(o.validatedAt, '%Y-%m') as month",
                'SUM(oi.quantity) as total_quantity',
                'SUM(oi.unitPrice * oi.quantity) as total_revenue',
                'COUNT(DISTINCT oi.order) as order_count'
            )
            ->join('oi.order', 'o')
            ->where('oi.product = :product')
            ->andWhere('o.validatedAt >= :from')
            ->andWhere('o.validatedAt <= :to')
            ->andWhere('o.status IN (:statuses)')
            ->setParameter('product', $product)
            ->setParameter('from', $from)
            ->setParameter('to', $to)
            ->setParameter('statuses', \App\Enum\Order\OrderStatus::paidStatuses())
            ->groupBy('month')
            ->orderBy('month', 'ASC');

        return $qb->getQuery()->getResult();
    }

    /**
     * Produits fréquemment achetés ensemble.
     * 
     * Trouve les produits souvent commandés dans la même commande qu'un produit donné.
     * Utile pour suggestions "Les clients ont aussi acheté...".
     */
    public function getFrequentlyBoughtTogether(Product $product, int $limit = 5): array
    {
        $qb = $this->createQueryBuilder('oi');

        $subQb = $this->_em->createQueryBuilder()
            ->select('IDENTITY(oi2.order)')
            ->from(OrderItem::class, 'oi2')
            ->where('oi2.product = :product');

        $qb
            ->select(
                'IDENTITY(oi.product) as product_id',
                'COUNT(DISTINCT oi.order) as frequency'
            )
            ->where($qb->expr()->in('oi.order', $subQb->getDQL()))
            ->andWhere('oi.product != :product')
            ->andWhere('oi.product IS NOT NULL')
            ->setParameter('product', $product)
            ->groupBy('oi.product')
            ->orderBy('frequency', 'DESC')
            ->setMaxResults($limit);

        $results = $qb->getQuery()->getResult();

        // Enrichir avec infos produit
        $enriched = [];
        foreach ($results as $result) {
            $relatedProduct = $this->_em->getRepository(Product::class)->find($result['product_id']);

            if ($relatedProduct) {
                $enriched[] = [
                    'product_id' => $result['product_id'],
                    'product_name' => $relatedProduct->getName(),
                    'product_slug' => $relatedProduct->getSlug(),
                    'frequency' => (int)$result['frequency'],
                ];
            }
        }

        return $enriched;
    }
}
