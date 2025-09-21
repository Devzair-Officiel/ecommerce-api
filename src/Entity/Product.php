<?php

namespace App\Entity;

use App\Traits\DateTrait;
use App\Validator\BusinessRule;
use Doctrine\ORM\Mapping as ORM;
use Doctrine\Common\Collections\Collection;
use Doctrine\Common\Collections\ArrayCollection;
use Symfony\Component\Serializer\Annotation\Groups;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;
use Gedmo\Mapping\Annotation as Gedmo;


#[ORM\Entity(repositoryClass: "App\Repository\ProductRepository")]
#[ORM\HasLifecycleCallbacks]
#[ORM\Index(columns: ['site_id', 'slug'], name: 'idx_product_site_slug')]
#[ORM\Index(columns: ['is_active', 'featured'], name: 'idx_product_active_featured')]
#[ORM\Index(columns: ['sku'], name: 'idx_product_sku')]
class Product
{
    use DateTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    #[Groups(['product_list', 'product_detail', 'cart_item', 'order_item'])]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Site::class, inversedBy: 'products')]
    #[ORM\JoinColumn(nullable: false)]
    #[Assert\NotNull(message: "validation.product.site.required")]
    private ?Site $site = null;

    #[ORM\Column(length: 200)]
    #[Groups(['product_list', 'product_detail', 'cart_item', 'order_item', 'public_read'])]
    #[Assert\NotBlank(message: "validation.product.name.required")]
    #[Assert\Length(max: 200, maxMessage: "validation.product.name.max_length")]
    private ?string $name = null;

    #[ORM\Column(length: 220, unique: true)]
    #[Groups(['product_list', 'product_detail', 'public_read'])]
    #[Gedmo\Slug(fields: ['name'])]
    #[Assert\NotBlank(message: "validation.product.slug.required")]
    #[Assert\Regex(pattern: "/^[a-z0-9\-]+$/", message: "validation.product.slug.format")]
    private ?string $slug = null;

    #[ORM\Column(type: 'text', nullable: true)]
    #[Groups(['product_detail', 'public_read'])]
    private ?string $description = null;

    #[ORM\Column(type: 'text', nullable: true)]
    #[Groups(['product_detail', 'public_read'])]
    #[Assert\Length(max: 500, maxMessage: "validation.product.short_description.max_length")]
    private ?string $shortDescription = null;

    #[ORM\Column(length: 50, nullable: true)]
    #[Groups(['product_detail', 'admin_read'])]
    #[Assert\Length(max: 50, maxMessage: "validation.product.sku.max_length")]
    private ?string $sku = null;

    #[ORM\Column(type: 'decimal', precision: 10, scale: 2)]
    #[Groups(['product_list', 'product_detail', 'cart_item', 'order_item', 'public_read'])]
    #[Assert\NotBlank(message: "validation.product.price.required")]
    #[Assert\PositiveOrZero(message: "validation.product.price.positive")]
    private ?string $price = null;

