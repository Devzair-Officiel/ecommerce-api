<?php

declare(strict_types=1);

namespace App\Entity\Cart;

use App\Entity\Product\Product;
use App\Entity\Product\ProductVariant;
use App\Repository\Cart\CartItemRepository;
use App\Traits\DateTrait;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Annotation\Groups;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Ligne de panier (produit + variante + quantité).
 * 
 * Responsabilités :
 * - Lier une variante à un panier avec quantité
 * - Snapshot du prix au moment de l'ajout (évite surprises)
 * - Calcul du total ligne (prix × quantité)
 * - Détection économies (tarifs dégressifs)
 * - Données figées pour traçabilité
 * 
 * Pourquoi snapshot du prix ?
 * - Le prix peut changer pendant que le client navigue
 * - On garde le prix initial dans le panier
 * - À la commande, on recalcule avec prix actuel
 * - Si différence > 5%, on alerte le client
 * 
 * Relations :
 * - ManyToOne avec Cart (plusieurs items dans un panier)
 * - ManyToOne avec ProductVariant (quel format exactement)
 * - ManyToOne avec Product (référence parent, optionnel)
 */
#[ORM\Entity(repositoryClass: CartItemRepository::class)]
#[ORM\Index(columns: ['cart_id'], name: 'idx_cart_item_cart')]
#[ORM\Index(columns: ['variant_id'], name: 'idx_cart_item_variant')]
#[ORM\HasLifecycleCallbacks]
class CartItem
{
    use DateTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::INTEGER)]
    #[Groups(['cart:read', 'cart:items'])]
    private ?int $id = null;

    /**
     * Panier parent.
     * 
     * Cascade DELETE : Si le panier est supprimé, ses items aussi.
     */
    #[ORM\ManyToOne(targetEntity: Cart::class, inversedBy: 'items')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    #[Assert\NotNull(message: 'Le panier est requis.')]
    private ?Cart $cart = null;

    /**
     * Variante du produit (format spécifique).
     * 
     * Important : On stocke la VARIANTE, pas juste le produit.
     * Exemple : Pot 250g (pas juste "Miel de Fleurs").
     * 
     * Peut devenir null si la variante est supprimée.
     * Dans ce cas, on garde productSnapshot pour l'historique.
     */
    #[ORM\ManyToOne(targetEntity: ProductVariant::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    #[Groups(['cart:read', 'cart:items'])]
    private ?ProductVariant $variant = null;

    /**
     * Produit parent (référence optionnelle).
     * 
     * Utilisé pour :
     * - Retrouver le produit même si variante supprimée
     * - Afficher infos produit (description, images)
     * - Analytics (produits populaires)
     */
    #[ORM\ManyToOne(targetEntity: Product::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    #[Groups(['cart:read', 'cart:items'])]
    private ?Product $product = null;

    /**
     * Quantité commandée.
     * 
     * Min : 1
     * Max : Dépend du stock disponible (validé par CartService)
     */
    #[ORM\Column(type: Types::INTEGER)]
    #[Assert\Positive(message: 'La quantité doit être positive.')]
    #[Assert\Range(min: 1, max: 999, notInRangeMessage: 'Quantité invalide (1-999).')]
    #[Groups(['cart:read', 'cart:items', 'cart:write'])]
    private int $quantity = 1;

    /**
     * Prix unitaire au moment de l'ajout (snapshot).
     * 
     * Pourquoi ?
     * - Évite surprises si prix change pendant navigation
     * - À la commande, on recalcule avec prix actuel
     * - Si différence significative (>5%), on alerte
     * 
     * Toujours dans la devise du panier parent.
     */
    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 2)]
    #[Assert\PositiveOrZero(message: 'Le prix ne peut pas être négatif.')]
    #[Groups(['cart:read', 'cart:items'])]
    private float $priceAtAdd = 0.0;

    /**
     * Snapshot complet du produit/variante (JSON).
     * 
     * Sauvegarde au moment de l'ajout :
     * - SKU, nom produit, nom variante
     * - Image principale
     * - Poids (pour frais de port)
     * 
     * Permet de garder les infos même si produit/variante supprimés.
     * 
     * Structure :
     * {
     *   "product_id": 42,
     *   "product_name": "Miel de Fleurs Bio",
     *   "product_slug": "miel-de-fleurs-bio",
     *   "variant_id": 123,
     *   "variant_sku": "MIEL-FLEURS-250G",
     *   "variant_name": "Pot 250g",
     *   "image": "/uploads/products/miel-1.jpg",
     *   "weight": 250
     * }
     */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    #[Groups(['cart:read', 'cart:items'])]
    private ?array $productSnapshot = null;

    /**
     * Économie réalisée avec tarif dégressif (snapshot).
     * 
     * Calculé au moment de l'ajout selon la quantité.
     * Exemple : Prix unitaire 12€ → 10€ par 5 → économie de 2€/unité
     * 
     * null si pas de tarif dégressif applicable.
     */
    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 2, nullable: true)]
    #[Groups(['cart:read', 'cart:items'])]
    private ?float $savingsAtAdd = null;

    /**
     * Message personnalisé pour cet item (optionnel).
     * 
     * Exemple : "C'est un cadeau, merci de l'emballer"
     */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    #[Assert\Length(max: 500, maxMessage: 'Message trop long (500 caractères max).')]
    #[Groups(['cart:read', 'cart:items', 'cart:write'])]
    private ?string $customMessage = null;

    // ===============================================
    // GETTERS & SETTERS
    // ===============================================

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getCart(): ?Cart
    {
        return $this->cart;
    }

    public function setCart(?Cart $cart): static
    {
        $this->cart = $cart;
        return $this;
    }

    public function getVariant(): ?ProductVariant
    {
        return $this->variant;
    }

    public function setVariant(?ProductVariant $variant): static
    {
        $this->variant = $variant;

        // Synchroniser le produit parent
        if ($variant) {
            $this->product = $variant->getProduct();
        }

        return $this;
    }

    public function getProduct(): ?Product
    {
        return $this->product;
    }

    public function setProduct(?Product $product): static
    {
        $this->product = $product;
        return $this;
    }

    public function getQuantity(): int
    {
        return $this->quantity;
    }

    public function setQuantity(int $quantity): static
    {
        $this->quantity = max(1, $quantity);
        return $this;
    }

    public function getPriceAtAdd(): float
    {
        return $this->priceAtAdd;
    }

    public function setPriceAtAdd(float $priceAtAdd): static
    {
        $this->priceAtAdd = $priceAtAdd;
        return $this;
    }

    public function getProductSnapshot(): ?array
    {
        return $this->productSnapshot;
    }

    public function setProductSnapshot(?array $productSnapshot): static
    {
        $this->productSnapshot = $productSnapshot;
        return $this;
    }

    public function getSavingsAtAdd(): ?float
    {
        return $this->savingsAtAdd;
    }

    public function setSavingsAtAdd(?float $savingsAtAdd): static
    {
        $this->savingsAtAdd = $savingsAtAdd;
        return $this;
    }

    public function getCustomMessage(): ?string
    {
        return $this->customMessage;
    }

    public function setCustomMessage(?string $customMessage): static
    {
        $this->customMessage = $customMessage;
        return $this;
    }

    // ===============================================
    // HELPERS MÉTIER - CALCULS
    // ===============================================

    /**
     * Calcule le montant total de cette ligne (prix × quantité).
     */
    public function getLineTotal(): float
    {
        return $this->priceAtAdd * $this->quantity;
    }

    /**
     * Récupère le prix actuel de la variante (prix du moment).
     * 
     * Permet de comparer avec priceAtAdd pour détecter changements.
     * 
     * @return float|null Prix actuel ou null si variante supprimée
     */
    public function getCurrentPrice(): ?float
    {
        if (!$this->variant) {
            return null;
        }

        return $this->variant->getPriceFor(
            $this->cart?->getCurrency() ?? 'EUR',
            $this->cart?->getCustomerType() ?? 'B2C',
            $this->quantity
        );
    }

    /**
     * Vérifie si le prix a changé depuis l'ajout au panier.
     * 
     * @param float $threshold Seuil de tolérance en % (défaut: 5%)
     * @return bool True si changement significatif
     */
    public function hasPriceChanged(float $threshold = 5.0): bool
    {
        $currentPrice = $this->getCurrentPrice();

        if ($currentPrice === null) {
            return false; // Variante supprimée, on ne peut pas comparer
        }

        $difference = abs($currentPrice - $this->priceAtAdd);
        $percentageChange = ($difference / $this->priceAtAdd) * 100;

        return $percentageChange > $threshold;
    }

    /**
     * Calcule la différence de prix (actuel - snapshot).
     * 
     * @return float|null Différence ou null si pas comparable
     */
    public function getPriceDifference(): ?float
    {
        $currentPrice = $this->getCurrentPrice();

        if ($currentPrice === null) {
            return null;
        }

        return $currentPrice - $this->priceAtAdd;
    }

    /**
     * Calcule les économies totales sur cette ligne.
     * 
     * @return float|null Économie totale (savings × quantité)
     */
    public function getSavings(): ?float
    {
        if ($this->savingsAtAdd === null) {
            return null;
        }

        return $this->savingsAtAdd * $this->quantity;
    }

    /**
     * Calcule le poids total de cette ligne (grammes).
     * 
     * @return int|null Poids total ou null si non défini
     */
    public function getTotalWeight(): ?int
    {
        $weight = $this->variant?->getEffectiveWeight();

        if ($weight === null) {
            return null;
        }

        return $weight * $this->quantity;
    }

    // ===============================================
    // HELPERS MÉTIER - SNAPSHOT
    // ===============================================

    /**
     * Crée le snapshot du produit/variante au moment de l'ajout.
     * 
     * Appelé automatiquement par CartService lors de l'ajout.
     */
    public function createSnapshot(): static
    {
        if (!$this->variant) {
            return $this;
        }

        $product = $this->variant->getProduct();

        $this->productSnapshot = [
            'product_id' => $product?->getId(),
            'product_name' => $product?->getName(),
            'product_slug' => $product?->getSlug(),
            'variant_id' => $this->variant->getId(),
            'variant_sku' => $this->variant->getSku(),
            'variant_name' => $this->variant->getName(),
            'full_name' => $this->variant->getFullName(),
            'image' => $this->variant->getFinalImage(),
            'weight' => $this->variant->getEffectiveWeight(),
        ];

        return $this;
    }

    /**
     * Récupère une valeur du snapshot.
     */
    public function getSnapshotValue(string $key, mixed $default = null): mixed
    {
        return $this->productSnapshot[$key] ?? $default;
    }

    /**
     * Récupère le nom complet pour affichage.
     * 
     * Ordre de priorité :
     * 1. Snapshot (si variante supprimée)
     * 2. Variante actuelle
     * 3. Fallback "Produit indisponible"
     */
    public function getDisplayName(): string
    {
        // Essayer variante actuelle
        if ($this->variant) {
            return $this->variant->getFullName();
        }

        // Fallback sur snapshot
        if ($this->productSnapshot) {
            return $this->getSnapshotValue('full_name', 'Produit indisponible');
        }

        return 'Produit indisponible';
    }

    /**
     * Récupère l'image pour affichage.
     */
    public function getDisplayImage(): ?string
    {
        // Essayer variante actuelle
        if ($this->variant) {
            return $this->variant->getFinalImage();
        }

        // Fallback sur snapshot
        return $this->getSnapshotValue('image');
    }

    /**
     * Récupère le SKU pour affichage.
     */
    public function getDisplaySku(): ?string
    {
        // Essayer variante actuelle
        if ($this->variant) {
            return $this->variant->getSku();
        }

        // Fallback sur snapshot
        return $this->getSnapshotValue('variant_sku');
    }

    // ===============================================
    // HELPERS MÉTIER - VALIDATION
    // ===============================================

    /**
     * Vérifie si le stock est disponible pour la quantité demandée.
     * 
     * @return bool True si stock suffisant
     */
    public function hasStockAvailable(): bool
    {
        if (!$this->variant) {
            return false; // Variante supprimée
        }

        return $this->variant->hasQuantityAvailable($this->quantity);
    }

    /**
     * Vérifie si la variante est toujours active et disponible.
     */
    public function isVariantAvailable(): bool
    {
        if (!$this->variant) {
            return false;
        }

        return $this->variant->isActive()
            && !$this->variant->isDeleted()
            && $this->variant->isInStock();
    }

    /**
     * Vérifie si l'item peut être commandé (variante OK + stock OK).
     */
    public function isOrderable(): bool
    {
        return $this->isVariantAvailable() && $this->hasStockAvailable();
    }

    /**
     * Récupère le stock disponible actuel.
     * 
     * @return int Stock disponible ou 0 si variante supprimée
     */
    public function getAvailableStock(): int
    {
        if (!$this->variant) {
            return 0;
        }

        return $this->variant->getAvailableStock();
    }

    // ===============================================
    // HELPERS MÉTIER - QUANTITÉ
    // ===============================================

    /**
     * Incrémente la quantité.
     * 
     * @param int $amount Montant à ajouter
     * @return static
     */
    public function incrementQuantity(int $amount = 1): static
    {
        $this->quantity += $amount;
        return $this;
    }

    /**
     * Décrémente la quantité (min 1).
     * 
     * @param int $amount Montant à retirer
     * @return static
     */
    public function decrementQuantity(int $amount = 1): static
    {
        $this->quantity = max(1, $this->quantity - $amount);
        return $this;
    }

    /**
     * Vérifie si on peut ajouter une quantité supplémentaire.
     * 
     * @param int $additionalQty Quantité à ajouter
     * @return bool True si stock suffisant
     */
    public function canAddQuantity(int $additionalQty): bool
    {
        if (!$this->variant) {
            return false;
        }

        $newTotal = $this->quantity + $additionalQty;
        return $this->variant->hasQuantityAvailable($newTotal);
    }

    // ===============================================
    // LIFECYCLE CALLBACKS
    // ===============================================

    /**
     * Met à jour l'activité du panier parent.
     */
    #[ORM\PostPersist]
    #[ORM\PostUpdate]
    #[ORM\PostRemove]
    public function touchCartActivity(): void
    {
        $this->cart?->touchActivity();
    }

    // ===============================================
    // FORMATAGE
    // ===============================================

    /**
     * Résumé de la ligne pour affichage.
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->getDisplayName(),
            'sku' => $this->getDisplaySku(),
            'image' => $this->getDisplayImage(),
            'quantity' => $this->quantity,
            'unit_price' => $this->priceAtAdd,
            'line_total' => $this->getLineTotal(),
            'savings' => $this->getSavings(),
            'weight' => $this->getTotalWeight(),
            'is_orderable' => $this->isOrderable(),
            'stock_available' => $this->getAvailableStock(),
            'has_price_changed' => $this->hasPriceChanged(),
        ];
    }

    public function __toString(): string
    {
        return sprintf(
            '%s × %d (%.2f €)',
            $this->getDisplayName(),
            $this->quantity,
            $this->getLineTotal()
        );
    }
}
