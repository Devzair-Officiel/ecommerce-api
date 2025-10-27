<?php

declare(strict_types=1);

namespace App\Entity\Product;

use App\Traits\DateTrait;
use App\Entity\Cart\CartItem;
use Doctrine\DBAL\Types\Types;
use App\Traits\ActiveStateTrait;
use Doctrine\ORM\Mapping as ORM;
use App\Traits\SoftDeletableTrait;
use Symfony\Component\Serializer\Annotation\Groups;
use App\Repository\Product\ProductVariantRepository;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Doctrine\Common\Collections\Collection;
use Doctrine\Common\Collections\ArrayCollection;

/**
 * Variante de produit (format/volume spécifique).
 * 
 * Responsabilités :
 * - Gestion du stock par variante (compteur simple)
 * - Prix différenciés multidevises (EUR, USD...) et multi-segments (B2B/B2C)
 * - Tarifs dégressifs par quantité
 * - Informations physiques (poids, dimensions, EAN)
 * 
 * Exemple : "Miel de Fleurs Bio" → Variantes : Pot 250g, Pot 500g, Pot 1kg
 * 
 * Structure JSON des prix :
 * {
 *   "EUR": {
 *     "B2C": {
 *       "base": 12.90,
 *       "quantity_tiers": [
 *         {"min": 5, "price": 11.90},
 *         {"min": 10, "price": 10.90}
 *       ]
 *     },
 *     "B2B": {
 *       "base": 9.90,
 *       "quantity_tiers": [
 *         {"min": 20, "price": 8.50}
 *       ]
 *     }
 *   },
 *   "USD": {
 *     "B2C": { "base": 14.90 }
 *   }
 * }
 */