    #[ORM\Column(type: 'decimal', precision: 10, scale: 2, nullable: true)]
    #[Groups(['product_list', 'product_detail', 'public_read'])]
    #[Assert\PositiveOrZero(message: "validation.product.original_price.positive")]
    #[BusinessRule(
        method: 'validateOriginalPrice',
        message: 'validation.product.original_price.must_be_higher'
    )]
    private ?string $originalPrice = null;

    #[ORM\Column]
    #[Groups(['product_detail', 'admin_read'])]
    #[Assert\PositiveOrZero(message: "validation.product.stock.positive")]
    #[BusinessRule(
        method: 'validateStockConsistency',
        message: 'validation.product.stock.below_minimum'
    )]
    private int $stock = 0;

    #[ORM\Column]
    #[Groups(['product_detail', 'admin_read'])]
    #[Assert\PositiveOrZero(message: "validation.product.min_stock.positive")]
    private int $minStock = 0;

    #[ORM\Column(type: 'decimal', precision: 8, scale: 3, nullable: true)]
    #[Groups(['product_detail', 'public_read'])]
    #[Assert\PositiveOrZero(message: "validation.product.weight.positive")]
    private ?string $weight = null;

    #[ORM\Column(type: 'json')]
    #[Groups(['product_detail', 'public_read'])]
    private array $images = [];

    #[ORM\Column]
    #[Groups(['product_list', 'admin_read'])]
    private bool $isActive = true;

    #[ORM\Column]
    #[Groups(['product_list', 'admin_read'])]
    private bool $featured = false;

    #[ORM\Column(type: 'json')]
    #[Groups(['product_detail', 'public_read'])]
    private array $attributes = [];

    #[ORM\ManyToMany(targetEntity: Category::class, inversedBy: 'products')]
    #[ORM\JoinTable(name: 'product_category')]
    #[Groups(['product_detail'])]
    private Collection $categories;

    #[ORM\OneToMany(mappedBy: 'product', targetEntity: ProductVariant::class, cascade: ['persist', 'remove'])]
    #[Groups(['product_detail'])]
    private Collection $variants;

    #[ORM\OneToMany(mappedBy: 'product', targetEntity: CartItem::class)]
    private Collection $cartItems;

    #[ORM\OneToMany(mappedBy: 'product', targetEntity: Review::class, orphanRemoval: true, cascade: ['persist', 'remove'])]
    private Collection $reviews;

    public function __construct()
    {
        $this->categories = new ArrayCollection();
        $this->variants = new ArrayCollection();
        $this->cartItems = new ArrayCollection();
        $this->reviews = new ArrayCollection();
    }

    /**
     * Validation métier complexe via callback.
     * 
     * Cette méthode centralise toutes les validations cross-field
     * qui ne peuvent pas être exprimées avec des contraintes simples.
     */
    #[Assert\Callback]
    public function validateBusinessRules(ExecutionContextInterface $context): void
    {
        // Validation : produit avec variantes ne doit pas avoir de stock principal
        if ($this->hasVariants() && $this->stock > 0) {
            $context->buildViolation('validation.product.stock.should_be_zero_with_variants')
                ->atPath('stock')
                ->addViolation();
        }

        // Validation : produit en promotion doit avoir un prix original
        if ($this->featured && !$this->originalPrice) {
            $context->buildViolation('validation.product.original_price.required_for_featured')
                ->atPath('originalPrice')
                ->addViolation();
        }

        // Validation : produit avec images doit avoir au moins une image principale
        if (!empty($this->images) && !$this->getMainImage()) {
            $context->buildViolation('validation.product.images.main_image_required')
                ->atPath('images')
                ->addViolation();
        }
    }
    
    // === GETTERS/SETTERS BASIQUES ===

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getSite(): ?Site
    {
        return $this->site;
    }

    public function setSite(?Site $site): static
    {
        $this->site = $site;
        return $this;
    }

    public function getName(): ?string
    {
        return $this->name;
    }

    // Nettoie et stocke le nom du produit
    public function setName(?string $name): static
    {
        $this->name = $name ? trim($name) : null;
        return $this;
    }

    public function getSlug(): ?string
    {
        return $this->slug;
    }

    // Formate le slug en minuscules pour l'URL
    public function setSlug(?string $slug): static
    {
        $this->slug = $slug ? strtolower(trim($slug)) : null;
        return $this;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    // Nettoie la description complète du produit
    public function setDescription(?string $description): static
    {
        $this->description = $description ? trim($description) : null;
        return $this;
    }

    public function getShortDescription(): ?string
    {
        return $this->shortDescription;
    }

    // Nettoie la description courte pour l'aperçu
    public function setShortDescription(?string $shortDescription): static
    {
        $this->shortDescription = $shortDescription ? trim($shortDescription) : null;
        return $this;
    }

    public function getSku(): ?string
    {
        return $this->sku;
    }

    // Formate le SKU en majuscules (convention)
    public function setSku(?string $sku): static
    {
        $this->sku = $sku ? strtoupper(trim($sku)) : null;
        return $this;
    }

    public function getPrice(): ?string
    {
        return $this->price;
    }

    // Définit le prix avec validation métier basique
    public function setPrice(?string $price): static
    {
        if ($price !== null && (float) $price < 0) {
            throw new \InvalidArgumentException('validation.product.price.negative');
        }
        $this->price = $price;
        return $this;
    }

    public function getOriginalPrice(): ?string
    {
        return $this->originalPrice;
    }

    // Définit le prix original (pour afficher une remise)
    public function setOriginalPrice(?string $originalPrice): static
    {
        if ($originalPrice !== null && (float) $originalPrice < 0) {
            throw new \InvalidArgumentException('validation.product.original_price.negative');
        }
        $this->originalPrice = $originalPrice;
        return $this;
    }

    public function getStock(): int
    {
        return $this->stock;
    }

    // Met à jour le stock avec validation métier
    public function setStock(int $stock): static
    {
        if ($stock < 0) {
            throw new \InvalidArgumentException('validation.product.stock.negative');
        }
        $this->stock = $stock;
        return $this;
    }

    public function getMinStock(): int
    {
        return $this->minStock;
    }

    // Définit le seuil d'alerte stock faible
    public function setMinStock(int $minStock): static
    {
        if ($minStock < 0) {
            throw new \InvalidArgumentException('validation.product.min_stock.negative');
        }
        $this->minStock = $minStock;
        return $this;
    }

    public function getWeight(): ?string
    {
        return $this->weight;
    }

    // Stocke le poids pour calcul des frais de port
    public function setWeight(?string $weight): static
    {
        if ($weight !== null && (float) $weight < 0) {
            throw new \InvalidArgumentException('validation.product.weight.negative');
        }
        $this->weight = $weight;
        return $this;
    }

    public function getImages(): array
    {
        return $this->images;
    }

    // Stocke les URLs d'images en filtrant les valeurs invalides
    public function setImages(array $images): static
    {
        $this->images = array_filter($images, 'is_string');
        return $this;
    }

    public function isActive(): bool
    {
        return $this->isActive;
    }

    public function setIsActive(bool $isActive): static
    {
        $this->isActive = $isActive;
        return $this;
    }

    public function isFeatured(): bool
    {
        return $this->featured;
    }

    public function setFeatured(bool $featured): static
    {
        $this->featured = $featured;
        return $this;
    }

    public function getAttributes(): array
    {
        return $this->attributes;
    }

    public function setAttributes(array $attributes): static
    {
        $this->attributes = $attributes;
        return $this;
    }

    // === MÉTHODES D'ÉTAT SIMPLE (conformes SOLID - lecture seule de propriétés) ===

    // Retourne la première image pour l'affichage principal
    #[Groups(['product_list', 'product_detail'])]
    public function getMainImage(): ?string
    {
        return $this->images[0] ?? null;
    }

    // Vérifie si le produit est en promotion (prix original > prix actuel)
    #[Groups(['product_detail'])]
    public function isOnSale(): bool
    {
        return $this->originalPrice !== null &&
            (float) $this->originalPrice > (float) $this->price;
    }

    // Vérifie la disponibilité du produit (avec ou sans variantes)
    #[Groups(['product_detail'])]
    public function isInStock(): bool
    {
        return $this->hasVariants() ? $this->getTotalStock() > 0 : $this->stock > 0;
    }

    // Vérifie si le produit a des variantes (tailles différentes)
    #[Groups(['product_detail'])]
    public function hasVariants(): bool
    {
        return $this->variants->count() > 0;
    }

    // Alerte stock faible (stock <= seuil minimum mais > 0)
    #[Groups(['product_detail'])]
    public function isLowStock(): bool
    {
        $currentStock = $this->hasVariants() ? $this->getTotalStock() : $this->stock;
        return $currentStock <= $this->minStock && $currentStock > 0;
    }

    // Vérifie si le produit est en rupture de stock
    #[Groups(['product_detail'])]
    public function isOutOfStock(): bool
    {
        return !$this->isInStock();
    }

    // Vérifie si le produit a des images
    #[Groups(['product_detail'])]
    public function hasImages(): bool
    {
        return !empty($this->images);
    }

    // === MÉTHODES DE CALCUL SIMPLE (basées sur les propriétés existantes) ===

    // Calcule le stock total (produit + toutes variantes actives)
    #[Groups(['product_detail'])]
    public function getTotalStock(): int
    {
        if (!$this->hasVariants()) {
            return $this->stock;
        }

        $totalStock = 0;
        foreach ($this->variants as $variant) {
            if ($variant->isActive()) {
                $totalStock += $variant->getStock();
            }
        }

        return $totalStock;
    }

    // Retourne la première variante active (par défaut)
    #[Groups(['product_detail'])]
    public function getDefaultVariant(): ?ProductVariant
    {
        if (!$this->hasVariants()) {
            return null;
        }

        foreach ($this->variants as $variant) {
            if ($variant->isActive()) {
                return $variant;
            }
        }

        return $this->variants->first() ?: null;
    }

    // Calcule la fourchette de prix des variantes
    #[Groups(['product_detail'])]
    public function getPriceRange(): array
    {
        if (!$this->hasVariants()) {
            return [
                'min' => $this->price,
                'max' => $this->price
            ];
        }

        $prices = [];
        foreach ($this->variants as $variant) {
            if ($variant->isActive()) {
                $prices[] = (float) $variant->getPrice();
            }
        }

        if (empty($prices)) {
            return [
                'min' => $this->price,
                'max' => $this->price
            ];
        }

        return [
            'min' => (string) min($prices),
            'max' => (string) max($prices)
        ];
    }

    // === GESTION DES COLLECTIONS (Doctrine standard) ===

    public function getCategories(): Collection
    {
        return $this->categories;
    }

    // Ajoute une catégorie si pas déjà présente
    public function addCategory(Category $category): static
    {
        if (!$this->categories->contains($category)) {
            $this->categories->add($category);
        }
        return $this;
    }

    // Supprime une catégorie de la collection
    public function removeCategory(Category $category): static
    {
        $this->categories->removeElement($category);
        return $this;
    }

    public function getVariants(): Collection
    {
        return $this->variants;
    }

    // Ajoute une variante en maintenant la relation bidirectionnelle
    public function addVariant(ProductVariant $variant): static
    {
        if (!$this->variants->contains($variant)) {
            $this->variants->add($variant);
            $variant->setProduct($this);
        }
        return $this;
    }

    // Supprime une variante en nettoyant la relation
    public function removeVariant(ProductVariant $variant): static
    {
        if ($this->variants->removeElement($variant)) {
            if ($variant->getProduct() === $this) {
                $variant->setProduct(null);
            }
        }
        return $this;
    }

    public function getCartItems(): Collection
    {
        return $this->cartItems;
    }

    public function getReviews(): Collection
    {
        return $this->reviews;
    }

    // Ajoute un avis en maintenant la relation bidirectionnelle
    public function addReview(Review $review): static
    {
        if (!$this->reviews->contains($review)) {
            $this->reviews->add($review);
            $review->setProduct($this);
        }
        return $this;
    }

    // Supprime un avis en nettoyant la relation
    public function removeReview(Review $review): static
    {
        if ($this->reviews->removeElement($review)) {
            if ($review->getProduct() === $this) {
                $review->setProduct(null);
            }
        }
        return $this;
    }

    // === GESTION DES IMAGES (manipulation de la propriété array) ===

    // Ajoute une image si pas déjà présente
    public function addImage(string $image): static
    {
        if (!in_array($image, $this->images, true)) {
            $this->images[] = $image;
        }
        return $this;
    }

    // Supprime une image et réindexe le tableau
    public function removeImage(string $image): static
    {
        $key = array_search($image, $this->images, true);
        if ($key !== false) {
            unset($this->images[$key]);
            $this->images = array_values($this->images);
        }
        return $this;
    }

    // Vide toutes les images
    public function clearImages(): static
    {
        $this->images = [];
        return $this;
    }

    // === GESTION DES ATTRIBUTS (manipulation de la propriété array) ===

    // Définit un attribut personnalisé (bio, origine, etc.)
    public function setAttribute(string $key, mixed $value): static
    {
        $this->attributes[$key] = $value;
        return $this;
    }

    // Récupère un attribut avec valeur par défaut
    public function getAttribute(string $key, mixed $default = null): mixed
    {
        return $this->attributes[$key] ?? $default;
    }

    // Supprime un attribut
    public function removeAttribute(string $key): static
    {
        unset($this->attributes[$key]);
        return $this;
    }

    // Vérifie l'existence d'un attribut
    public function hasAttribute(string $key): bool
    {
        return array_key_exists($key, $this->attributes);
    }

    // === MÉTHODES D'ACTION MÉTIER (conformes SOLID - actions simples sur l'état) ===

    // Active le produit
    public function activate(): static
    {
        $this->isActive = true;
        return $this;
    }

    // Désactive le produit
    public function deactivate(): static
    {
        $this->isActive = false;
        return $this;
    }

    // Met le produit en avant
    public function setAsFeatured(): static
    {
        $this->featured = true;
        return $this;
    }

    // Retire le produit des mis en avant
    public function removeFeatured(): static
    {
        $this->featured = false;
        return $this;
    }

    // Ajuste le stock (+ ou -) avec validation
    public function adjustStock(int $quantity): static
    {
        $newStock = $this->stock + $quantity;
        if ($newStock < 0) {
            throw new \InvalidArgumentException('validation.product.stock.insufficient');
        }
        $this->stock = $newStock;
        return $this;
    }
}
