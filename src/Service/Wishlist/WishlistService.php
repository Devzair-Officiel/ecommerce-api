<?php

declare(strict_types=1);

namespace App\Service\Wishlist;

use App\Entity\Site\Site;
use App\Entity\User\User;
use App\Entity\Wishlist\Wishlist;
use App\Service\Cart\CartService;
use App\Entity\Wishlist\WishlistItem;
use App\Service\Core\AbstractService;
use App\Service\Core\RelationProcessor;
use App\Exception\BusinessRuleException;
use Doctrine\ORM\EntityManagerInterface;
use App\Repository\Wishlist\WishlistRepository;
use App\Repository\Wishlist\WishlistItemRepository;
use App\Repository\Product\ProductVariantRepository;
use Symfony\Component\Serializer\SerializerInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * Service métier pour la gestion des wishlists.
 * 
 * Responsabilités :
 * - CRUD wishlists et items
 * - Conversion wishlist → panier
 * - Partage public
 * - Gestion wishlist par défaut
 */
class WishlistService extends AbstractService
{
    public function __construct(
        EntityManagerInterface $em,
        SerializerInterface $serializer,
        ValidatorInterface $validator,
        RelationProcessor $relationProcessor,
        private readonly WishlistRepository $wishlistRepository,
        private readonly WishlistItemRepository $wishlistItemRepository,
        private readonly ProductVariantRepository $variantRepository,
        private readonly CartService $cartService
    ) {
        parent::__construct($em, $serializer, $validator, $relationProcessor);
    }

    protected function getEntityClass(): string
    {
        return Wishlist::class;
    }

    protected function getRepository(): WishlistRepository
    {
        return $this->wishlistRepository;
    }

    // ===============================================
    // CRÉATION & RÉCUPÉRATION WISHLIST
    // ===============================================

    /**
     * Récupère ou crée la wishlist par défaut d'un utilisateur.
     * 
     * Chaque utilisateur a UNE wishlist par défaut.
     * Créée automatiquement si elle n'existe pas.
     * 
     * @param User $user Utilisateur
     * @param Site $site Site concerné
     * @return Wishlist
     */
    public function getOrCreateDefaultWishlist(User $user, Site $site): Wishlist
    {
        $wishlist = $this->wishlistRepository->findDefaultByUser($user, $site);

        if ($wishlist) {
            return $wishlist;
        }

        // Créer wishlist par défaut
        $wishlist = new Wishlist();
        $wishlist->setName('Ma wishlist');
        $wishlist->setUser($user);
        $wishlist->setSite($site);
        $wishlist->setIsDefault(true);

        $this->validateEntity($wishlist);
        $this->em->persist($wishlist);
        $this->em->flush();

        return $wishlist;
    }

    /**
     * Crée une wishlist personnalisée.
     * 
     * @param User $user Utilisateur
     * @param Site $site Site concerné
     * @param string $name Nom de la wishlist
     * @param string|null $description Description
     * @return Wishlist
     */
    public function createWishlist(User $user, Site $site, string $name, ?string $description = null): Wishlist
    {
        $wishlist = new Wishlist();
        $wishlist->setName($name);
        $wishlist->setDescription($description);
        $wishlist->setUser($user);
        $wishlist->setSite($site);
        $wishlist->setIsDefault(false);

        $this->validateEntity($wishlist);
        $this->em->persist($wishlist);
        $this->em->flush();

        return $wishlist;
    }

    /**
     * Récupère toutes les wishlists d'un utilisateur.
     * 
     * @param User $user Utilisateur
     * @param Site $site Site concerné
     * @return Wishlist[]
     */
    public function getUserWishlists(User $user, Site $site): array
    {
        return $this->wishlistRepository->findByUser($user, $site);
    }

    /**
     * Récupère une wishlist par ID.
     * 
     * @param int $id ID de la wishlist
     * @param User $user Propriétaire (sécurité)
     * @return Wishlist
     * @throws BusinessRuleException Si non trouvée ou n'appartient pas à l'user
     */
    public function getWishlist(int $id, User $user): Wishlist
    {
        $wishlist = $this->wishlistRepository->find($id);

        if (!$wishlist) {
            throw new BusinessRuleException('wishlist_not_found', 'Wishlist non trouvée.');
        }

        if ($wishlist->getUser()->getId() !== $user->getId()) {
            throw new BusinessRuleException('access_denied', 'Vous ne pouvez pas accéder à cette wishlist.');
        }

        return $wishlist;
    }

