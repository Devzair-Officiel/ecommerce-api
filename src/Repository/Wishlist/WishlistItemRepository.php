<?php

declare(strict_types=1);

namespace App\Repository\Wishlist;

use App\Entity\Product\ProductVariant;
use App\Entity\Wishlist\Wishlist;
use App\Entity\Wishlist\WishlistItem;
use App\Repository\Core\AbstractRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Repository pour l'entité WishlistItem.
 */
class WishlistItemRepository extends AbstractRepository
{
    protected array $sortableFields = ['id', 'createdAt', 'priority'];
    protected array $searchableFields = ['note'];

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, WishlistItem::class);
    }

    public function findWithPagination(int $page, int $limit, array $filters = []): array
    {
        return [];
    }

    /**
     * Trouve un item par wishlist + variante.
     * 
     * @param Wishlist $wishlist Wishlist concernée
     * @param ProductVariant $variant Variante recherchée
     * @return WishlistItem|null
     */
    public function findByWishlistAndVariant(Wishlist $wishlist, ProductVariant $variant): ?WishlistItem
    {
        return $this->createQueryBuilder('wi')
            ->where('wi.wishlist = :wishlist')
            ->andWhere('wi.variant = :variant')
            ->setParameter('wishlist', $wishlist)
            ->setParameter('variant', $variant)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Récupère tous les items d'une wishlist.
     * 
     * @param Wishlist $wishlist Wishlist concernée
     * @return WishlistItem[]
     */
    public function findByWishlist(Wishlist $wishlist): array
    {
        return $this->createQueryBuilder('wi')
            ->where('wi.wishlist = :wishlist')
            ->setParameter('wishlist', $wishlist)
            ->orderBy('wi.priority', 'DESC')
            ->addOrderBy('wi.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Supprime tous les items d'une wishlist.
     * 
     * @param Wishlist $wishlist Wishlist à vider
     * @return int Nombre d'items supprimés
     */
    public function deleteByWishlist(Wishlist $wishlist): int
    {
        return $this->createQueryBuilder('wi')
            ->delete()
            ->where('wi.wishlist = :wishlist')
            ->setParameter('wishlist', $wishlist)
            ->getQuery()
            ->execute();
    }
}
