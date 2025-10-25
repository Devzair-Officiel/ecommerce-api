<?php

declare(strict_types=1);

namespace App\Repository\Product;

use App\Entity\Product\Category;
use App\Entity\Site\Site;
use App\Repository\Core\AbstractRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

class CategoryRepository extends AbstractRepository
{
    protected array $sortableFields = ['id', 'name', 'slug', 'position', 'createdAt'];
    protected array $searchableFields = ['name', 'slug', 'description'];

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Category::class);
    }

    public function findWithPagination(int $page, int $limit, array $filters = []): array
    {
        $qb = $this->createBaseQueryBuilder();

        $this->applyTextSearch($qb, $filters);
        $this->applySiteFilter($qb, $filters);
        $this->applyLocaleFilter($qb, $filters);
        $this->applyParentFilter($qb, $filters);
        $this->applyActiveOnlyFilter($qb, $filters);
        $this->applyWithProductsFilter($qb, $filters);
        $this->applySorting($qb, $filters);

        return $this->buildPaginatedResponse($qb, $page, $limit);
    }

    private function applySiteFilter(QueryBuilder $qb, array $filters): void
    {
        if (empty($filters['site_id'])) {
            return;
        }

        $qb->andWhere($this->defaultalias . '.site = :siteId')
            ->setParameter('siteId', $filters['site_id']);
    }

    private function applyLocaleFilter(QueryBuilder $qb, array $filters): void
    {
        if (empty($filters['locale'])) {
            return;
        }

        $qb->andWhere($this->defaultalias . '.locale = :locale')
            ->setParameter('locale', $filters['locale']);
    }

    private function applyParentFilter(QueryBuilder $qb, array $filters): void
    {
        if (!array_key_exists('parent_id', $filters)) {
            return;
        }

        if ($filters['parent_id'] === null || $filters['parent_id'] === 'null') {
            $qb->andWhere($this->defaultalias . '.parent IS NULL');
        } else {
            $qb->andWhere($this->defaultalias . '.parent = :parentId')
                ->setParameter('parentId', $filters['parent_id']);
        }
    }

    private function applyActiveOnlyFilter(QueryBuilder $qb, array $filters): void
    {
        if (empty($filters['active_only']) || !filter_var($filters['active_only'], FILTER_VALIDATE_BOOLEAN)) {
            return;
        }

        $qb->andWhere($this->defaultalias . '.closedAt IS NULL')
            ->andWhere($this->defaultalias . '.isDeleted = false');
    }

    private function applyWithProductsFilter(QueryBuilder $qb, array $filters): void
    {
        if (empty($filters['with_products']) || !filter_var($filters['with_products'], FILTER_VALIDATE_BOOLEAN)) {
            return;
        }

        $qb->innerJoin($this->defaultalias . '.products', 'p')
            ->andWhere('p.isDeleted = false');
    }

    public function findRootCategories(Site $site, string $locale, bool $activeOnly = true): array
    {
        $qb = $this->createQueryBuilder($this->defaultalias)
            ->where($this->defaultalias . '.site = :site')
            ->andWhere($this->defaultalias . '.locale = :locale')
            ->andWhere($this->defaultalias . '.parent IS NULL')
            ->andWhere($this->defaultalias . '.isDeleted = false')
            ->setParameter('site', $site)
            ->setParameter('locale', $locale)
            ->orderBy($this->defaultalias . '.position', 'ASC')
            ->addOrderBy($this->defaultalias . '.name', 'ASC');

        if ($activeOnly) {
            $qb->andWhere($this->defaultalias . '.closedAt IS NULL');
        }

        return $qb->getQuery()->getResult();
    }

    public function findCategoryTree(Site $site, string $locale, bool $activeOnly = true): array
    {
        return $this->findRootCategories($site, $locale, $activeOnly);
    }

    public function findBySlug(string $slug, Site $site, string $locale): ?Category
    {
        return $this->createQueryBuilder($this->defaultalias)
            ->where($this->defaultalias . '.slug = :slug')
            ->andWhere($this->defaultalias . '.site = :site')
            ->andWhere($this->defaultalias . '.locale = :locale')
            ->andWhere($this->defaultalias . '.isDeleted = false')
            ->setParameter('slug', $slug)
            ->setParameter('site', $site)
            ->setParameter('locale', $locale)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function isSlugTaken(string $slug, Site $site, string $locale, ?int $excludeId = null): bool
    {
        $qb = $this->createQueryBuilder($this->defaultalias)
            ->select('COUNT(' . $this->defaultalias . '.id)')
            ->where($this->defaultalias . '.slug = :slug')
            ->andWhere($this->defaultalias . '.site = :site')
            ->andWhere($this->defaultalias . '.locale = :locale')
            ->andWhere($this->defaultalias . '.isDeleted = false')
            ->setParameter('slug', $slug)
            ->setParameter('site', $site)
            ->setParameter('locale', $locale);

        if ($excludeId !== null) {
            $qb->andWhere($this->defaultalias . '.id != :excludeId')
                ->setParameter('excludeId', $excludeId);
        }

        return (int) $qb->getQuery()->getSingleScalarResult() > 0;
    }

    public function findWithProductCounts(Site $site, string $locale): array
    {
        return $this->createQueryBuilder('c')
            ->select('c', 'COUNT(p.id) as productCount')
            ->leftJoin('c.products', 'p')
            ->where('c.site = :site')
            ->andWhere('c.locale = :locale')
            ->andWhere('c.isDeleted = false')
            ->andWhere('c.closedAt IS NULL')
            ->groupBy('c.id')
            ->orderBy('c.position', 'ASC')
            ->setParameter('site', $site)
            ->setParameter('locale', $locale)
            ->getQuery()
            ->getResult();
    }

    public function findPopularCategories(Site $site, string $locale, int $limit = 10): array
    {
        return $this->createQueryBuilder('c')
            ->select('c', 'COUNT(p.id) as HIDDEN productCount')
            ->innerJoin('c.products', 'p')
            ->where('c.site = :site')
            ->andWhere('c.locale = :locale')
            ->andWhere('c.isDeleted = false')
            ->andWhere('c.closedAt IS NULL')
            ->andWhere('p.isDeleted = false')
            ->groupBy('c.id')
            ->orderBy('productCount', 'DESC')
            ->setMaxResults($limit)
            ->setParameter('site', $site)
            ->setParameter('locale', $locale)
            ->getQuery()
            ->getResult();
    }

    public function countActiveBySite(Site $site, string $locale): int
    {
        return (int) $this->createQueryBuilder($this->defaultalias)
            ->select('COUNT(' . $this->defaultalias . '.id)')
            ->where($this->defaultalias . '.site = :site')
            ->andWhere($this->defaultalias . '.locale = :locale')
            ->andWhere($this->defaultalias . '.closedAt IS NULL')
            ->andWhere($this->defaultalias . '.isDeleted = false')
            ->setParameter('site', $site)
            ->setParameter('locale', $locale)
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function findEmptyCategories(Site $site, string $locale): array
    {
        return $this->createQueryBuilder('c')
            ->leftJoin('c.products', 'p')
            ->where('c.site = :site')
            ->andWhere('c.locale = :locale')
            ->andWhere('c.isDeleted = false')
            ->groupBy('c.id')
            ->having('COUNT(p.id) = 0')
            ->setParameter('site', $site)
            ->setParameter('locale', $locale)
            ->getQuery()
            ->getResult();
    }
}
