<?php

declare(strict_types=1);

namespace App\Entity\Order;

use App\Entity\Product\Product;
use App\Entity\Product\ProductVariant;
use App\Repository\Order\OrderItemRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Annotation\Groups;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Ligne de commande (produit + variante + quantité) figée.
 * 
 * Responsabilités :
 * - Snapshot immutable d'un CartItem
 * - Prix et données produit figés au moment de la commande
 * - Traçabilité historique (même si produit/variante supprimés)
 * - Calcul du total ligne
 * 
 * Différence avec CartItem :
 * - CartItem = temporaire, modifiable
 * - OrderItem = permanent, immutable
 * 
 * Principe d'immutabilité :
 * - Les prix, SKU, noms sont copiés depuis CartItem
 * - Si le produit change/supprimé, OrderItem reste inchangé
 * - Garantit conformité légale (facture, comptabilité)
 * 
 * Relations :
 * - ManyToOne avec Order (plusieurs lignes par commande)
 * - ManyToOne avec ProductVariant (référence, peut devenir null)
 * - ManyToOne avec Product (référence parent, peut devenir null)
 */
#[ORM\Entity(repositoryClass: OrderItemRepository::class)]
#[ORM\Index(columns: ['order_id'], name: 'idx_order_item_order')]
#[ORM\Index(columns: ['variant_id'], name: 'idx_order_item_variant')]
class OrderItem
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::INTEGER)]
    #[Groups(['order:read', 'order:items'])]
    private ?int $id = null;

    /**
     * Commande parente.
     * 
     * Cascade DELETE : Si commande supprimée, ses items aussi.
     */
    #[ORM\ManyToOne(targetEntity: Order::class, inversedBy: 'items')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    #[Assert\NotNull(message: 'La commande est requise.')]
    private ?Order $order = null;

    /**
     * Référence vers la variante (peut devenir null si supprimée).
     * 
     * Le productSnapshot garde toutes les infos nécessaires.
     */
    #[ORM\ManyToOne(targetEntity: ProductVariant::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    #[Groups(['order:read:admin'])]
    private ?ProductVariant $variant = null;

    /**
     * Référence vers le produit parent (peut devenir null si supprimé).
     */
    #[ORM\ManyToOne(targetEntity: Product::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    #[Groups(['order:read:admin'])]
    private ?Product $product = null;

    /**
     * Quantité commandée (figée).
     */
    #[ORM\Column(type: Types::INTEGER)]
    #[Assert\Positive(message: 'La quantité doit être positive.')]
    #[Assert\Range(min: 1, max: 999)]
    #[Groups(['order:read', 'order:items'])]
    private int $quantity = 1;

    /**
     * Prix unitaire HT au moment de la commande (figé).
     * 
     * Copié depuis CartItem.priceAtAdd.
     * Ne change JAMAIS même si le prix catalogue évolue.
     */
    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 2)]
    #[Assert\PositiveOrZero]
    #[Groups(['order:read', 'order:items'])]
    private float $unitPrice = 0.0;

    /**
     * Taux de TVA appliqué à cette ligne (en %).
     * 
     * Permet gestion multi-taux (certains produits 5.5%, d'autres 20%).
     * Pour MVP, on peut utiliser le taux global de Order.
     */
    #[ORM\Column(type: Types::DECIMAL, precision: 5, scale: 2)]
    #[Assert\PositiveOrZero]
    #[Assert\Range(min: 0, max: 100)]
    #[Groups(['order:read', 'order:items'])]
    private float $taxRate = 20.0;

    /**
     * Montant de TVA sur cette ligne (figé).
     * 
     * Formule : (unitPrice × quantity) × (taxRate / 100)
     */
    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 2)]
    #[Assert\PositiveOrZero]
    #[Groups(['order:read', 'order:items'])]
    private float $taxAmount = 0.0;

    /**
     * Snapshot complet du produit/variante (JSON).
     * 
     * Structure :
     * {
     *   "product_id": 42,
     *   "product_name": "Miel de Fleurs Bio",
     *   "product_slug": "miel-de-fleurs-bio",
     *   "variant_id": 123,
     *   "variant_sku": "MIEL-FLEURS-250G",
     *   "variant_name": "Pot 250g",
     *   "full_name": "Miel de Fleurs Bio - Pot 250g",
     *   "image": "/uploads/products/miel-1.jpg",
     *   "weight": 250,
     *   "attributes": {
     *     "origin": "France",
     *     "organic_label": "AB"
     *   }
     * }
     * 
     * Copié depuis CartItem.productSnapshot.
     * Garantit traçabilité même si produit supprimé.
     */
    #[ORM\Column(type: Types::JSON)]
    #[Assert\NotNull]
    #[Groups(['order:read', 'order:items'])]
    private array $productSnapshot = [];

    /**
     * Économie réalisée avec tarif dégressif (figée).
     * 
     * Copiée depuis CartItem.savingsAtAdd.
     * null si pas de tarif dégressif.
     */
    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 2, nullable: true)]
    #[Groups(['order:read', 'order:items'])]
    private ?float $savingsAmount = null;

    /**
     * Message personnalisé pour cet item (optionnel).
     * 
     * Exemple : "C'est un cadeau, merci de l'emballer"
     */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    #[Assert\Length(max: 500)]
    #[Groups(['order:read', 'order:items'])]
    private ?string $customMessage = null;

    // ===============================================
    // GETTERS & SETTERS
    // ===============================================

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getOrder(): ?Order
    {
        return $this->order;
    }

    public function setOrder(?Order $order): static
    {
        $this->order = $order;
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

    public function getUnitPrice(): float
    {
        return $this->unitPrice;
    }

    public function setUnitPrice(float $unitPrice): static
    {
        $this->unitPrice = $unitPrice;
        return $this;
    }

    public function getTaxRate(): float
    {
        return $this->taxRate;
    }

    public function setTaxRate(float $taxRate): static
    {
        $this->taxRate = $taxRate;
        return $this;
    }

    public function getTaxAmount(): float
    {
        return $this->taxAmount;
    }

    public function setTaxAmount(float $taxAmount): static
    {
        $this->taxAmount = $taxAmount;
        return $this;
    }

    public function getProductSnapshot(): array
    {
        return $this->productSnapshot;
    }

    public function setProductSnapshot(array $productSnapshot): static
    {
        $this->productSnapshot = $productSnapshot;
        return $this;
    }

    public function getSavingsAmount(): ?float
    {
        return $this->savingsAmount;
    }

    public function setSavingsAmount(?float $savingsAmount): static
    {
        $this->savingsAmount = $savingsAmount;
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
     * Calcule le montant total HT de cette ligne.
     * 
     * Formule : unitPrice × quantity
     */
    public function getLineSubtotal(): float
    {
        return $this->unitPrice * $this->quantity;
    }

    /**
     * Calcule le montant total TTC de cette ligne.
     * 
     * Formule : lineSubtotal + taxAmount
     */
    public function getLineTotal(): float
    {
        return $this->getLineSubtotal() + $this->taxAmount;
    }

    /**
     * Calcule les économies totales sur cette ligne.
     */
    public function getSavings(): ?float
    {
        if ($this->savingsAmount === null) {
            return null;
        }

        return $this->savingsAmount * $this->quantity;
    }

    /**
     * Calcule le poids total de cette ligne (grammes).
     */
    public function getTotalWeight(): ?int
    {
        $weight = $this->getSnapshotValue('weight');

        if ($weight === null) {
            return null;
        }

        return (int)$weight * $this->quantity;
    }

    // ===============================================
    // HELPERS MÉTIER - SNAPSHOT
    // ===============================================

    /**
     * Récupère une valeur du snapshot.
     */
    public function getSnapshotValue(string $key, mixed $default = null): mixed
    {
        return $this->productSnapshot[$key] ?? $default;
    }

    /**
     * Nom complet pour affichage.
     * 
     * Ordre de priorité :
     * 1. Snapshot (toujours disponible)
     * 2. Variante actuelle (si toujours existante)
     * 3. Fallback "Produit indisponible"
     */
    public function getDisplayName(): string
    {
        // Priorité au snapshot (données figées de la commande)
        if (!empty($this->productSnapshot['full_name'])) {
            return $this->productSnapshot['full_name'];
        }

        // Fallback sur variante actuelle
        if ($this->variant) {
            return $this->variant->getFullName();
        }

        return 'Produit indisponible';
    }

    /**
     * Image pour affichage.
     */
    public function getDisplayImage(): ?string
    {
        return $this->getSnapshotValue('image');
    }

    /**
     * SKU pour affichage.
     */
    public function getDisplaySku(): ?string
    {
        return $this->getSnapshotValue('variant_sku');
    }

    /**
     * Nom du produit parent (sans variante).
     */
    public function getProductName(): string
    {
        return $this->getSnapshotValue('product_name', 'Produit');
    }

    /**
     * Nom de la variante seule.
     */
    public function getVariantName(): string
    {
        return $this->getSnapshotValue('variant_name', '');
    }

    // ===============================================
    // HELPERS MÉTIER - VALIDATION
    // ===============================================

    /**
     * Vérifie si le produit/variante existe toujours.
     */
    public function isProductStillAvailable(): bool
    {
        return $this->variant !== null && !$this->variant->isDeleted();
    }

    /**
     * Vérifie si on peut racheter ce produit.
     */
    public function canReorder(): bool
    {
        if (!$this->variant) {
            return false;
        }

        return $this->variant->isActive()
            && !$this->variant->isDeleted()
            && $this->variant->isInStock();
    }

    // ===============================================
    // FACTORY - CRÉATION DEPUIS CARTITEM
    // ===============================================

    /**
     * Crée un OrderItem depuis un CartItem.
     * 
     * Copie tous les snapshots et données figées.
     * À appeler lors de la conversion Panier → Commande.
     */
    public static function createFromCartItem(\App\Entity\Cart\CartItem $cartItem): self
    {
        $orderItem = new self();

        $orderItem->setVariant($cartItem->getVariant());
        $orderItem->setProduct($cartItem->getProduct());
        $orderItem->setQuantity($cartItem->getQuantity());
        $orderItem->setUnitPrice($cartItem->getPriceAtAdd());
        $orderItem->setProductSnapshot($cartItem->getProductSnapshot() ?? []);
        $orderItem->setSavingsAmount($cartItem->getSavingsAtAdd());
        $orderItem->setCustomMessage($cartItem->getCustomMessage());

        // Taux de TVA (à définir selon logique métier)
        // Pour l'instant, on met le taux standard français
        $orderItem->setTaxRate(20.0);

        // Calcul du montant de TVA
        $lineSubtotal = $orderItem->getLineSubtotal();
        $taxAmount = $lineSubtotal * ($orderItem->getTaxRate() / 100);
        $orderItem->setTaxAmount(round($taxAmount, 2));

        return $orderItem;
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
            'unit_price' => $this->unitPrice,
            'tax_rate' => $this->taxRate,
            'tax_amount' => $this->taxAmount,
            'line_subtotal' => $this->getLineSubtotal(),
            'line_total' => $this->getLineTotal(),
            'savings' => $this->getSavings(),
            'weight' => $this->getTotalWeight(),
            'can_reorder' => $this->canReorder(),
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
