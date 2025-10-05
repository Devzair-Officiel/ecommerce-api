<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\User;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;
use App\Repository\Core\AbstractRepository;
use Symfony\Component\Security\Core\User\PasswordUpgraderInterface;
use Symfony\Component\Security\Core\Exception\UnsupportedUserException;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;

class UserRepository extends AbstractRepository implements PasswordUpgraderInterface
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, User::class);
    }

    /**
     * Used to upgrade (rehash) the user's password automatically over time.
     */
    public function upgradePassword(PasswordAuthenticatedUserInterface $user, string $newHashedPassword): void
    {
        if (!$user instanceof User) {
            throw new UnsupportedUserException(sprintf('Instances of "%s" are not supported.', $user::class));
        }

        $user->setPassword($newHashedPassword);
        $this->getEntityManager()->persist($user);
        $this->getEntityManager()->flush();
    }


    /** Configuration des champs autorisés pour le tri */
    protected array $sortableFields = [
        'id',
        'email',
        'firstName',
        'lastName',
        'createdAt',
        'isActive'
    ];

    /** Champs de recherche textuelle */
    protected array $searchableFields = [
        'email',
        'firstName',
        'lastName'
    ];


    /**
     * Implémentation de la recherche paginée pour User.
     */
    public function findWithPagination(int $page, int $limit, array $filters = []): array
    {
        $qb = $this->createBaseQueryBuilder();

        // Application des filtres génériques (hérités)
        $this->applyTextSearch($qb, $filters);
        $this->applySorting($qb, $filters);
        $this->applyBooleanFilter($qb, $filters, 'isActive', 'isActive');
        $this->applyDateRangeFilter($qb, $filters, 'createdAt');

        // Filtres spécifiques à User (logique métier)
        $this->applyUserSpecificFilters($qb, $filters);

        return $this->buildPaginatedResponse($qb, $page, $limit);
    }

    /**
     * Recherche par email (méthode spécifique).
     */
    public function findByEmail(string $email): ?User
    {
        return $this->findOneBy(['email' => $email]);
    }

    /**
     * Utilisateurs actifs uniquement.
     */
    public function findValidUsers(): array
    {
        return $this->createQueryBuilder('u')
            ->where('u.isValid = true')
            ->getQuery()
            ->getResult();
    }

    /**
     * Filtres spécifiques au domaine User.
     */
    private function applyUserSpecificFilters(QueryBuilder $qb, array $filters): void
    {
        // Filtre par rôles
        if (isset($filters['roles']) && is_array($filters['roles'])) {
            $qb->andWhere('JSON_CONTAINS(e.roles, :roles) = 1')
                ->setParameter('roles', json_encode($filters['roles']));
        }

        // Filtre par division (relation)
        if (isset($filters['division_id'])) {
            $qb->join('e.division', 'd')
                ->andWhere('d.id = :divisionId')
                ->setParameter('divisionId', $filters['division_id']);
        }

        // Filtre par équipe (many-to-many)
        if (isset($filters['team_id'])) {
            $qb->join('e.teams', 't')
                ->andWhere('t.id = :teamId')
                ->setParameter('teamId', $filters['team_id']);
        }
    }
}