    // ===============================================
    // GESTION ITEMS
    // ===============================================

    /**
     * Ajoute un produit à une wishlist.
     * 
     * Si déjà présent, ne fait rien (ou incrémente quantité si souhaité).
     * 
     * @param Wishlist $wishlist Wishlist cible
     * @param int $variantId ID de la ProductVariant
     * @param int $priority Priorité (1-3)
     * @param string|null $note Note personnelle
     * @param int $quantity Quantité souhaitée
     * @return WishlistItem
     * @throws BusinessRuleException Si variante invalide
     */
    public function addItem(
        Wishlist $wishlist,
        int $variantId,
        int $priority = WishlistItem::PRIORITY_MEDIUM,
        ?string $note = null,
        int $quantity = 1
    ): WishlistItem {
        // Récupérer variante
        $variant = $this->variantRepository->find($variantId);

        if (!$variant) {
            throw new BusinessRuleException('variant_not_found', 'Produit non trouvé.');
        }

        if (!$variant->isActive() || $variant->isDeleted()) {
            throw new BusinessRuleException(
                'variant_unavailable',
                'Ce produit n\'est plus disponible.'
            );
        }

        // Vérifier si déjà présent
        $existingItem = $wishlist->findItemByVariant($variantId);

        if ($existingItem) {
            // Item existe → incrémenter quantité
            $existingItem->setQuantity($existingItem->getQuantity() + $quantity);
            $this->em->flush();
            return $existingItem;
        }

        // Créer nouvel item
        $item = new WishlistItem();
        $item->setWishlist($wishlist);
        $item->setVariant($variant);
        $item->setProduct($variant->getProduct());
        $item->setPriority($priority);
        $item->setNote($note);
        $item->setQuantity($quantity);

        $this->validateEntity($item);
        $this->em->persist($item);
        $wishlist->addItem($item);
        $this->em->flush();

        return $item;
    }

    /**
     * Supprime un item de la wishlist.
     * 
     * @param int $itemId ID de WishlistItem
     * @param User $user Propriétaire (sécurité)
     * @throws BusinessRuleException Si non trouvé ou accès refusé
     */
    public function removeItem(int $itemId, User $user): void
    {
        $item = $this->wishlistItemRepository->find($itemId);

        if (!$item) {
            throw new BusinessRuleException('item_not_found', 'Produit non trouvé dans la wishlist.');
        }

        $wishlist = $item->getWishlist();

        if ($wishlist->getUser()->getId() !== $user->getId()) {
            throw new BusinessRuleException('access_denied', 'Vous ne pouvez pas modifier cette wishlist.');
        }

        $wishlist->removeItem($item);
        $this->em->remove($item);
        $this->em->flush();
    }

    /**
     * Met à jour un item (priorité, note, quantité).
     * 
     * @param int $itemId ID de WishlistItem
     * @param User $user Propriétaire
     * @param array $data Données à mettre à jour
     * @return WishlistItem
     */
    public function updateItem(int $itemId, User $user, array $data): WishlistItem
    {
        $item = $this->wishlistItemRepository->find($itemId);

        if (!$item) {
            throw new BusinessRuleException('item_not_found', 'Produit non trouvé.');
        }

        $wishlist = $item->getWishlist();

        if ($wishlist->getUser()->getId() !== $user->getId()) {
            throw new BusinessRuleException('access_denied', 'Vous ne pouvez pas modifier cette wishlist.');
        }

        if (isset($data['priority'])) {
            $item->setPriority($data['priority']);
        }

        if (isset($data['note'])) {
            $item->setNote($data['note']);
        }

        if (isset($data['quantity'])) {
            $item->setQuantity($data['quantity']);
        }

        $this->validateEntity($item);
        $this->em->flush();

        return $item;
    }

