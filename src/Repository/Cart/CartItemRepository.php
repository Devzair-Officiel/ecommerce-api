<?php

declare(strict_types=1);

namespace App\Repository\Cart;

use App\Entity\Cart\Cart;
use App\Entity\Cart\CartItem;
use App\Entity\Product\ProductVariant;
use App\Repository\Core\AbstractRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Repository pour l'entité CartItem.
 * 
 * Responsabilités :
 * - Recherche items par panier/variante
 * - Statistiques produits populaires dans paniers
 * - Détection problèmes (prix changé, stock insuffisant)
 */
class CartItemRepository extends AbstractRepository
{
    protected array $sortableFields = ['id', 'createdAt', 'quantity'];
    protected array $searchableFields = [];

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, CartItem::class);
    }

    /**
     * Recherche paginée (rarement utilisée, surtout pour admin).
     */
    public function findWithPagination(int $page, int $limit, array $filters = []): array
    {
        $qb = $this->createBaseQueryBuilder();

        $this->applyCartFilter($qb, $filters);
        $this->applyVariantFilter($qb, $filters);
        $this->applySorting($qb, $filters);

        return $this->buildPaginatedResponse($qb, $page, $limit);
    }

    // ===============================================
    // RECHERCHE PAR PANIER / VARIANTE
    // ===============================================

    /**
     * Récupère tous les items d'un panier.
     * 
     * @param Cart $cart Panier concerné
     * @return CartItem[]
     */
    public function findByCart(Cart $cart): array
    {
        return $this->createQueryBuilder('ci')
            ->where('ci.cart = :cart')
            ->setParameter('cart', $cart)
            ->orderBy('ci.createdAt', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Trouve un item spécifique par panier + variante.
     * 
     * Utilité : Vérifier si variante déjà dans panier avant ajout.
     * 
     * @param Cart $cart Panier concerné
     * @param ProductVariant $variant Variante recherchée
     * @return CartItem|null
     */
    public function findByCartAndVariant(Cart $cart, ProductVariant $variant): ?CartItem
    {
        return $this->createQueryBuilder('ci')
            ->where('ci.cart = :cart')
            ->andWhere('ci.variant = :variant')
            ->setParameter('cart', $cart)
            ->setParameter('variant', $variant)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Compte le nombre total d'items dans un panier.
     * 
     * @param Cart $cart Panier concerné
     * @return int
     */
    public function countByCart(Cart $cart): int
    {
        return (int) $this->createQueryBuilder('ci')
            ->select('COUNT(ci.id)')
            ->where('ci.cart = :cart')
            ->setParameter('cart', $cart)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Calcule la quantité totale dans un panier (somme quantities).
     * 
     * @param Cart $cart Panier concerné
     * @return int
     */
    public function getTotalQuantity(Cart $cart): int
    {
        $result = $this->createQueryBuilder('ci')
            ->select('SUM(ci.quantity)')
            ->where('ci.cart = :cart')
            ->setParameter('cart', $cart)
            ->getQuery()
            ->getSingleScalarResult();

        return (int) ($result ?? 0);
    }

    // ===============================================
    // STATISTIQUES PRODUITS POPULAIRES
    // ===============================================

    /**
     * Récupère les variantes les plus ajoutées aux paniers.
     * 
     * Utilité : Homepage "Populaires", analytics.
     * 
     * @param int $limit Nombre de résultats
     * @param int $daysAgo Depuis X jours (0 = tous)
     * @return array Tableau [variant, count]
     */
    public function findMostAddedVariants(int $limit = 10, int $daysAgo = 0): array
    {
        $qb = $this->createQueryBuilder('ci')
            ->select('IDENTITY(ci.variant) as variant_id', 'COUNT(ci.id) as add_count')
            ->where('ci.variant IS NOT NULL')
            ->groupBy('ci.variant')
            ->orderBy('add_count', 'DESC')
            ->setMaxResults($limit);

        if ($daysAgo > 0) {
            $threshold = (new \DateTimeImmutable())->modify("-{$daysAgo} days");
            $qb->andWhere('ci.createdAt >= :threshold')
                ->setParameter('threshold', $threshold);
        }

        return $qb->getQuery()->getArrayResult();
    }

    /**
     * Récupère les produits avec le plus grand total quantité.
     * 
     * @param int $limit Nombre de résultats
     * @return array Tableau [variant, total_quantity]
     */
    public function findHighestQuantityVariants(int $limit = 10): array
    {
        return $this->createQueryBuilder('ci')
            ->select('IDENTITY(ci.variant) as variant_id', 'SUM(ci.quantity) as total_quantity')
            ->where('ci.variant IS NOT NULL')
            ->groupBy('ci.variant')
            ->orderBy('total_quantity', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getArrayResult();
    }

    // ===============================================
    // DÉTECTION PROBLÈMES
    // ===============================================

    /**
     * Trouve les items avec prix changé (>5%).
     * 
     * Utilité : Alerter clients avant checkout.
     * 
     * Note : Nécessite vérification manuelle via getters CartItem,
     * ici on retourne juste tous les items pour vérification.
     * 
     * @param Cart $cart Panier concerné
     * @return CartItem[]
     */
    public function findItemsWithPriceChange(Cart $cart): array
    {
        $items = $this->findByCart($cart);

        return array_filter($items, fn(CartItem $item) => $item->hasPriceChanged());
    }

    /**
     * Trouve les items avec stock insuffisant.
     * 
     * Utilité : Validation avant checkout.
     * 
     * @param Cart $cart Panier concerné
     * @return CartItem[]
     */
    public function findItemsWithInsufficientStock(Cart $cart): array
    {
        $items = $this->findByCart($cart);

        return array_filter($items, fn(CartItem $item) => !$item->hasStockAvailable());
    }

    /**
     * Trouve les items avec variante supprimée/désactivée.
     * 
     * @param Cart $cart Panier concerné
     * @return CartItem[]
     */
    public function findItemsWithUnavailableVariant(Cart $cart): array
    {
        $items = $this->findByCart($cart);

        return array_filter($items, fn(CartItem $item) => !$item->isVariantAvailable());
    }

    /**
     * Valide tous les items d'un panier.
     * 
     * Retourne un rapport de validation.
     * 
     * @param Cart $cart Panier concerné
     * @return array Rapport avec erreurs
     */
    public function validateCartItems(Cart $cart): array
    {
        $report = [
            'valid' => true,
            'errors' => [],
            'warnings' => []
        ];

        $priceChanges = $this->findItemsWithPriceChange($cart);
        $stockIssues = $this->findItemsWithInsufficientStock($cart);
        $unavailable = $this->findItemsWithUnavailableVariant($cart);

        if (!empty($priceChanges)) {
            $report['warnings'][] = [
                'type' => 'price_changed',
                'items' => array_map(fn($i) => $i->getId(), $priceChanges)
            ];
        }

        if (!empty($stockIssues)) {
            $report['valid'] = false;
            $report['errors'][] = [
                'type' => 'insufficient_stock',
                'items' => array_map(fn($i) => $i->getId(), $stockIssues)
            ];
        }

        if (!empty($unavailable)) {
            $report['valid'] = false;
            $report['errors'][] = [
                'type' => 'variant_unavailable',
                'items' => array_map(fn($i) => $i->getId(), $unavailable)
            ];
        }

        return $report;
    }

    // ===============================================
    // NETTOYAGE
    // ===============================================

    /**
     * Supprime tous les items d'un panier.
     * 
     * Utilité : Vider le panier rapidement.
     * 
     * @param Cart $cart Panier à vider
     * @return int Nombre d'items supprimés
     */
    public function deleteByCart(Cart $cart): int
    {
        return $this->createQueryBuilder('ci')
            ->delete()
            ->where('ci.cart = :cart')
            ->setParameter('cart', $cart)
            ->getQuery()
            ->execute();
    }

    /**
     * Supprime les items orphelins (cart_id null ou cart supprimé).
     * 
     * Ne devrait jamais arriver grâce à orphanRemoval=true,
     * mais utile pour nettoyage de base corrompue.
     * 
     * @return int Nombre d'items supprimés
     */
    public function deleteOrphans(): int
    {
        return $this->createQueryBuilder('ci')
            ->delete()
            ->where('ci.cart IS NULL')
            ->getQuery()
            ->execute();
    }

    // ===============================================
    // FILTRES SPÉCIFIQUES
    // ===============================================

    private function applyCartFilter($qb, array $filters): void
    {
        if (empty($filters['cart_id'])) {
            return;
        }

        $qb->andWhere('ci.cart = :cartId')
            ->setParameter('cartId', $filters['cart_id']);
    }

    private function applyVariantFilter($qb, array $filters): void
    {
        if (empty($filters['variant_id'])) {
            return;
        }

        $qb->andWhere('ci.variant = :variantId')
            ->setParameter('variantId', $filters['variant_id']);
    }
}
