<?php

declare(strict_types=1);

namespace App\Service\Cart;

use App\Entity\Cart\Cart;
use App\Entity\Site\Site;
use App\Entity\User\User;
use App\Entity\Cart\CartItem;
use Symfony\Component\Uid\Uuid;
use App\Service\Core\AbstractService;
use App\Entity\Product\ProductVariant;
use App\Repository\Cart\CartRepository;
use App\Service\Core\RelationProcessor;
use App\Exception\BusinessRuleException;
use Doctrine\ORM\EntityManagerInterface;
use App\Repository\Cart\CartItemRepository;
use App\Repository\Product\ProductVariantRepository;
use Symfony\Component\Serializer\SerializerInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * Service métier pour la gestion des paniers.
 * 
 * Responsabilités :
 * - CRUD paniers (création, récupération, mise à jour)
 * - Gestion items (ajout, suppression, update quantité)
 * - Validation stock et prix
 * - Fusion paniers invités → utilisateurs
 * - Génération tokens JWT pour invités
 * - Calcul totaux et vérifications avant checkout
 * 
 * Workflow invité :
 * 1. Génère sessionToken (UUID)
 * 2. Crée panier avec ce token
 * 3. Retourne token à stocker dans JWT côté API
 * 4. Client Nuxt stocke JWT en localStorage
 * 5. Chaque requête inclut JWT avec cart_token
 * 
 * Workflow connexion :
 * 1. Utilisateur se connecte avec panier invité actif
 * 2. Fusion avec panier existant ou conversion
 * 3. sessionToken devient null
 */
class CartService extends AbstractService
{
    public function __construct(
        EntityManagerInterface $em,
        SerializerInterface $serializer,
        ValidatorInterface $validator,
        RelationProcessor $relationProcessor,
        private readonly CartRepository $cartRepository,
        private readonly CartItemRepository $cartItemRepository,
        private readonly ProductVariantRepository $variantRepository
    ) {
        parent::__construct($em, $serializer, $validator, $relationProcessor);
    }

    protected function getEntityClass(): string
    {
        return Cart::class;
    }

    protected function getRepository(): CartRepository
    {
        return $this->cartRepository;
    }

    // ===============================================
    // CRÉATION & RÉCUPÉRATION PANIER
    // ===============================================

    /**
     * Récupère ou crée un panier.
     * 
     * Logique :
     * - Si user → cherche panier existant ou crée nouveau
     * - Si sessionToken → cherche panier invité ou crée nouveau
     * - Si rien → crée panier invité avec nouveau token
     * 
     * @param Site $site Site concerné
     * @param string $currency Devise (EUR, USD...)
     * @param string $locale Langue (fr, en, es)
     * @param string $customerType Type client (B2C, B2B)
     * @param User|null $user Utilisateur connecté (null si invité)
     * @param string|null $sessionToken Token invité existant
     * @return array [cart: Cart, token: string|null, is_new: bool]
     */
    public function getOrCreateCart(
        Site $site,
        string $currency,
        string $locale,
        string $customerType = 'B2C',
        ?User $user = null,
        ?string $sessionToken = null
    ): array {
        // Chercher panier existant
        $cart = $this->cartRepository->findOrNull($site, $user, $sessionToken);

        if ($cart !== null) {
            return [
                'cart' => $cart,
                'token' => $cart->getSessionToken(),
                'is_new' => false
            ];
        }

        // Créer nouveau panier
        $cart = new Cart();
        $cart->setSite($site);
        $cart->setCurrency($currency);
        $cart->setLocale($locale);
        $cart->setCustomerType($customerType);

        if ($user !== null) {
            $cart->setUser($user);
        } else {
            // Générer token pour invité
            $newToken = Uuid::v4()->toRfc4122();
            $cart->setSessionToken($newToken);
        }

        $this->validateEntity($cart);
        $this->em->persist($cart);
        $this->em->flush();

        return [
            'cart' => $cart,
            'token' => $cart->getSessionToken(),
            'is_new' => true
        ];
    }

    /**
     * Récupère un panier existant (user ou invité).
     * 
     * @param Site $site Site concerné
     * @param User|null $user Utilisateur connecté
     * @param string|null $sessionToken Token invité
     * @return Cart|null
     */
    public function getCart(Site $site, ?User $user = null, ?string $sessionToken = null): ?Cart
    {
        return $this->cartRepository->findOrNull($site, $user, $sessionToken);
    }

