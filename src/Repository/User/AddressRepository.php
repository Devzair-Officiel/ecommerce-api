<?php

declare(strict_types=1);

namespace App\Repository\User;

use App\Entity\User\User;
use App\Entity\User\Address;
use App\Enum\User\AddressType;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;
use App\Repository\Core\AbstractRepository;

/**
 * Repository pour l'entité Address.
 */
class AddressRepository extends AbstractRepository
{
    protected array $sortableFields = ['id', 'fullName', 'city', 'postalCode', 'createdAt'];
    protected array $searchableFields = ['fullName', 'company', 'street', 'city', 'postalCode'];

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Address::class);
    }

    /**
     * Recherche paginée avec filtres.
     */
    public function findWithPagination(int $page, int $limit, array $filters = []): array
    {
        $qb = $this->createBaseQueryBuilder();

        $this->applyTextSearch($qb, $filters);
        $this->applyUserFilter($qb, $filters);
        $this->applyTypeFilter($qb, $filters);
        $this->applyCountryFilter($qb, $filters);
        $this->applyDefaultFilter($qb, $filters);
        $this->applySorting($qb, $filters);

        return $this->buildPaginatedResponse($qb, $page, $limit);
    }

    // ===============================================
    // FILTRES SPÉCIFIQUES
    // ===============================================

    private function applyUserFilter(QueryBuilder $qb, array $filters): void
    {
        if (empty($filters['user_id'])) {
            return;
        }

        $qb->andWhere($this->defaultalias . '.user = :userId')
            ->setParameter('userId', $filters['user_id']);
    }

    private function applyTypeFilter(QueryBuilder $qb, array $filters): void
    {
        if (empty($filters['type'])) {
            return;
        }

        $qb->andWhere($this->defaultalias . '.type = :type')
            ->setParameter('type', $filters['type']);
    }

    private function applyCountryFilter(QueryBuilder $qb, array $filters): void
    {
        if (empty($filters['country'])) {
            return;
        }

        $qb->andWhere($this->defaultalias . '.countryCode = :country')
            ->setParameter('country', strtoupper($filters['country']));
    }

    private function applyDefaultFilter(QueryBuilder $qb, array $filters): void
    {
        if (!isset($filters['is_default'])) {
            return;
        }

        $isDefault = filter_var($filters['is_default'], FILTER_VALIDATE_BOOLEAN);
        $qb->andWhere($this->defaultalias . '.isDefault = :isDefault')
            ->setParameter('isDefault', $isDefault);
    }

    // ===============================================
    // MÉTHODES MÉTIER
    // ===============================================

    /**
     * Trouve toutes les adresses d'un utilisateur.
     */
    public function findByUser(User $user): array
    {
        return $this->createQueryBuilder($this->defaultalias)
            ->where($this->defaultalias . '.user = :user')
            ->andWhere($this->defaultalias . '.isDeleted = false')
            ->setParameter('user', $user)
            ->orderBy($this->defaultalias . '.isDefault', 'DESC')
            ->addOrderBy($this->defaultalias . '.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Trouve l'adresse par défaut d'un utilisateur.
     */
    public function findDefaultByUser(User $user): ?Address
    {
        return $this->createQueryBuilder($this->defaultalias)
            ->where($this->defaultalias . '.user = :user')
            ->andWhere($this->defaultalias . '.isDefault = true')
            ->andWhere($this->defaultalias . '.isDeleted = false')
            ->setParameter('user', $user)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Trouve les adresses par type (facturation, livraison).
     */
    public function findByUserAndType(User $user, AddressType $type): array
    {
        $qb = $this->createQueryBuilder($this->defaultalias)
            ->where($this->defaultalias . '.user = :user')
            ->andWhere($this->defaultalias . '.isDeleted = false')
            ->setParameter('user', $user)
            ->orderBy($this->defaultalias . '.isDefault', 'DESC')
            ->addOrderBy($this->defaultalias . '.createdAt', 'DESC');

        // Filtrer selon le type
        if ($type === AddressType::BILLING) {
            $qb->andWhere($qb->expr()->orX(
                $this->defaultalias . '.type = :billing',
                $this->defaultalias . '.type = :both'
            ))
                ->setParameter('billing', AddressType::BILLING->value)
                ->setParameter('both', AddressType::BOTH->value);
        } elseif ($type === AddressType::SHIPPING) {
            $qb->andWhere($qb->expr()->orX(
                $this->defaultalias . '.type = :shipping',
                $this->defaultalias . '.type = :both'
            ))
                ->setParameter('shipping', AddressType::SHIPPING->value)
                ->setParameter('both', AddressType::BOTH->value);
        } else {
            $qb->andWhere($this->defaultalias . '.type = :type')
                ->setParameter('type', $type->value);
        }

        return $qb->getQuery()->getResult();
    }

    /**
     * Compte les adresses d'un utilisateur.
     */
    public function countByUser(User $user): int
    {
        return (int) $this->createQueryBuilder($this->defaultalias)
            ->select('COUNT(' . $this->defaultalias . '.id)')
            ->where($this->defaultalias . '.user = :user')
            ->andWhere($this->defaultalias . '.isDeleted = false')
            ->setParameter('user', $user)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Retire le flag isDefault de toutes les adresses d'un utilisateur.
     * Utilisé avant de définir une nouvelle adresse par défaut.
     */
    public function unsetDefaultForUser(User $user): void
    {
        $this->createQueryBuilder($this->defaultalias)
            ->update()
            ->set($this->defaultalias . '.isDefault', ':false')
            ->where($this->defaultalias . '.user = :user')
            ->setParameter('false', false)
            ->setParameter('user', $user)
            ->getQuery()
            ->execute();
    }
}
