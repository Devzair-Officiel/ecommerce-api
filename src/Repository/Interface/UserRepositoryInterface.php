<?php

namespace App\Repository\Interface;

use App\Entity\User;
use App\ValueObject\UserSearchCriteria;

interface UserRepositoryInterface
{
    public function findByCriteria(UserSearchCriteria $criteria): array;
    public function countByCriteria(UserSearchCriteria $criteria): int;
    public function findByEmail(string $email): ?User;
    public function findActiveUsers(): array;
    public function hasActiveOrders(User $user): bool;
}