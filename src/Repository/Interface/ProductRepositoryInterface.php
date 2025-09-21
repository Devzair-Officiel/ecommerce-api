<?php

namespace App\Repository\Interface;

use App\Entity\Product;
use App\ValueObject\ProductSearchCriteria;

interface ProductRepositoryInterface
{
    public function findByCriteria(ProductSearchCriteria $criteria): array;
    public function countByCriteria(ProductSearchCriteria $criteria): int;
    public function hasActiveOrders(Product $product): bool;
    public function findBySlugWithSite(string $slug, int $siteId): ?Product;
    public function findFeaturedBySite(int $siteId, int $limit = 10): array;
}