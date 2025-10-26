<?php

declare(strict_types=1);

namespace App\Repository\Wishlist;

use App\Entity\Site\Site;
use App\Entity\User\User;
use App\Entity\Wishlist\Wishlist;
use App\Repository\Core\AbstractRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Repository pour l'entité Wishlist.
 * 
 * Responsabilités :
 * - Recherche par user/shareToken
 * - Gestion wishlist par défaut
 * - Statistiques utilisateur
 */
class WishlistRepository extends AbstractRepository
{
    protected array $sortableFields = ['id', 'name', 'createdAt', 'updatedAt'];
    protected array $searchableFields = ['name', 'description'];

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Wishlist::class);
    }

    /**
     * Recherche paginée avec filtres.
     */
    public function findWithPagination(int $page, int $limit, array $filters = []): array
    {
        $qb = $this->createBaseQueryBuilder();

        $this->applyMultipleLikeFilters($qb, $filters, $this->searchableFields);
        $this->applyTextSearch($qb, $filters);
        $this->applySiteFilter($qb, $filters);
        $this->applyUserFilter($qb, $filters);
        $this->applyPublicOnlyFilter($qb, $filters);
        $this->applySorting($qb, $filters);

        return $this->buildPaginatedResponse($qb, $page, $limit);
    }

    // ===============================================
    // RECHERCHE PAR UTILISATEUR
    // ===============================================

    /**
     * Récupère toutes les wishlists d'un utilisateur.
     * 
     * @param User $user Utilisateur
     * @param Site $site Site concerné
     * @return Wishlist[]
     */
    public function findByUser(User $user, Site $site): array
    {
        return $this->createQueryBuilder('w')
            ->where('w.user = :user')
            ->andWhere('w.site = :site')
            ->andWhere('w.isDeleted = false')
            ->setParameter('user', $user)
            ->setParameter('site', $site)
            ->orderBy('w.isDefault', 'DESC')
            ->addOrderBy('w.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Récupère la wishlist par défaut d'un utilisateur.
     * 
     * Si aucune, retourne null (sera créée par le service).
     * 
     * @param User $user Utilisateur
     * @param Site $site Site concerné
     * @return Wishlist|null
     */
    public function findDefaultByUser(User $user, Site $site): ?Wishlist
    {
        return $this->createQueryBuilder('w')
            ->where('w.user = :user')
            ->andWhere('w.site = :site')
            ->andWhere('w.isDefault = true')
            ->andWhere('w.isDeleted = false')
            ->setParameter('user', $user)
            ->setParameter('site', $site)
            ->getQuery()
            ->getOneOrNullResult();
    }

    // ===============================================
    // PARTAGE PUBLIC
    // ===============================================

    /**
     * Trouve une wishlist par token de partage.
     * 
     * @param string $shareToken Token de partage
     * @return Wishlist|null
     */
    public function findByShareToken(string $shareToken): ?Wishlist
    {
        return $this->createQueryBuilder('w')
            ->where('w.shareToken = :token')
            ->andWhere('w.isPublic = true')
            ->andWhere('w.isDeleted = false')
            ->setParameter('token', $shareToken)
            ->getQuery()
            ->getOneOrNullResult();
    }

    // ===============================================
    // STATISTIQUES
    // ===============================================

    /**
     * Compte le nombre de wishlists d'un utilisateur.
     * 
     * @param User $user Utilisateur
     * @param Site $site Site concerné
     * @return int
     */
    public function countByUser(User $user, Site $site): int
    {
        return (int) $this->createQueryBuilder('w')
            ->select('COUNT(w.id)')
            ->where('w.user = :user')
            ->andWhere('w.site = :site')
            ->andWhere('w.isDeleted = false')
            ->setParameter('user', $user)
            ->setParameter('site', $site)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Récupère les wishlists les plus remplies.
     * 
     * @param Site $site Site concerné
     * @param int $limit Nombre de résultats
     * @return Wishlist[]
     */
    public function findMostPopular(Site $site, int $limit = 10): array
    {
        return $this->createQueryBuilder('w')
            ->select('w', 'COUNT(wi.id) as HIDDEN itemCount')
            ->leftJoin('w.items', 'wi')
            ->where('w.site = :site')
            ->andWhere('w.isDeleted = false')
            ->groupBy('w.id')
            ->orderBy('itemCount', 'DESC')
            ->setMaxResults($limit)
            ->setParameter('site', $site)
            ->getQuery()
            ->getResult();
    }

    // ===============================================
    // FILTRES SPÉCIFIQUES
    // ===============================================

    private function applySiteFilter($qb, array $filters): void
    {
        if (empty($filters['site_id'])) {
            return;
        }

        $qb->andWhere('w.site = :siteId')
            ->setParameter('siteId', $filters['site_id']);
    }

    private function applyUserFilter($qb, array $filters): void
    {
        if (empty($filters['user_id'])) {
            return;
        }

        $qb->andWhere('w.user = :userId')
            ->setParameter('userId', $filters['user_id']);
    }

    private function applyPublicOnlyFilter($qb, array $filters): void
    {
        if (empty($filters['public_only']) || !filter_var($filters['public_only'], FILTER_VALIDATE_BOOLEAN)) {
            return;
        }

        $qb->andWhere('w.isPublic = true');
    }
}