    /**
     * Récupère un panier avec validation d'expiration.
     * 
     * Lance exception si panier expiré.
     * 
     * @throws BusinessRuleException Si panier expiré
     */
    public function getValidCart(Site $site, ?User $user = null, ?string $sessionToken = null): ?Cart
    {
        $cart = $this->getCart($site, $user, $sessionToken);

        if ($cart === null) {
            return null;
        }

        if ($cart->isExpired()) {
            throw new BusinessRuleException(
                'cart_expired',
                'Votre panier a expiré. Veuillez créer un nouveau panier.'
            );
        }

        return $cart;
    }

    // ===============================================
    // GESTION ITEMS - AJOUT
    // ===============================================

    /**
     * Ajoute un produit au panier (ou incrémente quantité si déjà présent).
     * 
     * Workflow complet :
     * 1. Valider variante existe et active
     * 2. Valider stock disponible
     * 3. Récupérer prix actuel
     * 4. Chercher si variante déjà dans panier
     * 5. Si oui : incrémenter quantité
     * 6. Si non : créer nouvel item
     * 7. Créer snapshot produit
     * 8. Flush et retourner
     * 
     * @param Cart $cart Panier cible
     * @param int $variantId ID de la ProductVariant
     * @param int $quantity Quantité à ajouter
     * @param string|null $customMessage Message personnalisé
     * @return CartItem Item ajouté ou mis à jour
     * @throws BusinessRuleException Si erreur validation
     */
    public function addItem(
        Cart $cart,
        int $variantId,
        int $quantity = 1,
        ?string $customMessage = null
    ): CartItem {
        // 1. Récupérer et valider variante
        $variant = $this->variantRepository->find($variantId);

        if (!$variant) {
            throw new BusinessRuleException(
                'variant_not_found',
                'Produit non trouvé.'
            );
        }

        if (!$variant->isActive() || $variant->isDeleted()) {
            throw new BusinessRuleException(
                'variant_unavailable',
                sprintf('Le produit "%s" n\'est plus disponible.', $variant->getName())
            );
        }

        // 2. Vérifier stock disponible
        if (!$variant->hasQuantityAvailable($quantity)) {
            throw new BusinessRuleException(
                'insufficient_stock',
                sprintf(
                    'Stock insuffisant pour "%s". Disponible : %d, demandé : %d.',
                    $variant->getName(),
                    $variant->getAvailableStock(),
                    $quantity
                )
            );
        }

        // 3. Récupérer prix actuel
        $price = $variant->getPriceFor(
            $cart->getCurrency(),
            $cart->getCustomerType(),
            $quantity
        );

        if ($price === null) {
            throw new BusinessRuleException(
                'price_not_available',
                sprintf(
                    'Prix non disponible pour "%s" en %s %s.',
                    $variant->getName(),
                    $cart->getCurrency(),
                    $cart->getCustomerType()
                )
            );
        }

        // 4. Chercher item existant
        $existingItem = $cart->findItemByVariant($variantId);

        if ($existingItem) {
            // Item existe → incrémenter quantité
            $newQuantity = $existingItem->getQuantity() + $quantity;

            // Revalider stock avec nouvelle quantité
            if (!$variant->hasQuantityAvailable($newQuantity)) {
                throw new BusinessRuleException(
                    'insufficient_stock',
                    sprintf(
                        'Stock insuffisant pour cette quantité. Disponible : %d, demandé : %d.',
                        $variant->getAvailableStock(),
                        $newQuantity
                    )
                );
            }

            $existingItem->setQuantity($newQuantity);

            // Recalculer prix avec nouvelle quantité (tarifs dégressifs)
            $newPrice = $variant->getPriceFor(
                $cart->getCurrency(),
                $cart->getCustomerType(),
                $newQuantity
            );
            $existingItem->setPriceAtAdd($newPrice);

            // Recalculer économies
            $savings = $variant->getSavingsForQuantity(
                $cart->getCurrency(),
                $cart->getCustomerType(),
                $newQuantity
            );
            $existingItem->setSavingsAtAdd($savings);

            $this->em->flush();

            return $existingItem;
        }

        // 5. Créer nouvel item
        $item = new CartItem();
        $item->setCart($cart);
        $item->setVariant($variant);
        $item->setProduct($variant->getProduct());
        $item->setQuantity($quantity);
        $item->setPriceAtAdd($price);

        // Calculer économies si tarif dégressif
        $savings = $variant->getSavingsForQuantity(
            $cart->getCurrency(),
            $cart->getCustomerType(),
            $quantity
        );
        $item->setSavingsAtAdd($savings);

        // Message personnalisé
        if ($customMessage) {
            $item->setCustomMessage($customMessage);
        }

        // 6. Créer snapshot
        $item->createSnapshot();

        // 7. Valider et persister
        $this->validateEntity($item);
        $this->em->persist($item);
        $cart->addItem($item);
        $this->em->flush();

        return $item;
    }

