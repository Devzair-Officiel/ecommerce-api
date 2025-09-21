<?php

namespace App\Repository;

use App\Entity\User;
use Doctrine\ORM\QueryBuilder;
use App\ValueObject\UserSearchCriteria;
use Doctrine\Persistence\ManagerRegistry;
use App\Repository\Interface\UserRepositoryInterface;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;

class UserRepository extends ServiceEntityRepository implements UserRepositoryInterface
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, User::class);
    }

    public function findByCriteria(UserSearchCriteria $criteria): array
    {
        $qb = $this->createQueryBuilder('u');

        $this->buildCriteria($qb, $criteria);
        $this->buildSorting($qb, $criteria);
        $this->buildPagination($qb, $criteria);

        return $qb->getQuery()->getResult();
    }

    public function countByCriteria(UserSearchCriteria $criteria): int
    {
        $qb = $this->createQueryBuilder('u')
            ->select('COUNT(u.id)');

        $this->buildCriteria($qb, $criteria);

        return (int) $qb->getQuery()->getSingleScalarResult();
    }

    public function findByEmail(string $email): ?User
    {
        return $this->createQueryBuilder('u')
            ->where('u.email = :email')
            ->setParameter('email', $email)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function findActiveUsers(): array
    {
        return $this->createQueryBuilder('u')
            ->where('u.isActive = true')
            ->orderBy('u.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    public function hasActiveOrders(User $user): bool
    {
        return (bool) $this->getEntityManager()
            ->createQuery(
                'SELECT COUNT(o.id) 
                 FROM App\Entity\Order o 
                 WHERE o.user = :user 
                 AND o.status IN (:statuses)'
            )
            ->setParameter('user', $user)
            ->setParameter('statuses', ['pending', 'processing', 'shipped'])
            ->getSingleScalarResult();
    }

    // === MÉTHODES PRIVÉES ===

    private function buildCriteria(QueryBuilder $qb, UserSearchCriteria $criteria): void
    {
        if ($criteria->search) {
            $qb->andWhere('u.firstName LIKE :search OR u.lastName LIKE :search OR u.email LIKE :search')
                ->setParameter('search', '%' . $criteria->search . '%');
        }

        if ($criteria->isActive !== null) {
            $qb->andWhere('u.isActive = :isActive')
                ->setParameter('isActive', $criteria->isActive);
        }

        if ($criteria->roles) {
            $conditions = [];
            foreach ($criteria->roles as $index => $role) {
                $conditions[] = "JSON_CONTAINS(u.roles, :role{$index}) = 1";
                $qb->setParameter("role{$index}", json_encode($role));
            }
            $qb->andWhere('(' . implode(' OR ', $conditions) . ')');
        }

        if ($criteria->email) {
            $qb->andWhere('u.email = :email')
                ->setParameter('email', $criteria->email);
        }
    }

    private function buildSorting(QueryBuilder $qb, UserSearchCriteria $criteria): void
    {
        $allowedSortFields = ['id', 'email', 'firstName', 'lastName', 'createdAt', 'isActive'];

        if (in_array($criteria->sortBy, $allowedSortFields, true)) {
            $qb->orderBy('u.' . $criteria->sortBy, $criteria->sortOrder);
        } else {
            $qb->orderBy('u.createdAt', 'DESC');
        }
    }

    private function buildPagination(QueryBuilder $qb, UserSearchCriteria $criteria): void
    {
        $qb->setFirstResult($criteria->offset)
            ->setMaxResults($criteria->limit);
    }
}