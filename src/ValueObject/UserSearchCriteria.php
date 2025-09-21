<?php

declare(strict_types=1);

namespace App\ValueObject;

readonly class UserSearchCriteria
{
    public function __construct(
        public ?string $search = null,
        public ?bool $isActive = null,
        public ?array $roles = null,
        public ?string $email = null,
        public string $sortBy = 'createdAt',
        public string $sortOrder = 'DESC',
        public int $offset = 0,
        public int $limit = 20
    ) {
        if ($this->limit > 100) {
            throw new \InvalidArgumentException('Limit cannot exceed 100');
        }

        if (!in_array($this->sortOrder, ['ASC', 'DESC'])) {
            throw new \InvalidArgumentException('Sort order must be ASC or DESC');
        }
    }
}
