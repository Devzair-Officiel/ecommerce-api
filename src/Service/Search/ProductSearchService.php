<?php

declare(strict_types=1);

namespace App\Service\Search;

use App\Utils\PaginationUtils;
use App\ValueObject\ProductSearchCriteria;
use App\Repository\Interface\ProductRepositoryInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

class ProductSearchService
{
    public function __construct(
        private readonly ProductRepositoryInterface $productRepository,
        private readonly UrlGeneratorInterface $urlGenerator
    ) {}

    public function searchProducts(array $filters): array
    {
        $criteria = $this->buildCriteria($filters);

        $products = $this->productRepository->findByCriteria($criteria);
        $totalItems = $this->productRepository->countByCriteria($criteria);

        return $this->buildPaginatedResponse($products, $totalItems, $criteria, $filters);
    }

    public function getFeaturedProducts(int $siteId, int $limit = 10): array
    {
        return $this->productRepository->findFeaturedBySite($siteId, $limit);
    }

    // === CONSTRUCTION DES CRITÈRES ===

    private function buildCriteria(array $filters): ProductSearchCriteria
    {
        $page = max(1, (int) ($filters['page'] ?? 1));
        $limit = min(100, max(1, (int) ($filters['limit'] ?? 20)));

        return new ProductSearchCriteria(
            search: $filters['search'] ?? null,
            categoryId: isset($filters['category_id']) ? (int) $filters['category_id'] : null,
            siteId: isset($filters['site_id']) ? (int) $filters['site_id'] : null,
            isActive: isset($filters['is_active']) ? filter_var($filters['is_active'], FILTER_VALIDATE_BOOLEAN) : null,
            isFeatured: isset($filters['is_featured']) ? filter_var($filters['is_featured'], FILTER_VALIDATE_BOOLEAN) : null,
            minPrice: isset($filters['min_price']) ? (float) $filters['min_price'] : null,
            maxPrice: isset($filters['max_price']) ? (float) $filters['max_price'] : null,
            inStock: isset($filters['in_stock']) ? filter_var($filters['in_stock'], FILTER_VALIDATE_BOOLEAN) : null,
            sku: $filters['sku'] ?? null,
            sortBy: $filters['sort_by'] ?? 'createdAt',
            sortOrder: strtoupper($filters['sort_order'] ?? 'DESC'),
            offset: ($page - 1) * $limit,
            limit: $limit
        );
    }

    private function buildPaginatedResponse(array $items, int $totalItems, ProductSearchCriteria $criteria, array $filters): array
    {
        $currentPage = (int) ceil($criteria->offset / $criteria->limit) + 1;

        $pagination = new PaginationUtils($currentPage, $criteria->limit, $totalItems);
        $links = $pagination->generateLinks('api_products_list', $this->urlGenerator, $filters);

        return [
            'items' => $items,
            'page' => $currentPage,
            'limit' => $criteria->limit,
            'total_items' => $totalItems,
            'total_pages' => $pagination->getTotalPages(),
            'total_items_found' => count($items), 
            'links' => $links
        ];
    }
}