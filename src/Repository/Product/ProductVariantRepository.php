<?php

declare(strict_types=1);

namespace App\Repository\Product;

use App\Entity\Product\Product;
use App\Entity\Product\ProductVariant;
use App\Repository\Core\AbstractRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Repository pour l'entité ProductVariant.
 * 
 * Responsabilités :
 * - Gestion du stock
 * - Recherche de variantes disponibles
 * - Statistiques stock
 */
class ProductVariantRepository extends AbstractRepository
{
    protected array $sortableFields = ['id', 'name', 'sku', 'stock', 'position', 'createdAt'];
    protected array $searchableFields = ['name', 'sku'];

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ProductVariant::class);
    }

    /**
     * Recherche paginée avec filtres.
     * 
     * Filtres supportés :
     * - search : Recherche textuelle
     * - product_id : Variantes d'un produit
     * - has_stock : En stock uniquement
     * - low_stock : Stock faible
     */
    public function findWithPagination(int $page, int $limit, array $filters = []): array
    {
        $qb = $this->createBaseQueryBuilder();

        $this->applyTextSearch($qb, $filters);
        $this->applyProductFilter($qb, $filters);
        $this->applyStockFilters($qb, $filters);
        $this->applySorting($qb, $filters);

        return $this->buildPaginatedResponse($qb, $page, $limit);
    }

    // ===============================================
    // FILTRES SPÉCIFIQUES
    // ===============================================

    private function applyProductFilter(QueryBuilder $qb, array $filters): void
    {
        if (empty($filters['product_id'])) {
            return;
        }

        $qb->andWhere($this->defaultalias . '.product = :productId')
            ->setParameter('productId', $filters['product_id']);
    }

    private function applyStockFilters(QueryBuilder $qb, array $filters): void
    {
        // Filtre : en stock uniquement
        if (!empty($filters['has_stock']) && filter_var($filters['has_stock'], FILTER_VALIDATE_BOOLEAN)) {
            $qb->andWhere($this->defaultalias . '.stock > 0');
        }

        // Filtre : stock faible
        if (!empty($filters['low_stock']) && filter_var($filters['low_stock'], FILTER_VALIDATE_BOOLEAN)) {
            $qb->andWhere($this->defaultalias . '.stock > 0')
                ->andWhere($this->defaultalias . '.stock <= ' . $this->defaultalias . '.lowStockThreshold');
        }
    }

    // ===============================================
    // MÉTHODES MÉTIER
    // ===============================================

    /**
     * Trouve une variante par SKU
     */
    public function findBySku(string $sku): ?ProductVariant
    {
        return $this->createQueryBuilder($this->defaultalias)
            ->where($this->defaultalias . '.sku = :sku')
            ->andWhere($this->defaultalias . '.isDeleted = false')
            ->setParameter('sku', strtoupper($sku))
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Récupère toutes les variantes d'un produit
     */
    public function findByProduct(Product $product, bool $activeOnly = true): array
    {
        $qb = $this->createQueryBuilder($this->defaultalias)
            ->where($this->defaultalias . '.product = :product')
            ->andWhere($this->defaultalias . '.isDeleted = false')
            ->setParameter('product', $product)
            ->orderBy($this->defaultalias . '.position', 'ASC')
            ->addOrderBy($this->defaultalias . '.name', 'ASC');

        if ($activeOnly) {
            $qb->andWhere($this->defaultalias . '.closedAt IS NULL');
        }

        return $qb->getQuery()->getResult();
    }

    /**
     * Récupère les variantes disponibles (en stock) d'un produit
     */
    public function findAvailableByProduct(Product $product): array
    {
        return $this->createQueryBuilder($this->defaultalias)
            ->where($this->defaultalias . '.product = :product')
            ->andWhere($this->defaultalias . '.stock > 0')
            ->andWhere($this->defaultalias . '.closedAt IS NULL')
            ->andWhere($this->defaultalias . '.isDeleted = false')
            ->setParameter('product', $product)
            ->orderBy($this->defaultalias . '.position', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Trouve la variante par défaut d'un produit
     */
    public function findDefaultVariant(Product $product): ?ProductVariant
    {
        $qb = $this->createQueryBuilder($this->defaultalias)
            ->where($this->defaultalias . '.product = :product')
            ->andWhere($this->defaultalias . '.isDefault = true')
            ->andWhere($this->defaultalias . '.closedAt IS NULL')
            ->andWhere($this->defaultalias . '.isDeleted = false')
            ->setParameter('product', $product)
            ->setMaxResults(1);

        $default = $qb->getQuery()->getOneOrNullResult();

        // Fallback sur la première variante disponible
        if (!$default) {
            $variants = $this->findAvailableByProduct($product);
            $default = $variants[0] ?? null;
        }

        return $default;
    }

    /**
     * Trouve toutes les variantes avec stock faible
     */
    public function findLowStockVariants(): array
    {
        return $this->createQueryBuilder($this->defaultalias)
            ->where($this->defaultalias . '.stock > 0')
            ->andWhere($this->defaultalias . '.stock <= ' . $this->defaultalias . '.lowStockThreshold')
            ->andWhere($this->defaultalias . '.closedAt IS NULL')
            ->andWhere($this->defaultalias . '.isDeleted = false')
            ->orderBy($this->defaultalias . '.stock', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Trouve toutes les variantes en rupture de stock
     */
    public function findOutOfStockVariants(): array
    {
        return $this->createQueryBuilder($this->defaultalias)
            ->where($this->defaultalias . '.stock = 0')
            ->andWhere($this->defaultalias . '.closedAt IS NULL')
            ->andWhere($this->defaultalias . '.isDeleted = false')
            ->orderBy($this->defaultalias . '.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Vérifie si un SKU existe déjà
     */
    public function skuExists(string $sku, ?int $excludeId = null): bool
    {
        $qb = $this->createQueryBuilder($this->defaultalias)
            ->select('COUNT(' . $this->defaultalias . '.id)')
            ->where($this->defaultalias . '.sku = :sku')
            ->andWhere($this->defaultalias . '.isDeleted = false')
            ->setParameter('sku', strtoupper($sku));

        if ($excludeId !== null) {
            $qb->andWhere($this->defaultalias . '.id != :excludeId')
                ->setParameter('excludeId', $excludeId);
        }

        return (int) $qb->getQuery()->getSingleScalarResult() > 0;
    }

    /**
     * Met à jour le stock d'une variante (méthode sécurisée)
     */
    public function updateStock(int $variantId, int $newStock): bool
    {
        $conn = $this->getEntityManager()->getConnection();

        $sql = 'UPDATE product_variant SET stock = :stock WHERE id = :id AND is_deleted = 0';
        $stmt = $conn->prepare($sql);

        return $stmt->executeStatement([
            'stock' => max(0, $newStock),
            'id' => $variantId
        ]) > 0;
    }

    /**
     * Décrémente le stock (pour ajout au panier/commande)
     */
    public function decrementStock(int $variantId, int $quantity): bool
    {
        $conn = $this->getEntityManager()->getConnection();

        $sql = '
            UPDATE product_variant 
            SET stock = GREATEST(0, stock - :quantity) 
            WHERE id = :id 
            AND is_deleted = 0
            AND stock >= :quantity
        ';

        $stmt = $conn->prepare($sql);
        return $stmt->executeStatement([
            'quantity' => $quantity,
            'id' => $variantId
        ]) > 0;
    }

    /**
     * Incrémente le stock (pour annulation/retour)
     */
    public function incrementStock(int $variantId, int $quantity): bool
    {
        $conn = $this->getEntityManager()->getConnection();

        $sql = '
            UPDATE product_variant 
            SET stock = stock + :quantity 
            WHERE id = :id 
            AND is_deleted = 0
        ';

        $stmt = $conn->prepare($sql);
        return $stmt->executeStatement([
            'quantity' => $quantity,
            'id' => $variantId
        ]) > 0;
    }

    /**
     * Statistiques stock globales
     */
    public function getStockStatistics(): array
    {
        $conn = $this->getEntityManager()->getConnection();

        $sql = '
            SELECT 
                COUNT(DISTINCT id) as total_variants,
                COUNT(DISTINCT CASE WHEN stock > 0 THEN id END) as in_stock_count,
                COUNT(DISTINCT CASE WHEN stock = 0 THEN id END) as out_of_stock_count,
                COUNT(DISTINCT CASE WHEN stock > 0 AND stock <= low_stock_threshold THEN id END) as low_stock_count,
                SUM(stock) as total_stock_units,
                AVG(stock) as avg_stock
            FROM product_variant
            WHERE closed_at IS NULL
            AND is_deleted = 0
        ';

        $stmt = $conn->prepare($sql);
        $result = $stmt->executeQuery();

        return $result->fetchAssociative() ?: [];
    }

    /**
     * Récupère les variantes les plus vendues (nécessite OrderItem)
     * TODO: À implémenter après création de OrderItem
     */
    // public function findBestSellers(int $limit = 10): array
    // {
    //     return $this->createQueryBuilder($this->defaultalias)
    //         ->select($this->defaultalias)
    //         ->addSelect('COUNT(oi.id) as HIDDEN order_count')
    //         ->leftJoin($this->defaultalias . '.orderItems', 'oi')
    //         ->where($this->defaultalias . '.closedAt IS NULL')
    //         ->andWhere($this->defaultalias . '.isDeleted = false')
    //         ->groupBy($this->defaultalias . '.id')
    //         ->orderBy('order_count', 'DESC')
    //         ->setMaxResults($limit)
    //         ->getQuery()
    //         ->getResult();
    // }
}
