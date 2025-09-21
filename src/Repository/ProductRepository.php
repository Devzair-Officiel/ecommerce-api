<?php

namespace App\Repository;

use App\Entity\Product;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;
use App\ValueObject\ProductSearchCriteria;
use App\Repository\Interface\ProductRepositoryInterface;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;

/**
 * @extends ServiceEntityRepository<Product>
 */
class ProductRepository extends ServiceEntityRepository implements ProductRepositoryInterface
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Product::class);
    }

    public function findByCriteria(ProductSearchCriteria $criteria): array
    {
        $qb = $this->createQueryBuilder('p');

        $this->buildCriteria($qb, $criteria);
        $this->buildSorting($qb, $criteria);
        $this->buildPagination($qb, $criteria);

        return $qb->getQuery()->getResult();
    }

    public function countByCriteria(ProductSearchCriteria $criteria): int
    {
        $qb = $this->createQueryBuilder('p')
            ->select('COUNT(p.id)');

        $this->buildCriteria($qb, $criteria);

        return (int) $qb->getQuery()->getSingleScalarResult();
    }

    public function hasActiveOrders(Product $product): bool
    {
        return (bool) $this->getEntityManager()
            ->createQuery(
                'SELECT COUNT(oi.id) 
                 FROM App\Entity\OrderItem oi 
                 JOIN oi.order o 
                 WHERE oi.product = :product 
                 AND o.status IN (:statuses)'
            )
            ->setParameter('product', $product)
            ->setParameter('statuses', ['pending', 'processing', 'shipped'])
            ->getSingleScalarResult();
    }

    public function findBySlugWithSite(string $slug, int $siteId): ?Product
    {
        return $this->createQueryBuilder('p')
            ->where('p.slug = :slug')
            ->andWhere('p.site = :siteId')
            ->setParameter('slug', $slug)
            ->setParameter('siteId', $siteId)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function findFeaturedBySite(int $siteId, int $limit = 10): array
    {
        return $this->createQueryBuilder('p')
            ->where('p.site = :siteId')
            ->andWhere('p.featured = true')
            ->andWhere('p.isActive = true')
            ->orderBy('p.createdAt', 'DESC')
            ->setMaxResults($limit)
            ->setParameter('siteId', $siteId)
            ->getQuery()
            ->getResult();
    }

    // === MÉTHODES PRIVÉES POUR DÉCOUPER LA LOGIQUE ===

    private function buildCriteria(QueryBuilder $qb, ProductSearchCriteria $criteria): void
    {
        if ($criteria->search) {
            $qb->andWhere('p.name LIKE :search OR p.description LIKE :search')
                ->setParameter('search', '%' . $criteria->search . '%');
        }

        if ($criteria->categoryId) {
            $qb->join('p.categories', 'c')
                ->andWhere('c.id = :categoryId')
                ->setParameter('categoryId', $criteria->categoryId);
        }

        if ($criteria->siteId) {
            $qb->andWhere('p.site = :siteId')
                ->setParameter('siteId', $criteria->siteId);
        }

        if ($criteria->isActive !== null) {
            $qb->andWhere('p.isActive = :isActive')
                ->setParameter('isActive', $criteria->isActive);
        }

        if ($criteria->isFeatured !== null) {
            $qb->andWhere('p.featured = :featured')
                ->setParameter('featured', $criteria->isFeatured);
        }

        if ($criteria->minPrice) {
            $qb->andWhere('p.price >= :minPrice')
                ->setParameter('minPrice', $criteria->minPrice);
        }

        if ($criteria->maxPrice) {
            $qb->andWhere('p.price <= :maxPrice')
                ->setParameter('maxPrice', $criteria->maxPrice);
        }

        if ($criteria->inStock !== null) {
            $operator = $criteria->inStock ? '>' : '=';
            $qb->andWhere("p.stock {$operator} 0");
        }

        if ($criteria->sku) {
            $qb->andWhere('p.sku = :sku')
                ->setParameter('sku', $criteria->sku);
        }
    }

    private function buildSorting(QueryBuilder $qb, ProductSearchCriteria $criteria): void
    {
        $allowedSortFields = ['id', 'name', 'price', 'createdAt', 'stock', 'featured'];

        if (in_array($criteria->sortBy, $allowedSortFields, true)) {
            $qb->orderBy('p.' . $criteria->sortBy, $criteria->sortOrder);
        } else {
            $qb->orderBy('p.createdAt', 'DESC'); // Fallback sécurisé
        }
    }

    private function buildPagination(QueryBuilder $qb, ProductSearchCriteria $criteria): void
    {
        $qb->setFirstResult($criteria->offset)
            ->setMaxResults($criteria->limit);
    }
}