#[ORM\Entity(repositoryClass: ProductVariantRepository::class)]
#[ORM\Index(columns: ['sku'], name: 'idx_variant_sku')]
#[ORM\Index(columns: ['stock'], name: 'idx_variant_stock')]
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
     * SKU unique de la variante (ex: MIEL-FLEURS-250G).
     * Doit être différent du SKU parent du produit.
     */
    #[ORM\Column(type: Types::STRING, length: 100, unique: true)]
    #[Assert\NotBlank(message: 'Le SKU est obligatoire.')]
    #[Assert\Length(max: 100)]
    #[Assert\Regex(pattern: '/^[A-Z0-9\-_]+$/', message: 'SKU invalide (majuscules, chiffres, tirets uniquement).')]
    #[Groups(['variant:read', 'variant:list', 'variant:write', 'product:variants'])]
    private ?string $sku = null;

    /**
     * Nom de la variante (ex: "Pot 250g", "Bidon 1L").
     * Affiché en complément du nom du produit parent.
     */
    #[ORM\Column(type: Types::STRING, length: 255)]
    #[Assert\NotBlank(message: 'Le nom est obligatoire.')]
    #[Assert\Length(max: 255)]
    #[Groups(['variant:read', 'variant:list', 'variant:write', 'product:variants'])]
    private ?string $name = null;

    /**
     * Description courte spécifique à cette variante (optionnel).
     * Si vide, utilise la description du produit parent.
     */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    #[Assert\Length(max: 500)]
    #[Groups(['variant:read', 'variant:write'])]
    private ?string $description = null;

    /**
     * Prix différenciés par devise, type client et quantité (JSON).
     * 
     * Permet de gérer :
     * - Multi-devises (EUR, USD, GBP...)
     * - Multi-segments (B2C, B2B)
     * - Tarifs dégressifs par paliers de quantité
     * 
     * Note : Les promotions (coupons) s'appliquent en surcouche via l'entité Coupon.
     */
    #[ORM\Column(type: Types::JSON)]
    #[Assert\NotBlank(message: 'Les prix sont obligatoires.')]
    #[Groups(['variant:read', 'variant:write', 'product:variants'])]
    private array $prices = [];

    // ===============================================
    // GESTION DU STOCK
    // ===============================================

    /**
     * Stock physique disponible (compteur simple).
     * 
     * Stratégie :
     * - Décrémenté lors de la validation de commande
     * - Incrémenté lors d'annulation/retour
     * - Stock négatif impossible (max(0, stock - qty))
     * 
     * Future évolution possible : Stock réservé (paniers) via StockMovement.
     */
    #[ORM\Column(type: Types::INTEGER, options: ['default' => 0])]
    #[Assert\PositiveOrZero(message: 'Le stock ne peut pas être négatif.')]
    #[Groups(['variant:read', 'variant:list', 'variant:write', 'product:variants'])]
    private int $stock = 0;

    /**
     * Seuil d'alerte stock bas (pour notifications admin).
     * Déclenche une alerte quand stock ≤ threshold.
     */
    #[ORM\Column(type: Types::INTEGER, options: ['default' => 5])]
    #[Assert\PositiveOrZero]
    #[Groups(['variant:read', 'variant:write'])]
    private int $lowStockThreshold = 5;

    /**
     * Stock de sécurité (optionnel, pour éviter survente).
     * 
     * Utilisation :
     * - Stock vendable = stock - safetyStock
     * - Protège contre les commandes concurrentes lors du checkout
     * 
     * Exemple : stock = 10, safetyStock = 2 → vendable = 8
     * 
     * Note : Non utilisé par défaut (0), à activer si besoin.
     */
    #[ORM\Column(type: Types::INTEGER, options: ['default' => 0])]
    #[Assert\PositiveOrZero]
    #[Groups(['variant:read', 'variant:write'])]
    private int $safetyStock = 0;

    // ===============================================
    // INFORMATIONS PHYSIQUES
    // ===============================================

    /**
     * Poids net en grammes (pour calcul frais de port).
     * Si null, utilise le poids moyen du produit parent.
     */
    #[ORM\Column(type: Types::INTEGER, nullable: true)]
    #[Assert\Positive]
    #[Groups(['variant:read', 'variant:write'])]
    private ?int $weight = null;

    /**
     * Dimensions en cm (JSON: {"length": 10, "width": 5, "height": 15}).
     * Utilisé pour calcul volumétrique des frais de port.
     */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    #[Groups(['variant:read', 'variant:write'])]
    private ?array $dimensions = null;

    /**
     * Code-barres EAN13 (optionnel, pour intégration logistique).
     */
    #[ORM\Column(type: Types::STRING, length: 13, nullable: true)]
    #[Assert\Length(exactly: 13)]
    #[Assert\Regex(pattern: '/^\d{13}$/', message: 'EAN invalide (13 chiffres requis).')]
    #[Groups(['variant:read', 'variant:write'])]
    private ?string $ean = null;

    // ===============================================
    // AFFICHAGE & ORGANISATION
    // ===============================================

    /**
     * Position pour l'ordre d'affichage sur la page produit.
     * Plus petit = affiché en premier.
     */
    #[ORM\Column(type: Types::INTEGER, options: ['default' => 0])]
    #[Assert\PositiveOrZero]
    #[Groups(['variant:read', 'variant:list', 'variant:write'])]
    private int $position = 0;

    /**
     * Variante par défaut pour ce produit.
     * 
     * Une seule variante devrait avoir isDefault=true par produit.
     * Utilisée pour :
     * - Affichage initial sur page produit
     * - Prix affiché dans les listings
     * - Ajout rapide au panier
     */
    #[ORM\Column(type: Types::BOOLEAN, options: ['default' => false])]
    #[Groups(['variant:read', 'variant:list', 'variant:write'])]
    private bool $isDefault = false;

    /**
     * Image spécifique à la variante (override image produit parent).
     * Si null, utilise l'image principale du produit parent.
     * 
     * Format : URL relative (/uploads/variants/pot-250g.jpg)
     */
    #[ORM\Column(type: Types::STRING, length: 255, nullable: true)]
    #[Groups(['variant:read', 'variant:write', 'product:variants'])]
    private ?string $image = null;

    // ===============================================
    // RELATIONS
    // ===============================================

    /**
     * Produit parent (OneToMany inversé).
     * Cascade DELETE : si le produit est supprimé, ses variantes aussi.
     */
    #[ORM\ManyToOne(targetEntity: Product::class, inversedBy: 'variants')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    #[Assert\NotNull(message: 'Le produit parent est requis.')]
    #[Groups(['variant:read', 'variant:product'])]
    private ?Product $product = null;

    /**
     * @var Collection<int, CartItem>
     */
    #[ORM\OneToMany(targetEntity: CartItem::class, mappedBy: 'variant')]
    private Collection $cartItems;

    // TODO: Décommenter après création de Order/OrderItem
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

    public function getSafetyStock(): int
    {
        return $this->safetyStock;
    }

    public function setSafetyStock(int $safetyStock): static
    {
        $this->safetyStock = max(0, $safetyStock);
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
     * Récupère le prix applicable pour un contexte donné.
     * 
     * Logique :
     * 1. Vérifier devise disponible
     * 2. Vérifier type client (B2C/B2B) avec fallback sur B2C
     * 3. Appliquer tarif dégressif si quantity > 1
     * 4. Retourner prix base sinon
     * 
     * @param string $currency Code devise ISO (EUR, USD...)
     * @param string $customerType Type client (B2C, B2B)
     * @param int $quantity Quantité commandée (pour tarifs dégressifs)
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
     * Vérifie si un prix existe pour un contexte donné.
     */
    public function hasPrice(string $currency = 'EUR', string $customerType = 'B2C'): bool
    {
        return $this->getPriceFor($currency, $customerType) !== null;
    }

    /**
     * Récupère toutes les données de prix pour un contexte (base + tiers).
     * Utile pour affichage UI (ex: "À partir de 10,90€ (par 5)").
     */
    public function getPriceData(string $currency = 'EUR', string $customerType = 'B2C'): ?array
    {
        if (!$this->hasPrice($currency, $customerType)) {
            return null;
        }

        $basePrice = $this->getPriceFor($currency, $customerType, 1);
        $tiers = $this->getQuantityTiers($currency, $customerType);

        return [
            'base' => $basePrice,
            'currency' => $currency,
            'has_tiers' => !empty($tiers),
            'tiers' => $tiers,
            'formatted' => $this->getFormattedPrice($currency, $customerType, 1)
        ];
    }

    /**
     * Définit le prix de base pour une devise et un type client.
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
     * Ajoute un palier de prix par quantité.
     * 
     * Les paliers sont automatiquement triés par quantité minimale croissante.
     * 
     * @param string $currency Code devise
     * @param string $customerType Type client
     * @param int $minQuantity Quantité minimale pour ce palier
     * @param float $price Prix unitaire à ce palier
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
     * Récupère tous les paliers de prix pour un contexte donné.
     */
    public function getQuantityTiers(string $currency, string $customerType): array
    {
        return $this->prices[$currency][$customerType]['quantity_tiers'] ?? [];
    }

    /**
     * Calcule l'économie totale réalisée avec un tarif dégressif.
     * 
     * @return float|null Montant économisé ou null si pas de réduction
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

    /**
     * Calcule le pourcentage de réduction pour une quantité.
     * 
     * @return float|null Pourcentage (ex: 15.5 pour -15.5%) ou null
     */
    public function getDiscountPercentage(string $currency, string $customerType, int $quantity): ?float
    {
        $basePrice = $this->getPriceFor($currency, $customerType, 1);
        $discountedPrice = $this->getPriceFor($currency, $customerType, $quantity);

        if ($basePrice === null || $discountedPrice === null || $basePrice === $discountedPrice) {
            return null;
        }

        return round((($basePrice - $discountedPrice) / $basePrice) * 100, 2);
    }

    // ===============================================
    // HELPERS MÉTIER - GESTION DU STOCK
    // ===============================================

    /**
     * Vérifie si la variante est en stock.
     */
    public function isInStock(): bool
    {
        return $this->getAvailableStock() > 0;
    }

    /**
     * Vérifie si le stock est bas (sous le seuil d'alerte).
     */
    public function isLowStock(): bool
    {
        $available = $this->getAvailableStock();
        return $available > 0 && $available <= $this->lowStockThreshold;
    }

    /**
     * Vérifie si une quantité spécifique est disponible.
     * 
     * Prend en compte le stock de sécurité si configuré.
     */
    public function hasQuantityAvailable(int $quantity): bool
    {
        return $this->getAvailableStock() >= $quantity;
    }

    /**
     * Récupère le stock vendable (stock physique - stock de sécurité).
     */
    public function getAvailableStock(): int
    {
        return max(0, $this->stock - $this->safetyStock);
    }

    /**
     * Décrémente le stock physique.
     * 
     * Important : Cette méthode ne fait PAS de validation.
     * Toujours appeler hasQuantityAvailable() avant.
     * 
     * @param int $quantity Quantité à décrémenter
     * @return static
     */
    public function decrementStock(int $quantity): static
    {
        $this->stock = max(0, $this->stock - $quantity);
        return $this;
    }

    /**
     * Incrémente le stock (retours, réassort).
     */
    public function incrementStock(int $quantity): static
    {
        $this->stock += $quantity;
        return $this;
    }

    /**
     * Retourne le statut du stock (clé i18n pour traduction).
     * 
     * Valeurs possibles :
     * - 'in_stock' : Stock disponible
     * - 'low_stock' : Stock faible (≤ threshold)
     * - 'out_of_stock' : Rupture de stock
     */
    public function getStockStatus(): string
    {
        $available = $this->getAvailableStock();

        if ($available === 0) {
            return 'out_of_stock';
        }

        if ($available <= $this->lowStockThreshold) {
            return 'low_stock';
        }

        return 'in_stock';
    }

    /**
     * Retourne le libellé du stock pour affichage UI.
     * 
     * Exemples :
     * - "En stock (42 unités)"
     * - "Stock faible (3 unités)"
     * - "Rupture de stock"
     */
    public function getStockLabel(string $locale = 'fr'): string
    {
        $available = $this->getAvailableStock();

        if ($available === 0) {
            return $locale === 'fr' ? 'Rupture de stock' : 'Out of stock';
        }

        if ($this->isLowStock()) {
            return $locale === 'fr'
                ? sprintf('Stock faible (%d unités)', $available)
                : sprintf('Low stock (%d units)', $available);
        }

        return $locale === 'fr'
            ? sprintf('En stock (%d unités)', $available)
            : sprintf('In stock (%d units)', $available);
    }

    // ===============================================
    // HELPERS MÉTIER - AUTRES
    // ===============================================

    /**
     * Récupère l'image finale (variante ou produit parent).
     * 
     * Ordre de priorité :
     * 1. Image spécifique de la variante
     * 2. Image principale du produit parent
     * 3. null
     */
    public function getFinalImage(): ?string
    {
        return $this->image ?? $this->product?->getMainImage()['url'] ?? null;
    }

    /**
     * Nom complet pour affichage (produit + variante).
     * 
     * Exemple : "Miel de Fleurs Bio - Pot 250g"
     */
    public function getFullName(): string
    {
        $productName = $this->product?->getName() ?? '';
        return trim($productName . ' - ' . $this->name);
    }

    /**
     * Calcule le poids effectif pour calcul frais de port.
     * 
     * Ordre de priorité :
     * 1. Poids spécifique de la variante
     * 2. Poids moyen du produit parent
     * 3. null (pas de poids défini)
     */
    public function getEffectiveWeight(): ?int
    {
        return $this->weight ?? $this->product?->getAverageWeight();
    }

    /**
     * Formate les dimensions en texte lisible.
     * 
     * @return string|null Ex: "10 x 5 x 15 cm" ou null si non défini
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
     * Prix formaté pour affichage UI.
     * 
     * @return string Ex: "12,90 €" ou "Prix non disponible"
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
     * Vérifie si la variante est disponible pour un type de client.
     * 
     * Vérifie à la fois :
     * - Le customerType du produit parent (B2C/B2B/BOTH)
     * - L'existence d'un prix pour ce type client
     */
    public function isAvailableFor(string $customerType, string $currency = 'EUR'): bool
    {
        // Vérifier produit parent autorise ce type client
        if (!$this->product?->isAvailableFor($customerType)) {
            return false;
        }

        // Vérifier qu'un prix existe pour ce type client
        return $this->hasPrice($currency, $customerType);
    }

    /**
     * Génère un tableau récapitulatif pour snapshot Order/Cart.
     * 
     * Utilisé lors de la création de OrderItem pour figer les données.
     */
    public function toSnapshot(string $currency = 'EUR', string $customerType = 'B2C'): array
    {
        return [
            'variant_id' => $this->id,
            'sku' => $this->sku,
            'name' => $this->name,
            'full_name' => $this->getFullName(),
            'price' => $this->getPriceFor($currency, $customerType),
            'currency' => $currency,
            'weight' => $this->getEffectiveWeight(),
            'image' => $this->getFinalImage(),
            'ean' => $this->ean,
        ];
    }

    public function __toString(): string
    {
        return $this->getFullName();
    }
}