    // ===============================================
    // GESTION ITEMS - MODIFICATION
    // ===============================================

    /**
     * Met à jour la quantité d'un item.
     * 
     * @param int $itemId ID de CartItem
     * @param int $newQuantity Nouvelle quantité
     * @return CartItem Item mis à jour
     * @throws BusinessRuleException Si erreur validation
     */
    public function updateItemQuantity(int $itemId, int $newQuantity): CartItem
    {
        $item = $this->cartItemRepository->find($itemId);

        if (!$item) {
            throw new BusinessRuleException('item_not_found', 'Article non trouvé.');
        }

        if ($newQuantity < 1) {
            throw new BusinessRuleException(
                'invalid_quantity',
                'La quantité doit être supérieure à 0.'
            );
        }

        $variant = $item->getVariant();

        if (!$variant) {
            throw new BusinessRuleException(
                'variant_unavailable',
                'Ce produit n\'est plus disponible.'
            );
        }

        // Valider stock
        if (!$variant->hasQuantityAvailable($newQuantity)) {
            throw new BusinessRuleException(
                'insufficient_stock',
                sprintf(
                    'Stock insuffisant. Disponible : %d.',
                    $variant->getAvailableStock()
                )
            );
        }

        $item->setQuantity($newQuantity);

        // Recalculer prix (tarifs dégressifs)
        $cart = $item->getCart();
        $newPrice = $variant->getPriceFor(
            $cart->getCurrency(),
            $cart->getCustomerType(),
            $newQuantity
        );
        $item->setPriceAtAdd($newPrice);

        // Recalculer économies
        $savings = $variant->getSavingsForQuantity(
            $cart->getCurrency(),
            $cart->getCustomerType(),
            $newQuantity
        );
        $item->setSavingsAtAdd($savings);

        $this->em->flush();

        return $item;
    }

    /**
     * Supprime un item du panier.
     * 
     * @param int $itemId ID de CartItem
     * @throws BusinessRuleException Si item non trouvé
     */
    public function removeItem(int $itemId): void
    {
        $item = $this->cartItemRepository->find($itemId);

        if (!$item) {
            throw new BusinessRuleException('item_not_found', 'Article non trouvé.');
        }

        $cart = $item->getCart();
        $cart->removeItem($item);
        $this->em->remove($item);
        $this->em->flush();
    }

    /**
     * Vide complètement le panier.
     * 
     * @param Cart $cart Panier à vider
     */
    public function clearCart(Cart $cart): void
    {
        $this->cartItemRepository->deleteByCart($cart);
        $cart->clear();
        $this->em->flush();
    }

    // ===============================================
    // VALIDATION & VÉRIFICATIONS
    // ===============================================

    /**
     * Valide tous les items du panier avant checkout.
     * 
     * Vérifications :
     * - Variantes toujours disponibles
     * - Stock suffisant
     * - Prix pas changé de > 5%
     * 
     * @param Cart $cart Panier à valider
     * @return array Rapport de validation
     */
    public function validateCart(Cart $cart): array
    {
        return $this->cartItemRepository->validateCartItems($cart);
    }

