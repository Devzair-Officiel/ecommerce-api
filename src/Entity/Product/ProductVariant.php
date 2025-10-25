<?php

declare(strict_types=1);

namespace App\Entity\Product;

use App\Traits\DateTrait;
use App\Traits\ActiveStateTrait;
use App\Traits\SoftDeletableTrait;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use App\Repository\Product\ProductVariantRepository;
use Symfony\Component\Serializer\Annotation\Groups;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;

/**
 * Variante de produit (format/volume spécifique).
 * 
 * Responsabilités :
 * - Gestion du stock par variante
 * - Prix différenciés (B2B/B2C, tarifs par quantité)
 * - Support multi-devises avec prix fixes
 * - Informations spécifiques (poids, dimensions, EAN)
 * 
 * Exemple : Miel 250g, Miel 500g, Miel 1kg
 * 
 * Structure des prix JSON :
 * {
 *   "EUR": {
 *     "B2C": { "base": 12.90, "quantity_tiers": [{"min": 5, "price": 11.90}] },
 *     "B2B": { "base": 9.90, "quantity_tiers": [{"min": 20, "price": 8.50}] }
 *   },
 *   "USD": { "B2C": { "base": 14.90 } }
 * }
 */
#[ORM\Entity(repositoryClass: ProductVariantRepository::class)]
#[ORM\Index(columns: ['sku'], name: 'idx_variant_sku')]
#[ORM\UniqueConstraint(name: 'UNIQ_VARIANT_SKU', fields: ['sku'])]
#[UniqueEntity(fields: ['sku'], message: 'Ce SKU variante existe déjà.')]
#[ORM\HasLifecycleCallbacks]
class ProductVariant
{
    use DateTrait;
    use ActiveStateTrait;
    use SoftDeletableTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::INTEGER)]
    #[Groups(['variant:read', 'variant:list', 'product:variants'])]
    private ?int $id = null;

    /**
     * SKU unique de la variante (ex: MIEL-FLEURS-250G)
     */
    #[ORM\Column(type: Types::STRING, length: 100, unique: true)]
    #[Assert\NotBlank(message: 'Le SKU est obligatoire.')]
    #[Assert\Length(max: 100)]
    #[Assert\Regex(pattern: '/^[A-Z0-9\-_]+$/', message: 'SKU invalide.')]
    #[Groups(['variant:read', 'variant:list', 'variant:write', 'product:variants'])]
    private ?string $sku = null;

    /**
     * Nom de la variante (ex: "Pot 250g", "Bidon 1L")
     */
    #[ORM\Column(type: Types::STRING, length: 255)]
    #[Assert\NotBlank(message: 'Le nom est obligatoire.')]
    #[Assert\Length(max: 255)]
    #[Groups(['variant:read', 'variant:list', 'variant:write', 'product:variants'])]
    private ?string $name = null;

    /**
     * Description courte spécifique à la variante (optionnel)
     */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    #[Assert\Length(max: 500)]
    #[Groups(['variant:read', 'variant:write'])]
    private ?string $description = null;

    /**
     * Prix différenciés par devise, type client et quantité (JSON)
     * Permet B2B/B2C et tarifs dégressifs
     */
    #[ORM\Column(type: Types::JSON)]
    #[Assert\NotBlank(message: 'Les prix sont obligatoires.')]
    #[Groups(['variant:read', 'variant:write', 'product:variants'])]
    private array $prices = [];

    /**
     * Stock disponible (quantité)
     */
    #[ORM\Column(type: Types::INTEGER, options: ['default' => 0])]
    #[Assert\PositiveOrZero]
    #[Groups(['variant:read', 'variant:list', 'variant:write', 'product:variants'])]
    private int $stock = 0;

    /**
     * Seuil d'alerte stock bas (pour notifications admin)
     */
    #[ORM\Column(type: Types::INTEGER, options: ['default' => 5])]
    #[Assert\PositiveOrZero]
    #[Groups(['variant:read', 'variant:write'])]
    private int $lowStockThreshold = 5;

    /**
     * Poids net en grammes (pour calcul frais de port)
     */
    #[ORM\Column(type: Types::INTEGER, nullable: true)]
    #[Assert\Positive]
    #[Groups(['variant:read', 'variant:write'])]
    private ?int $weight = null;

    /**
     * Dimensions en cm (JSON: {"length": 10, "width": 5, "height": 15})
     */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    #[Groups(['variant:read', 'variant:write'])]
    private ?array $dimensions = null;

    /**
     * Code-barres EAN13 (optionnel)
     */
    #[ORM\Column(type: Types::STRING, length: 13, nullable: true)]
    #[Assert\Length(exactly: 13)]
    #[Assert\Regex(pattern: '/^\d{13}$/', message: 'EAN invalide (13 chiffres).')]
    #[Groups(['variant:read', 'variant:write'])]
    private ?string $ean = null;

    /**
     * Position pour l'ordre d'affichage
     */
    #[ORM\Column(type: Types::INTEGER, options: ['default' => 0])]
    #[Assert\PositiveOrZero]
    #[Groups(['variant:read', 'variant:list', 'variant:write'])]
    private int $position = 0;

    /**
     * Variante par défaut pour ce produit
     */
    #[ORM\Column(type: Types::BOOLEAN, options: ['default' => false])]
    #[Groups(['variant:read', 'variant:list', 'variant:write'])]
    private bool $isDefault = false;

    /**
     * Image spécifique à la variante (override image produit parent)
     */
    #[ORM\Column(type: Types::STRING, length: 255, nullable: true)]
    #[Groups(['variant:read', 'variant:write', 'product:variants'])]
    private ?string $image = null;

    // ===============================================
    // RELATIONS
    // ===============================================

    #[ORM\ManyToOne(targetEntity: Product::class, inversedBy: 'variants')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    #[Assert\NotNull(message: 'Le produit parent est requis.')]
    #[Groups(['variant:read', 'variant:product'])]
    private ?Product $product = null;

    // /**
    //  * @var Collection<int, CartItem>
    //  */
    // #[ORM\OneToMany(targetEntity: CartItem::class, mappedBy: 'variant')]
    // private Collection $cartItems;

    // /**
    //  * @var Collection<int, OrderItem>
    //  */
    // #[ORM\OneToMany(targetEntity: OrderItem::class, mappedBy: 'variant')]
    // private Collection $orderItems;

    // ===============================================
    // GETTERS & SETTERS
    // ===============================================

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getSku(): ?string
    {
        return $this->sku;
    }

    public function setSku(string $sku): static
    {
        $this->sku = strtoupper($sku);
        return $this;
    }

    public function getName(): ?string
    {
        return $this->name;
    }

    public function setName(string $name): static
    {
        $this->name = $name;
        return $this;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(?string $description): static
    {
        $this->description = $description;
        return $this;
    }

    public function getPrices(): array
    {
        return $this->prices;
    }

    public function setPrices(array $prices): static
    {
        $this->prices = $prices;
        return $this;
    }

    public function getStock(): int
    {
        return $this->stock;
    }

    public function setStock(int $stock): static
    {
        $this->stock = max(0, $stock);
        return $this;
    }

    public function getLowStockThreshold(): int
    {
        return $this->lowStockThreshold;
    }

    public function setLowStockThreshold(int $lowStockThreshold): static
    {
        $this->lowStockThreshold = $lowStockThreshold;
        return $this;
    }

    public function getWeight(): ?int
    {
        return $this->weight;
    }

    public function setWeight(?int $weight): static
    {
        $this->weight = $weight;
        return $this;
    }

    public function getDimensions(): ?array
    {
        return $this->dimensions;
    }

    public function setDimensions(?array $dimensions): static
    {
        $this->dimensions = $dimensions;
        return $this;
    }

    public function getEan(): ?string
    {
        return $this->ean;
    }

    public function setEan(?string $ean): static
    {
        $this->ean = $ean;
        return $this;
    }

    public function getPosition(): int
    {
        return $this->position;
    }

    public function setPosition(int $position): static
    {
        $this->position = $position;
        return $this;
    }

    public function isDefault(): bool
    {
        return $this->isDefault;
    }

    public function setIsDefault(bool $isDefault): static
    {
        $this->isDefault = $isDefault;
        return $this;
    }

    public function getImage(): ?string
    {
        return $this->image;
    }

    public function setImage(?string $image): static
    {
        $this->image = $image;
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

    // ===============================================
    // HELPERS MÉTIER - GESTION DES PRIX
    // ===============================================

    /**
     * Récupère le prix pour une devise, type client et quantité donnés
     * 
     * @param string $currency Code devise (EUR, USD...)
     * @param string $customerType Type client (B2C, B2B)
     * @param int $quantity Quantité commandée
     * @return float|null Prix applicable ou null si non défini
     */
    public function getPriceFor(string $currency = 'EUR', string $customerType = 'B2C', int $quantity = 1): ?float
    {
        // Vérifier que la devise existe
        if (!isset($this->prices[$currency])) {
            return null;
        }

        // Vérifier que le type client existe pour cette devise
        if (!isset($this->prices[$currency][$customerType])) {
            // Fallback sur B2C si B2B non défini
            if ($customerType === 'B2B' && isset($this->prices[$currency]['B2C'])) {
                $customerType = 'B2C';
            } else {
                return null;
            }
        }

        $priceConfig = $this->prices[$currency][$customerType];
        $basePrice = $priceConfig['base'] ?? null;

        if ($basePrice === null) {
            return null;
        }

        // Appliquer tarifs dégressifs si quantité > 1
        if ($quantity > 1 && isset($priceConfig['quantity_tiers'])) {
            $applicableTier = null;

            foreach ($priceConfig['quantity_tiers'] as $tier) {
                if ($quantity >= $tier['min']) {
                    $applicableTier = $tier;
                }
            }

            if ($applicableTier !== null) {
                return (float) $applicableTier['price'];
            }
        }

        return (float) $basePrice;
    }

    /**
     * Définit le prix de base pour une devise et un type client
     */
    public function setBasePrice(string $currency, string $customerType, float $price): static
    {
        if (!isset($this->prices[$currency])) {
            $this->prices[$currency] = [];
        }

        if (!isset($this->prices[$currency][$customerType])) {
            $this->prices[$currency][$customerType] = [];
        }

        $this->prices[$currency][$customerType]['base'] = $price;
        return $this;
    }

    /**
     * Ajoute un palier de prix par quantité
     */
    public function addQuantityTier(string $currency, string $customerType, int $minQuantity, float $price): static
    {
        if (!isset($this->prices[$currency][$customerType])) {
            $this->prices[$currency][$customerType] = ['base' => 0];
        }

        if (!isset($this->prices[$currency][$customerType]['quantity_tiers'])) {
            $this->prices[$currency][$customerType]['quantity_tiers'] = [];
        }

        $this->prices[$currency][$customerType]['quantity_tiers'][] = [
            'min' => $minQuantity,
            'price' => $price
        ];

        // Trier par quantité minimale croissante
        usort(
            $this->prices[$currency][$customerType]['quantity_tiers'],
            fn($a, $b) => $a['min'] <=> $b['min']
        );

        return $this;
    }

    /**
     * Récupère tous les paliers de prix pour un contexte donné
     */
    public function getQuantityTiers(string $currency, string $customerType): array
    {
        return $this->prices[$currency][$customerType]['quantity_tiers'] ?? [];
    }

    /**
     * Calcule l'économie réalisée avec un tarif dégressif
     */
    public function getSavingsForQuantity(string $currency, string $customerType, int $quantity): ?float
    {
        $basePrice = $this->getPriceFor($currency, $customerType, 1);
        $discountedPrice = $this->getPriceFor($currency, $customerType, $quantity);

        if ($basePrice === null || $discountedPrice === null || $basePrice === $discountedPrice) {
            return null;
        }

        return ($basePrice - $discountedPrice) * $quantity;
    }

    // ===============================================
    // HELPERS MÉTIER - GESTION DU STOCK
    // ===============================================

    /**
     * Vérifie si la variante est en stock
     */
    public function isInStock(): bool
    {
        return $this->stock > 0;
    }

    /**
     * Vérifie si le stock est bas (sous le seuil)
     */
    public function isLowStock(): bool
    {
        return $this->stock > 0 && $this->stock <= $this->lowStockThreshold;
    }

    /**
     * Vérifie si une quantité est disponible
     */
    public function hasQuantityAvailable(int $quantity): bool
    {
        return $this->stock >= $quantity;
    }

    /**
     * Décrémente le stock
     */
    public function decrementStock(int $quantity): static
    {
        $this->stock = max(0, $this->stock - $quantity);
        return $this;
    }

    /**
     * Incrémente le stock (retours, réassort)
     */
    public function incrementStock(int $quantity): static
    {
        $this->stock += $quantity;
        return $this;
    }

    /**
     * Statut du stock (texte)
     */
    public function getStockStatus(): string
    {
        if ($this->stock === 0) {
            return 'out_of_stock';
        }

        if ($this->isLowStock()) {
            return 'low_stock';
        }

        return 'in_stock';
    }

    // ===============================================
    // HELPERS MÉTIER - AUTRES
    // ===============================================

    /**
     * Récupère l'image finale (variante ou produit parent)
     */
    public function getFinalImage(): ?string
    {
        return $this->image ?? $this->product?->getMainImage()['url'] ?? null;
    }

    /**
     * Nom complet (produit + variante)
     */
    public function getFullName(): string
    {
        $productName = $this->product?->getName() ?? '';
        return trim($productName . ' - ' . $this->name);
    }

    /**
     * Calcule le poids pour calcul frais de port
     * (variante ou fallback sur produit parent)
     */
    public function getEffectiveWeight(): ?int
    {
        return $this->weight ?? $this->product?->getAverageWeight();
    }

    /**
     * Formate les dimensions en texte (ex: "10 x 5 x 15 cm")
     */
    public function getFormattedDimensions(): ?string
    {
        if (empty($this->dimensions)) {
            return null;
        }

        $length = $this->dimensions['length'] ?? null;
        $width = $this->dimensions['width'] ?? null;
        $height = $this->dimensions['height'] ?? null;

        if ($length && $width && $height) {
            return sprintf('%s x %s x %s cm', $length, $width, $height);
        }

        return null;
    }

    /**
     * Prix formaté pour affichage (ex: "12,90 €")
     */
    public function getFormattedPrice(string $currency = 'EUR', string $customerType = 'B2C', int $quantity = 1): string
    {
        $price = $this->getPriceFor($currency, $customerType, $quantity);

        if ($price === null) {
            return 'Prix non disponible';
        }

        return number_format($price, 2, ',', ' ') . ' ' . $currency;
    }

    /**
     * Vérifie si la variante est disponible pour un type client
     */
    public function isAvailableFor(string $customerType): bool
    {
        return $this->product?->isAvailableFor($customerType) ?? true;
    }

    public function __toString(): string
    {
        return $this->getFullName();
    }
}
