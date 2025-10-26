<?php

declare(strict_types=1);

namespace App\Entity\Product;

use App\Entity\Site\Site;
use App\Traits\DateTrait;
use App\Traits\ActiveStateTrait;
use App\Traits\SoftDeletableTrait;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Gedmo\Mapping\Annotation as Gedmo;
use Doctrine\Common\Collections\Collection;
use Doctrine\Common\Collections\ArrayCollection;
use App\Repository\Product\ProductRepository;
use Symfony\Component\Serializer\Annotation\Groups;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;

/**
 * Produit parent regroupant les variantes.
 * 
 * Responsabilités :
 * - Informations communes à toutes les variantes (description, images, certifications)
 * - Gestion SEO et multilingue
 * - Support des prix différenciés (B2B/B2C) et multi-devises
 * - Promotions applicables au niveau produit
 * 
 * Exemple : "Miel de Fleurs Bio" → Variantes : 250g, 500g, 1kg
 */
#[ORM\Entity(repositoryClass: ProductRepository::class)]
#[ORM\Index(columns: ['slug', 'site_id', 'locale'], name: 'idx_product_slug_site_locale')]
#[ORM\Index(columns: ['sku'], name: 'idx_product_sku')]
#[ORM\UniqueConstraint(name: 'UNIQ_PRODUCT_SKU_SITE_LOCALE', fields: ['sku', 'site', 'locale'])]
#[UniqueEntity(
    fields: ['slug', 'site', 'locale'],
    message: 'Ce slug existe déjà pour ce site et cette langue.'
)]
#[ORM\HasLifecycleCallbacks]
class Product
{
    use DateTrait;
    use ActiveStateTrait;
    use SoftDeletableTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::INTEGER)]
    #[Groups(['product:read', 'product:list'])]
    private ?int $id = null;

    /**
     * SKU parent (ex: MIEL-FLEURS)
     * Les variantes auront leur propre SKU (MIEL-FLEURS-250G)
     */
    #[ORM\Column(type: Types::STRING, length: 100)]
    #[Assert\NotBlank(message: 'Le SKU est obligatoire.')]
    #[Assert\Length(max: 100)]
    #[Assert\Regex(pattern: '/^[A-Z0-9\-_]+$/', message: 'SKU invalide (majuscules, chiffres, tirets uniquement).')]
    #[Groups(['product:read', 'product:list', 'product:write'])]
    private ?string $sku = null;

    /**
     * Nom du produit (clé de traduction ou texte)
     */
    #[ORM\Column(type: Types::STRING, length: 255)]
    #[Assert\NotBlank(message: 'Le nom est obligatoire.')]
    #[Assert\Length(max: 255)]
    #[Groups(['product:read', 'product:list', 'product:write'])]
    private ?string $name = null;

    #[ORM\Column(type: Types::STRING, length: 255)]
    #[Gedmo\Slug(fields: ['name'], updatable: true, unique: false)]
    #[Groups(['product:read', 'product:list'])]
    private ?string $slug = null;

    #[ORM\Column(type: Types::STRING, length: 5)]
    #[Assert\NotBlank]
    #[Assert\Choice(choices: ['fr', 'en', 'es'], message: 'Langue non supportée.')]
    #[Groups(['product:read', 'product:list', 'product:write'])]
    private ?string $locale = null;

    /**
     * Description courte (listing)
     */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    #[Assert\Length(max: 500)]
    #[Groups(['product:read', 'product:list', 'product:write'])]
    private ?string $shortDescription = null;

    /**
     * Description complète (page produit)
     */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    #[Groups(['product:read', 'product:write'])]
    private ?string $description = null;

    /**
     * Images du produit (JSON)
     * Structure : [
     *   { "url": "/uploads/products/miel-1.jpg", "alt": "Miel de fleurs", "position": 1, "type": "main" },
     *   { "url": "/uploads/products/miel-2.jpg", "alt": "Pot de miel", "position": 2, "type": "gallery" }
     * ]
     */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    #[Groups(['product:read', 'product:write'])]
    private ?array $images = null;

    /**
     * Attributs spécifiques produits bio (JSON flexible)
     * Structure : {
     *   "origin": "France, Provence",
     *   "certifications": ["AB", "Ecocert"],
     *   "benefits": ["Antioxydant", "Énergisant"],
     *   "ingredients": "Miel de fleurs 100%"
     * }
     */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    #[Groups(['product:read', 'product:write'])]
    private ?array $attributes = null;

    /**
     * Valeurs nutritionnelles (JSON)
     * Structure : {
     *   "per_100g": { "energy_kj": 1360, "energy_kcal": 320, "carbs": 80, "sugars": 75, "protein": 0.5, "salt": 0.01 }
     * }
     */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    #[Groups(['product:read', 'product:write'])]
    private ?array $nutritionalValues = null;

    /**
     * Type de client cible (B2C par défaut)
     * Utilisé pour filtrer les prix différenciés
     */
    #[ORM\Column(type: Types::STRING, length: 20, options: ['default' => 'B2C'])]
    #[Assert\Choice(choices: ['B2C', 'B2B', 'BOTH'], message: 'Type client invalide.')]
    #[Groups(['product:read', 'product:write'])]
    private string $customerType = 'B2C';

    /**
     * Poids moyen pour calcul des frais de port (en grammes)
     * Utilisé si les variantes n'ont pas de poids spécifique
     */
    #[ORM\Column(type: Types::INTEGER, nullable: true)]
    #[Assert\Positive]
    #[Groups(['product:read', 'product:write'])]
    private ?int $averageWeight = null;

    /**
     * Position pour l'ordre d'affichage
     */
    #[ORM\Column(type: Types::INTEGER, options: ['default' => 0])]
    #[Assert\PositiveOrZero]
    #[Groups(['product:read', 'product:list', 'product:write'])]
    private int $position = 0;

    /**
     * Produit mis en avant (featured)
     */
    #[ORM\Column(type: Types::BOOLEAN, options: ['default' => false])]
    #[Groups(['product:read', 'product:list', 'product:write'])]
    private bool $isFeatured = false;

    /**
     * Nouveauté (badge)
     */
    #[ORM\Column(type: Types::BOOLEAN, options: ['default' => false])]
    #[Groups(['product:read', 'product:list', 'product:write'])]
    private bool $isNew = false;

    // ===============================================
    // CHAMPS SEO
    // ===============================================

    #[ORM\Column(type: Types::STRING, length: 70, nullable: true)]
    #[Assert\Length(max: 70)]
    #[Groups(['product:read', 'product:write', 'seo'])]
    private ?string $metaTitle = null;

    #[ORM\Column(type: Types::STRING, length: 160, nullable: true)]
    #[Assert\Length(max: 160)]
    #[Groups(['product:read', 'product:write', 'seo'])]
    private ?string $metaDescription = null;

    #[ORM\Column(type: Types::STRING, length: 255, nullable: true)]
    #[Assert\Url]
    #[Groups(['product:read', 'product:write', 'seo'])]
    private ?string $canonicalUrl = null;

    /**
     * Structured Data JSON-LD pour Google (produit riche)
     */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    #[Groups(['product:read', 'product:write', 'seo'])]
    private ?array $structuredData = null;

    // ===============================================
    // RELATIONS
    // ===============================================

    #[ORM\ManyToOne(targetEntity: Site::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    #[Assert\NotNull(message: 'Le site est requis.')]
    #[Groups(['product:read', 'site'])]
    private ?Site $site = null;

    /**
     * @var Collection<int, ProductVariant>
     */
    #[ORM\OneToMany(targetEntity: ProductVariant::class, mappedBy: 'product', cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['position' => 'ASC'])]
    #[Groups(['product:read', 'product:variants'])]
    private Collection $variants;

    /**
     * @var Collection<int, Category>
     */
    #[ORM\ManyToMany(targetEntity: Category::class)]
    #[ORM\JoinTable(name: 'product_categories')]
    #[Groups(['product:read', 'product:categories'])]
    private Collection $categories;

    // /**
    //  * @var Collection<int, Review>
    //  */
    // #[ORM\OneToMany(targetEntity: Review::class, mappedBy: 'product')]
    // private Collection $reviews;

    // /**
    //  * @var Collection<int, Coupon>
    //  */
    // #[ORM\ManyToMany(targetEntity: Coupon::class, mappedBy: 'products')]
    // private Collection $coupons;

    public function __construct()
    {
        $this->variants = new ArrayCollection();
        $this->categories = new ArrayCollection();
        // $this->reviews = new ArrayCollection();
        // $this->coupons = new ArrayCollection();
    }

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

    public function getSlug(): ?string
    {
        return $this->slug;
    }

    public function setSlug(string $slug): static
    {
        $this->slug = $slug;
        return $this;
    }

    public function getLocale(): ?string
    {
        return $this->locale;
    }

    public function setLocale(string $locale): static
    {
        $this->locale = $locale;
        return $this;
    }

    public function getShortDescription(): ?string
    {
        return $this->shortDescription;
    }

    public function setShortDescription(?string $shortDescription): static
    {
        $this->shortDescription = $shortDescription;
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

    public function getImages(): ?array
    {
        return $this->images;
    }

    public function setImages(?array $images): static
    {
        $this->images = $images;
        return $this;
    }

    public function getAttributes(): ?array
    {
        return $this->attributes;
    }

    public function setAttributes(?array $attributes): static
    {
        $this->attributes = $attributes;
        return $this;
    }

    public function getNutritionalValues(): ?array
    {
        return $this->nutritionalValues;
    }

    public function setNutritionalValues(?array $nutritionalValues): static
    {
        $this->nutritionalValues = $nutritionalValues;
        return $this;
    }

    public function getCustomerType(): string
    {
        return $this->customerType;
    }

    public function setCustomerType(string $customerType): static
    {
        $this->customerType = $customerType;
        return $this;
    }

    public function getAverageWeight(): ?int
    {
        return $this->averageWeight;
    }

    public function setAverageWeight(?int $averageWeight): static
    {
        $this->averageWeight = $averageWeight;
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

    public function isFeatured(): bool
    {
        return $this->isFeatured;
    }

    public function setIsFeatured(bool $isFeatured): static
    {
        $this->isFeatured = $isFeatured;
        return $this;
    }

    public function isNew(): bool
    {
        return $this->isNew;
    }

    public function setIsNew(bool $isNew): static
    {
        $this->isNew = $isNew;
        return $this;
    }

    public function getMetaTitle(): ?string
    {
        return $this->metaTitle;
    }

    public function setMetaTitle(?string $metaTitle): static
    {
        $this->metaTitle = $metaTitle;
        return $this;
    }

    public function getMetaDescription(): ?string
    {
        return $this->metaDescription;
    }

    public function setMetaDescription(?string $metaDescription): static
    {
        $this->metaDescription = $metaDescription;
        return $this;
    }

    public function getCanonicalUrl(): ?string
    {
        return $this->canonicalUrl;
    }

    public function setCanonicalUrl(?string $canonicalUrl): static
    {
        $this->canonicalUrl = $canonicalUrl;
        return $this;
    }

    public function getStructuredData(): ?array
    {
        return $this->structuredData;
    }

    public function setStructuredData(?array $structuredData): static
    {
        $this->structuredData = $structuredData;
        return $this;
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

    /**
     * @return Collection<int, ProductVariant>
     */
    public function getVariants(): Collection
    {
        return $this->variants;
    }

    public function addVariant(ProductVariant $variant): static
    {
        if (!$this->variants->contains($variant)) {
            $this->variants->add($variant);
            $variant->setProduct($this);
        }

        return $this;
    }

    public function removeVariant(ProductVariant $variant): static
    {
        if ($this->variants->removeElement($variant)) {
            if ($variant->getProduct() === $this) {
                $variant->setProduct(null);
            }
        }

        return $this;
    }

    /**
     * @return Collection<int, Category>
     */
    public function getCategories(): Collection
    {
        return $this->categories;
    }

    public function addCategory(Category $category): static
    {
        if (!$this->categories->contains($category)) {
            $this->categories->add($category);
        }

        return $this;
    }

    public function removeCategory(Category $category): static
    {
        $this->categories->removeElement($category);
        return $this;
    }

    // ===============================================
    // HELPERS MÉTIER
    // ===============================================

    /**
     * Récupère l'image principale
     */
    public function getMainImage(): ?array
    {
        if (empty($this->images)) {
            return null;
        }

        foreach ($this->images as $image) {
            if (isset($image['type']) && $image['type'] === 'main') {
                return $image;
            }
        }

        return $this->images[0] ?? null;
    }

    /**
     * Récupère toutes les images de la galerie
     */
    public function getGalleryImages(): array
    {
        if (empty($this->images)) {
            return [];
        }

        return array_filter(
            $this->images,
            fn($img) =>
            !isset($img['type']) || $img['type'] === 'gallery'
        );
    }

    /**
     * Vérifie si le produit a du stock disponible (au moins une variante)
     */
    public function hasStock(): bool
    {
        foreach ($this->variants as $variant) {
            if ($variant->isInStock()) {
                return true;
            }
        }
        return false;
    }

    /**
     * Récupère la variante par défaut (première disponible)
     */
    public function getDefaultVariant(): ?ProductVariant
    {
        foreach ($this->variants as $variant) {
            if ($variant->isActive() && $variant->isInStock()) {
                return $variant;
            }
        }

        return $this->variants->first() ?: null;
    }

    /**
     * Prix minimum toutes variantes confondues (pour affichage listing)
     */
    public function getMinPrice(string $currency = 'EUR', string $customerType = 'B2C'): ?float
    {
        $prices = [];
        foreach ($this->variants as $variant) {
            if ($variant->isActive()) {
                $price = $variant->getPriceFor($currency, $customerType);
                if ($price !== null) {
                    $prices[] = $price;
                }
            }
        }

        return !empty($prices) ? min($prices) : null;
    }

    /**
     * Prix maximum toutes variantes confondues
     */
    public function getMaxPrice(string $currency = 'EUR', string $customerType = 'B2C'): ?float
    {
        $prices = [];
        foreach ($this->variants as $variant) {
            if ($variant->isActive()) {
                $price = $variant->getPriceFor($currency, $customerType);
                if ($price !== null) {
                    $prices[] = $price;
                }
            }
        }

        return !empty($prices) ? max($prices) : null;
    }

    /**
     * Formate le prix pour affichage (ex: "À partir de 12,90 €")
     */
    public function getFormattedPriceRange(string $currency = 'EUR', string $customerType = 'B2C'): string
    {
        $min = $this->getMinPrice($currency, $customerType);
        $max = $this->getMaxPrice($currency, $customerType);

        if ($min === null) {
            return 'Prix non disponible';
        }

        if ($min === $max) {
            return number_format($min, 2, ',', ' ') . ' ' . $currency;
        }

        return sprintf(
            'À partir de %s %s',
            number_format($min, 2, ',', ' '),
            $currency
        );
    }

    /**
     * Récupère un attribut spécifique
     */
    public function getAttribute(string $key, mixed $default = null): mixed
    {
        return $this->attributes[$key] ?? $default;
    }

    /**
     * Définit un attribut
     */
    public function setAttribute(string $key, mixed $value): static
    {
        $attributes = $this->attributes ?? [];
        $attributes[$key] = $value;
        $this->attributes = $attributes;
        return $this;
    }

    /**
     * Vérifie si le produit a une certification
     */
    public function hasCertification(string $certification): bool
    {
        $certifications = $this->getAttribute('certifications', []);
        return in_array($certification, $certifications, true);
    }

    /**
     * Génère l'URL complète du produit
     */
    public function getUrl(): string
    {
        return sprintf('/%s/produits/%s', $this->locale, $this->slug);
    }

    /**
     * Vérifie si le produit est disponible pour un type de client
     */
    public function isAvailableFor(string $customerType): bool
    {
        return $this->customerType === 'BOTH' || $this->customerType === $customerType;
    }

    /**
     * Compte le nombre de variantes actives
     */
    public function getActiveVariantsCount(): int
    {
        return $this->variants->filter(fn($v) => $v->isActive())->count();
    }

    public function __toString(): string
    {
        return $this->name ?? 'Product #' . $this->id;
    }
}
