<?php

declare(strict_types=1);

namespace App\ValueObject;

readonly class ProductSearchCriteria
{
    public function __construct(
        public ?string $search = null,
        public ?int $categoryId = null,
        public ?int $siteId = null,
        public ?bool $isActive = null,
        public ?bool $isFeatured = null,
        public ?float $minPrice = null,
        public ?float $maxPrice = null,
        public ?bool $inStock = null,
        public ?string $sku = null,
        public string $sortBy = 'createdAt',
        public string $sortOrder = 'DESC',
        public int $offset = 0,
        public int $limit = 20
    ) {
        // Validation des paramètres
        if ($this->limit > 100) {
            throw new \InvalidArgumentException('Limit cannot exceed 100');
        }

        if (!in_array($this->sortOrder, ['ASC', 'DESC'])) {
            throw new \InvalidArgumentException('Sort order must be ASC or DESC');
        }
    }
}