    /**
     * Détecte et retourne les items avec problèmes.
     * 
     * @param Cart $cart Panier à analyser
     * @return array Tableau d'items avec problèmes
     */
    public function getProblematicItems(Cart $cart): array
    {
        $problems = [];

        foreach ($cart->getItems() as $item) {
            $itemProblems = [];

            if (!$item->isVariantAvailable()) {
                $itemProblems[] = 'variant_unavailable';
            }

            if (!$item->hasStockAvailable()) {
                $itemProblems[] = 'insufficient_stock';
            }

            if ($item->hasPriceChanged()) {
                $itemProblems[] = 'price_changed';
            }

            if (!empty($itemProblems)) {
                $problems[] = [
                    'item' => $item,
                    'issues' => $itemProblems
                ];
            }
        }

        return $problems;
    }

    /**
     * Synchronise les prix du panier avec les prix actuels.
     * 
     * Utile avant checkout pour recalculer avec tarifs actuels.
     * 
     * @param Cart $cart Panier à synchroniser
     * @return array Rapport des changements
     */
    public function syncPrices(Cart $cart): array
    {
        $changes = [];

        foreach ($cart->getItems() as $item) {
            $variant = $item->getVariant();

            if (!$variant) {
                continue;
            }

            $currentPrice = $variant->getPriceFor(
                $cart->getCurrency(),
                $cart->getCustomerType(),
                $item->getQuantity()
            );

            if ($currentPrice === null) {
                continue;
            }

            $oldPrice = $item->getPriceAtAdd();

            if (abs($currentPrice - $oldPrice) > 0.01) { // Différence significative
                $item->setPriceAtAdd($currentPrice);
                $changes[] = [
                    'item_id' => $item->getId(),
                    'old_price' => $oldPrice,
                    'new_price' => $currentPrice,
                    'difference' => $currentPrice - $oldPrice
                ];
            }
        }

        if (!empty($changes)) {
            $this->em->flush();
        }

        return $changes;
    }

    // ===============================================
    // FUSION PANIERS (INVITÉ → UTILISATEUR)
    // ===============================================

    /**
     * Fusionne un panier invité dans le panier d'un utilisateur.
     * 
     * Appelé lors de l'inscription ou connexion.
     * 
     * Workflow :
     * 1. Utilisateur a panier invité (sessionToken)
     * 2. Utilisateur se connecte/s'inscrit
     * 3. On cherche panier existant de l'utilisateur
     * 4. Si existe : fusionner items
     * 5. Si pas existe : convertir panier invité
     * 6. Retourner panier final
     * 
     * @param string $sessionToken Token du panier invité
     * @param User $user Utilisateur cible
     * @param Site $site Site concerné
     * @return Cart Panier final
     * @throws BusinessRuleException Si panier invité non trouvé
     */
    public function mergeGuestCartToUser(string $sessionToken, User $user, Site $site): Cart
    {
        $guestCart = $this->cartRepository->findBySessionToken($sessionToken, $site);

        if (!$guestCart) {
            throw new BusinessRuleException(
                'guest_cart_not_found',
                'Panier invité introuvable ou expiré.'
            );
        }

        return $this->cartRepository->mergeGuestCartIntoUser($guestCart, $user);
    }

    // ===============================================
    // STATISTIQUES & ANALYTICS
    // ===============================================

    /**
     * Récupère les statistiques du panier.
     * 
     * @param Cart $cart Panier concerné
     * @return array Statistiques complètes
     */
    public function getCartStatistics(Cart $cart): array
    {
        $validation = $this->validateCart($cart);
        $problems = $this->getProblematicItems($cart);

        return [
            'summary' => $cart->getSummary(),
            'validation' => $validation,
            'problems_count' => count($problems),
            'problems' => $problems,
            'is_valid_for_checkout' => $validation['valid'] && empty($problems),
        ];
    }

    // ===============================================
    // NETTOYAGE AUTOMATIQUE
    // ===============================================

    /**
     * Supprime les paniers expirés.
     * 
     * À exécuter via CRON (ex: toutes les heures).
     * 
     * @return int Nombre de paniers supprimés
     */
    public function cleanupExpiredCarts(): int
    {
        return $this->cartRepository->deleteExpired();
    }

    /**
     * Supprime les paniers vides expirés depuis X jours.
     * 
     * @param int $days Nombre de jours
     * @return int Nombre de paniers supprimés
     */
    public function cleanupEmptyExpiredCarts(int $days = 1): int
    {
        return $this->cartRepository->deleteEmptyExpiredOlderThan($days);
    }
}
