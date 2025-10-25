<?php

declare(strict_types=1);

namespace App\Repository\Product;

use App\Entity\Product\Product;
use App\Entity\Site\Site;
use App\Repository\Core\AbstractRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Repository pour l'entité Product.
 * 
 * Responsabilités :
 * - Recherche paginée avec filtres avancés
 * - Optimisation des requêtes (JOIN stratégiques)
 * - Méthodes métier pour le catalogue
 */
class ProductRepository extends AbstractRepository
{
    protected array $sortableFields = ['id', 'name', 'sku', 'position', 'createdAt'];
    protected array $searchableFields = ['name', 'sku', 'shortDescription'];

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Product::class);
    }

    /**
     * Recherche paginée avec filtres e-commerce.
     * 
     * Filtres supportés :
     * - search : Recherche textuelle
     * - site_id : Filtrer par site
     * - category_id / category_slug : Filtrer par catégorie
     * - customer_type : B2C, B2B, BOTH
     * - is_featured : Produits mis en avant
     * - is_new : Nouveautés
     * - has_stock : Produits disponibles uniquement
     * - locale : Langue
     */
    public function findWithPagination(int $page, int $limit, array $filters = []): array
    {
        $qb = $this->createBaseQueryBuilder();

        // JOIN avec variantes pour filtres stock/prix
        if (isset($filters['has_stock']) || isset($filters['sortBy']) && $filters['sortBy'] === 'price') {
            $qb->leftJoin($this->defaultalias . '.variants', 'v');
        }

        $this->applyTextSearch($qb, $filters);
        $this->applySiteFilter($qb, $filters);
        $this->applyCategoryFilter($qb, $filters);
        $this->applyCustomerTypeFilter($qb, $filters);
        $this->applyFeaturedFilter($qb, $filters);
        $this->applyNewFilter($qb, $filters);
        $this->applyStockFilter($qb, $filters);
        $this->applyLocaleFilter($qb, $filters);
        $this->applySorting($qb, $filters);

        return $this->buildPaginatedResponse($qb, $page, $limit);
    }

    // ===============================================
    // FILTRES SPÉCIFIQUES
    // ===============================================

    private function applySiteFilter(QueryBuilder $qb, array $filters): void
    {
        if (empty($filters['site_id'])) {
            return;
        }

        $qb->andWhere($this->defaultalias . '.site = :siteId')
            ->setParameter('siteId', $filters['site_id']);
    }

    private function applyCategoryFilter(QueryBuilder $qb, array $filters): void
    {
        if (!empty($filters['category_id'])) {
            $qb->join($this->defaultalias . '.categories', 'c')
                ->andWhere('c.id = :categoryId')
                ->setParameter('categoryId', $filters['category_id']);
        } elseif (!empty($filters['category_slug'])) {
            $qb->join($this->defaultalias . '.categories', 'c')
                ->andWhere('c.slug = :categorySlug')
                ->setParameter('categorySlug', $filters['category_slug']);
        }
    }

    private function applyCustomerTypeFilter(QueryBuilder $qb, array $filters): void
    {
        if (empty($filters['customer_type'])) {
            return;
        }

        $customerType = $filters['customer_type'];

        $qb->andWhere(
            $qb->expr()->orX(
                $this->defaultalias . '.customerType = :customerType',
                $this->defaultalias . '.customerType = :both'
            )
        )
            ->setParameter('customerType', $customerType)
            ->setParameter('both', 'BOTH');
    }

    private function applyFeaturedFilter(QueryBuilder $qb, array $filters): void
    {
        if (!isset($filters['is_featured'])) {
            return;
        }

        $isFeatured = filter_var($filters['is_featured'], FILTER_VALIDATE_BOOLEAN);
        $qb->andWhere($this->defaultalias . '.isFeatured = :isFeatured')
            ->setParameter('isFeatured', $isFeatured);
    }

    private function applyNewFilter(QueryBuilder $qb, array $filters): void
    {
        if (!isset($filters['is_new'])) {
            return;
        }

        $isNew = filter_var($filters['is_new'], FILTER_VALIDATE_BOOLEAN);
        $qb->andWhere($this->defaultalias . '.isNew = :isNew')
            ->setParameter('isNew', $isNew);
    }

    private function applyStockFilter(QueryBuilder $qb, array $filters): void
    {
        if (empty($filters['has_stock']) || !filter_var($filters['has_stock'], FILTER_VALIDATE_BOOLEAN)) {
            return;
        }

        // Produits avec au moins une variante en stock
        $qb->andWhere('v.stock > 0')
            ->andWhere('v.closedAt IS NULL')
            ->andWhere('v.isDeleted = false');
    }

    private function applyLocaleFilter(QueryBuilder $qb, array $filters): void
    {
        if (empty($filters['locale'])) {
            return;
        }

        $qb->andWhere($this->defaultalias . '.locale = :locale')
            ->setParameter('locale', $filters['locale']);
    }

    // ===============================================
    // MÉTHODES MÉTIER
    // ===============================================

    /**
     * Trouve un produit par slug et site
     */
    public function findBySlugAndSite(string $slug, Site $site, ?string $locale = null): ?Product
    {
        $qb = $this->createQueryBuilder($this->defaultalias)
            ->where($this->defaultalias . '.slug = :slug')
            ->andWhere($this->defaultalias . '.site = :site')
            ->andWhere($this->defaultalias . '.closedAt IS NULL')
            ->andWhere($this->defaultalias . '.isDeleted = false')
            ->setParameter('slug', $slug)
            ->setParameter('site', $site);

        if ($locale !== null) {
            $qb->andWhere($this->defaultalias . '.locale = :locale')
                ->setParameter('locale', $locale);
        }

        return $qb->getQuery()->getOneOrNullResult();
    }

    /**
     * Trouve un produit par SKU
     */
    public function findBySku(string $sku, ?Site $site = null): ?Product
    {
        $qb = $this->createQueryBuilder($this->defaultalias)
            ->where($this->defaultalias . '.sku = :sku')
            ->andWhere($this->defaultalias . '.isDeleted = false')
            ->setParameter('sku', strtoupper($sku));

        if ($site !== null) {
            $qb->andWhere($this->defaultalias . '.site = :site')
                ->setParameter('site', $site);
        }

        return $qb->getQuery()->getOneOrNullResult();
    }

    /**
     * Récupère les produits mis en avant d'un site
     */
    public function findFeaturedProducts(Site $site, ?string $locale = null, int $limit = 10): array
    {
        $qb = $this->createQueryBuilder($this->defaultalias)
            ->where($this->defaultalias . '.site = :site')
            ->andWhere($this->defaultalias . '.isFeatured = true')
            ->andWhere($this->defaultalias . '.closedAt IS NULL')
            ->andWhere($this->defaultalias . '.isDeleted = false')
            ->setParameter('site', $site)
            ->orderBy($this->defaultalias . '.position', 'ASC')
            ->addOrderBy($this->defaultalias . '.createdAt', 'DESC')
            ->setMaxResults($limit);

        if ($locale !== null) {
            $qb->andWhere($this->defaultalias . '.locale = :locale')
                ->setParameter('locale', $locale);
        }

        return $qb->getQuery()->getResult();
    }

    /**
     * Récupère les nouveautés d'un site
     */
    public function findNewProducts(Site $site, ?string $locale = null, int $limit = 10): array
    {
        $qb = $this->createQueryBuilder($this->defaultalias)
            ->where($this->defaultalias . '.site = :site')
            ->andWhere($this->defaultalias . '.isNew = true')
            ->andWhere($this->defaultalias . '.closedAt IS NULL')
            ->andWhere($this->defaultalias . '.isDeleted = false')
            ->setParameter('site', $site)
            ->orderBy($this->defaultalias . '.createdAt', 'DESC')
            ->setMaxResults($limit);

        if ($locale !== null) {
            $qb->andWhere($this->defaultalias . '.locale = :locale')
                ->setParameter('locale', $locale);
        }

        return $qb->getQuery()->getResult();
    }

    /**
     * Récupère les produits d'une catégorie avec stock
     */
    public function findByCategoryWithStock(int $categoryId, Site $site, ?string $locale = null): array
    {
        $qb = $this->createQueryBuilder($this->defaultalias)
            ->join($this->defaultalias . '.categories', 'c')
            ->join($this->defaultalias . '.variants', 'v')
            ->where('c.id = :categoryId')
            ->andWhere($this->defaultalias . '.site = :site')
            ->andWhere('v.stock > 0')
            ->andWhere($this->defaultalias . '.closedAt IS NULL')
            ->andWhere($this->defaultalias . '.isDeleted = false')
            ->setParameter('categoryId', $categoryId)
            ->setParameter('site', $site)
            ->orderBy($this->defaultalias . '.position', 'ASC');

        if ($locale !== null) {
            $qb->andWhere($this->defaultalias . '.locale = :locale')
                ->setParameter('locale', $locale);
        }

        return $qb->getQuery()->getResult();
    }

    /**
     * Recherche de produits similaires (même catégories)
     */
    public function findSimilarProducts(Product $product, int $limit = 4): array
    {
        // Récupérer les IDs des catégories du produit
        $categoryIds = $product->getCategories()->map(fn($c) => $c->getId())->toArray();

        if (empty($categoryIds)) {
            return [];
        }

        $qb = $this->createQueryBuilder($this->defaultalias)
            ->join($this->defaultalias . '.categories', 'c')
            ->where('c.id IN (:categoryIds)')
            ->andWhere($this->defaultalias . '.id != :productId')
            ->andWhere($this->defaultalias . '.site = :site')
            ->andWhere($this->defaultalias . '.locale = :locale')
            ->andWhere($this->defaultalias . '.closedAt IS NULL')
            ->andWhere($this->defaultalias . '.isDeleted = false')
            ->setParameter('categoryIds', $categoryIds)
            ->setParameter('productId', $product->getId())
            ->setParameter('site', $product->getSite())
            ->setParameter('locale', $product->getLocale())
            ->orderBy('RAND()') // Aléatoire pour varier
            ->setMaxResults($limit);

        return $qb->getQuery()->getResult();
    }

    /**
     * Compte les produits d'un site
     */
    public function countBySite(Site $site, bool $activeOnly = true): int
    {
        $qb = $this->createQueryBuilder($this->defaultalias)
            ->select('COUNT(' . $this->defaultalias . '.id)')
            ->where($this->defaultalias . '.site = :site')
            ->andWhere($this->defaultalias . '.isDeleted = false')
            ->setParameter('site', $site);

        if ($activeOnly) {
            $qb->andWhere($this->defaultalias . '.closedAt IS NULL');
        }

        return (int) $qb->getQuery()->getSingleScalarResult();
    }

    /**
     * Trouve les produits avec stock bas (au moins une variante en stock faible)
     */
    public function findLowStockProducts(Site $site): array
    {
        return $this->createQueryBuilder($this->defaultalias)
            ->join($this->defaultalias . '.variants', 'v')
            ->where($this->defaultalias . '.site = :site')
            ->andWhere('v.stock > 0')
            ->andWhere('v.stock <= v.lowStockThreshold')
            ->andWhere($this->defaultalias . '.closedAt IS NULL')
            ->andWhere($this->defaultalias . '.isDeleted = false')
            ->setParameter('site', $site)
            ->getQuery()
            ->getResult();
    }

    /**
     * Trouve les produits sans stock (aucune variante disponible)
     */
    public function findOutOfStockProducts(Site $site): array
    {
        $subQuery = $this->getEntityManager()->createQueryBuilder()
            ->select('IDENTITY(v2.product)')
            ->from('App\Entity\Product\ProductVariant', 'v2')
            ->where('v2.stock > 0')
            ->andWhere('v2.closedAt IS NULL')
            ->getDQL();

        return $this->createQueryBuilder($this->defaultalias)
            ->where($this->defaultalias . '.site = :site')
            ->andWhere($this->defaultalias . '.id NOT IN (' . $subQuery . ')')
            ->andWhere($this->defaultalias . '.closedAt IS NULL')
            ->andWhere($this->defaultalias . '.isDeleted = false')
            ->setParameter('site', $site)
            ->getQuery()
            ->getResult();
    }

    /**
     * Recherche de produits par certification
     */
    public function findByCertification(string $certification, Site $site): array
    {
        return $this->createQueryBuilder($this->defaultalias)
            ->where($this->defaultalias . '.site = :site')
            ->andWhere('JSON_CONTAINS(' . $this->defaultalias . '.attributes, :certification, \'$.certifications\') = 1')
            ->andWhere($this->defaultalias . '.closedAt IS NULL')
            ->andWhere($this->defaultalias . '.isDeleted = false')
            ->setParameter('site', $site)
            ->setParameter('certification', json_encode($certification))
            ->getQuery()
            ->getResult();
    }

    /**
     * Statistiques produits par site
     */
    public function getStatsBySite(Site $site): array
    {
        $conn = $this->getEntityManager()->getConnection();

        $sql = '
            SELECT 
                COUNT(DISTINCT p.id) as total_products,
                COUNT(DISTINCT CASE WHEN p.is_featured = 1 THEN p.id END) as featured_count,
                COUNT(DISTINCT CASE WHEN p.is_new = 1 THEN p.id END) as new_count,
                COUNT(DISTINCT v.id) as total_variants,
                SUM(v.stock) as total_stock_units
            FROM product p
            LEFT JOIN product_variant v ON v.product_id = p.id AND v.closed_at IS NULL
            WHERE p.site_id = :siteId
            AND p.closed_at IS NULL
            AND p.is_deleted = 0
        ';

        $stmt = $conn->prepare($sql);
        $result = $stmt->executeQuery(['siteId' => $site->getId()]);

        return $result->fetchAssociative() ?: [];
    }
}