    /**
     * Vide une wishlist.
     * 
     * @param Wishlist $wishlist Wishlist à vider
     */
    public function clearWishlist(Wishlist $wishlist): void
    {
        $this->wishlistItemRepository->deleteByWishlist($wishlist);
        $wishlist->clear();
        $this->em->flush();
    }

    // ===============================================
    // CONVERSION EN PANIER
    // ===============================================

    /**
     * Convertit une wishlist en panier.
     * 
     * Ajoute tous les items disponibles au panier de l'utilisateur.
     * Items indisponibles ou en rupture sont ignorés.
     * 
     * @param Wishlist $wishlist Wishlist source
     * @return array Rapport de conversion
     */
    public function convertToCart(Wishlist $wishlist): array
    {
        $user = $wishlist->getUser();
        $site = $wishlist->getSite();

        // Récupérer ou créer panier
        $result = $this->cartService->getOrCreateCart(
            $site,
            'EUR',
            'fr',
            'B2C',
            $user
        );

        $cart = $result['cart'];

        $report = [
            'total_items' => 0,
            'added' => 0,
            'skipped' => 0,
            'errors' => []
        ];

        foreach ($wishlist->getItems() as $item) {
            $report['total_items']++;

            if (!$item->isAvailable()) {
                $report['skipped']++;
                $report['errors'][] = [
                    'item_id' => $item->getId(),
                    'name' => $item->getDisplayName(),
                    'reason' => 'Produit indisponible'
                ];
                continue;
            }

            try {
                $this->cartService->addItem(
                    $cart,
                    $item->getVariant()->getId(),
                    $item->getQuantity()
                );

                $report['added']++;
            } catch (\Exception $e) {
                $report['skipped']++;
                $report['errors'][] = [
                    'item_id' => $item->getId(),
                    'name' => $item->getDisplayName(),
                    'reason' => $e->getMessage()
                ];
            }
        }

        return [
            'cart' => $cart,
            'report' => $report
        ];
    }

    // ===============================================
    // PARTAGE PUBLIC
    // ===============================================

    /**
     * Active le partage public d'une wishlist.
     * 
     * Génère un token de partage unique.
     * 
     * @param Wishlist $wishlist Wishlist à partager
     * @return string URL de partage
     */
    public function enablePublicSharing(Wishlist $wishlist): string
    {
        if (!$wishlist->isPublic()) {
            $wishlist->setIsPublic(true);
            $this->em->flush();
        }

        return $wishlist->getShareToken();
    }

    /**
     * Désactive le partage public.
     * 
     * @param Wishlist $wishlist Wishlist concernée
     */
    public function disablePublicSharing(Wishlist $wishlist): void
    {
        $wishlist->setIsPublic(false);
        $this->em->flush();
    }

    /**
     * Récupère une wishlist partagée par token.
     * 
     * @param string $shareToken Token de partage
     * @return Wishlist
     * @throws BusinessRuleException Si non trouvée
     */
    public function getSharedWishlist(string $shareToken): Wishlist
    {
        $wishlist = $this->wishlistRepository->findByShareToken($shareToken);

        if (!$wishlist) {
            throw new BusinessRuleException('wishlist_not_found', 'Wishlist non trouvée.');
        }

        return $wishlist;
    }

    // ===============================================
    // SUPPRESSION
    // ===============================================

    /**
     * Supprime une wishlist.
     * 
     * Ne peut pas supprimer la wishlist par défaut.
     * 
     * @param Wishlist $wishlist Wishlist à supprimer
     * @param User $user Propriétaire
     * @throws BusinessRuleException Si c'est la wishlist par défaut
     */
    public function deleteWishlist(Wishlist $wishlist, User $user): void
    {
        if ($wishlist->getUser()->getId() !== $user->getId()) {
            throw new BusinessRuleException('access_denied', 'Vous ne pouvez pas supprimer cette wishlist.');
        }

        if ($wishlist->isDefault()) {
            throw new BusinessRuleException(
                'cannot_delete_default',
                'Impossible de supprimer la wishlist par défaut.'
            );
        }

        $this->em->remove($wishlist);
        $this->em->flush();
    }
}
