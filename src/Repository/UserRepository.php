<?php

namespace App\Repository;

use App\Entity\User;
use Doctrine\ORM\QueryBuilder;
use App\Utils\QueryFilterUtils;
use Doctrine\Persistence\ManagerRegistry;
use Doctrine\ORM\Tools\Pagination\Paginator as DoctrinePaginator;
use Symfony\Component\Security\Core\User\PasswordUpgraderInterface;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Symfony\Component\Security\Core\Exception\UnsupportedUserException;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;

/**
 * @extends ServiceEntityRepository<User>
 */
class UserRepository extends ServiceEntityRepository implements PasswordUpgraderInterface
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

    /**
     * Récupère les utilisateurs avec pagination.
     *
     * @param int $offset L'offset pour la pagination.
     * @param int $limit  Le nombre d'éléments par page.
     * @return array
     */
    public function findUsersWithPaginationAndFilters(int $offset, int $limit, array $filters): array
    {
        $qb = $this->createQueryBuilder('u');

        // Appliquer les filtres
        $this->applyFilters($qb, $filters);

        // Applique le tri avec 'id DESC' comme fallback si aucun paramètre n'est précisé
        QueryFilterUtils::applySorting(
            qb: $qb,
            filters: $filters,
            allowedFields: ['receptionReviewAt', 'createdAt', 'title', 'id'],
            alias: 'u',
            defaultSortBy: 'id',
            defaultOrder: 'DESC'
        );

        QueryFilterUtils::applyPagination($qb, $offset, $limit);

        // Utilisation du Paginator pour gérer la pagination
        $paginator = new DoctrinePaginator($qb->getQuery(), true);

        return [
            'items' => iterator_to_array($paginator),
            'totalItemsFound' => count($paginator), // Total des éléments trouvés avec les filtres
        ];
    }

    private function applyFilters(QueryBuilder $qb, array $filters): QueryBuilder
    {
        // Filtre par email
        if (!empty($filters['email'])) {
            $qb->andWhere('u.email LIKE :email')
                ->setParameter('email', '%' . $filters['email'] . '%');
        }

        // Filtre par Prénom
        if (!empty($filters['firstname'])) {
            $qb->andWhere('u.firstname LIKE :firstname')
                ->setParameter('firstname', '%' . $filters['firstname'] . '%');
        }

        // Filtre par Nom
        if (!empty($filters['lastname'])) {
            $qb->andWhere('u.lastname LIKE :lastname')
                ->setParameter('lastname', '%' . $filters['lastname'] . '%');
        }

        // Filtre par user actif/inactif
        if (isset($filters['valid'])) { // Vérifie explicitement si 'valid' est présent dans les filtres
            $qb->andWhere('u.valid = :valid')
                ->setParameter('valid', (int) $filters['valid']); // Cast en entier pour éviter les problèmes de type
        }

        return $qb;
    }
}
