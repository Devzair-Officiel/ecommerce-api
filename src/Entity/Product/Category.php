<?php

declare(strict_types=1);

namespace App\Entity\Product;

use App\Entity\Site\Site;
use App\Traits\DateTrait;
use Doctrine\DBAL\Types\Types;
use App\Traits\ActiveStateTrait;
use Doctrine\ORM\Mapping as ORM;
use App\Traits\SoftDeletableTrait;
use Gedmo\Mapping\Annotation as Gedmo;
use Doctrine\Common\Collections\Collection;
use App\Repository\Product\CategoryRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Symfony\Component\Serializer\Annotation\Groups;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Serializer\Annotation\MaxDepth;

/**
 * Catégorie de produits avec support hiérarchique (2 niveaux max).
 * 
 * Organisation :
 * - Parent (niveau 1) : Alimentaire, Cosmétiques, etc.
 * - Enfant (niveau 2) : Miels, Huiles, etc.
 * 
 * Traductions : Les noms sont des clés traduites via fichiers YAML
 * Images : Stockées en JSON avec plusieurs tailles (original, thumbnail, etc.)
 * SEO : Champs dédiés pour optimisation moteurs de recherche
 */
#[ORM\Entity(repositoryClass: CategoryRepository::class)]
#[ORM\Index(columns: ['slug', 'site_id'], name: 'idx_category_slug_site')]
#[ORM\Index(columns: ['parent_id'], name: 'idx_category_parent')]
#[UniqueEntity(
    fields: ['slug', 'site', 'locale'],
    message: 'Ce slug existe déjà pour ce site et cette langue.'
)]
#[ORM\UniqueConstraint(name: 'UNIQ_IDENTIFIER_NAME_SITE_LOCAL', fields: ['name', 'site', 'locale'])]
#[ORM\HasLifecycleCallbacks]
class Category
{
    use DateTrait;
    use ActiveStateTrait;
    use SoftDeletableTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::INTEGER)]
    #[Groups(['category:read', 'category:list'])]
    private ?int $id = null;

    /**
     * Clé de traduction pour le nom (ex: "category.name.honeys")
     * Traduite via translations/messages.{locale}.yaml
     */
    #[ORM\Column(type: Types::STRING, length: 100)]
    #[Assert\NotBlank(message: 'Le nom de la catégorie est requis.')]
    #[Assert\Length(max: 100)]
    #[Groups(['category:read', 'category:list', 'category:write'])]
    private ?string $name = null;

    /**
     * Slug généré automatiquement depuis name via Gedmo
     * Unique par site + locale (ex: "miels", "honeys")
     */
    #[ORM\Column(type: Types::STRING, length: 100)]
    #[Gedmo\Slug(fields: ['name'], updatable: true, unique: false)]
    #[Groups(['category:read', 'category:list'])]
    private ?string $slug = null;

    /**
     * Langue de la catégorie (fr, en, es)
     */
    #[ORM\Column(type: Types::STRING, length: 5)]
    #[Assert\NotBlank]
    #[Assert\Choice(choices: ['fr', 'en', 'es'], message: 'Langue non supportée.')]
    #[Groups(['category:read', 'category:list', 'category:write'])]
    private ?string $locale = null;

    /**
     * Description longue (clé de traduction ou texte direct)
     */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    #[Groups(['category:read', 'category:write'])]
    private ?string $description = null;

    /**
     * Position pour l'ordre d'affichage (plus petit = premier)
     */
    #[ORM\Column(type: Types::INTEGER, options: ['default' => 0])]
    #[Assert\PositiveOrZero]
    #[Groups(['category:read', 'category:list', 'category:write'])]
    private int $position = 0;

    /**
     * Images de la catégorie (JSON)
     * Structure : { original, thumbnail, medium, large, alt, uploaded_at }
     */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    #[Groups(['category:read', 'category:write'])]
    private ?array $images = null;

    // ===============================================
    // CHAMPS SEO
    // ===============================================

    #[ORM\Column(type: Types::STRING, length: 70, nullable: true)]
    #[Assert\Length(max: 70)]
    #[Groups(['category:read', 'category:write', 'seo'])]
    private ?string $metaTitle = null;

    #[ORM\Column(type: Types::STRING, length: 160, nullable: true)]
    #[Assert\Length(max: 160)]
    #[Groups(['category:read', 'category:write', 'seo'])]
    private ?string $metaDescription = null;

    #[ORM\Column(type: Types::STRING, length: 255, nullable: true)]
    #[Assert\Url]
    #[Groups(['category:read', 'category:write', 'seo'])]
    private ?string $canonicalUrl = null;

    #[ORM\Column(type: Types::JSON, nullable: true)]
    #[Groups(['category:read', 'category:write', 'seo'])]
    private ?array $structuredData = null;

    // ===============================================
    // RELATIONS
    // ===============================================

    #[ORM\ManyToOne(targetEntity: Site::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    #[Assert\NotNull(message: 'Le site est requis.')]
    #[Groups(['category:read', 'site'])]
    private ?Site $site = null;

    #[ORM\ManyToOne(targetEntity: self::class, inversedBy: 'children')]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    #[Groups([ 'category:parent'])]
    #[MaxDepth(1)]
    private ?self $parent = null;

    /**
     * @var Collection<int, Category>
     */
    #[ORM\OneToMany(targetEntity: self::class, mappedBy: 'parent')]
    #[ORM\OrderBy(['position' => 'ASC', 'name' => 'ASC'])]
    #[Groups([ 'category:children'])]
    #[MaxDepth(1)]
    private Collection $children;

    /**
     * @var Collection<int, Product>
     */
    #[ORM\ManyToMany(targetEntity: Product::class, mappedBy: 'categories')]
    private Collection $products;

    public function __construct()
    {
        $this->children = new ArrayCollection();
        $this->products = new ArrayCollection();
    }

    // ===============================================
    // GETTERS & SETTERS
    // ===============================================

    public function getId(): ?int
    {
        return $this->id;
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

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(?string $description): static
    {
        $this->description = $description;
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

    public function getImages(): ?array
    {
        return $this->images;
    }

    public function setImages(?array $images): static
    {
        $this->images = $images;
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

    public function getParent(): ?self
    {
        return $this->parent;
    }

    public function setParent(?self $parent): static
    {
        if ($parent !== null && $parent->getParent() !== null) {
            throw new \InvalidArgumentException('Maximum 2 niveaux de catégories autorisés.');
        }

        $this->parent = $parent;
        return $this;
    }

    /**
     * @return Collection<int, self>
     */
    public function getChildren(): Collection
    {
        return $this->children;
    }

    public function addChild(self $child): static
    {
        if (!$this->children->contains($child)) {
            $this->children->add($child);
            $child->setParent($this);
        }

        return $this;
    }

    public function removeChild(self $child): static
    {
        if ($this->children->removeElement($child)) {
            if ($child->getParent() === $this) {
                $child->setParent(null);
            }
        }

        return $this;
    }

    /**
     * @return Collection<int, Product>
     */
    public function getProducts(): Collection
    {
        return $this->products;
    }

    public function addProduct(Product $product): static
    {
        if (!$this->products->contains($product)) {
            $this->products->add($product);
            $product->addCategory($this);
        }

        return $this;
    }

    public function removeProduct(Product $product): static
    {
        if ($this->products->removeElement($product)) {
            $product->removeCategory($this);
        }

        return $this;
    }

    // ===============================================
    // MÉTHODES HELPER
    // ===============================================

    public function isRoot(): bool
    {
        return $this->parent === null;
    }

    public function hasChildren(): bool
    {
        return !$this->children->isEmpty();
    }

    public function getProductCount(): int
    {
        return $this->products->count();
    }

    public function getBreadcrumb(): array
    {
        $breadcrumb = [$this];
        $parent = $this->parent;

        while ($parent !== null) {
            array_unshift($breadcrumb, $parent);
            $parent = $parent->getParent();
        }

        return $breadcrumb;
    }

    public function getFullPath(): string
    {
        $breadcrumb = $this->getBreadcrumb();
        $slugs = array_map(fn($cat) => $cat->getSlug(), $breadcrumb);

        return '/' . $this->locale . '/' . implode('/', $slugs);
    }

    public function getMainImage(): ?string
    {
        if (empty($this->images)) {
            return null;
        }

        return $this->images['original'] ?? $this->images['large'] ?? null;
    }

    public function getImage(string $size = 'original'): ?string
    {
        return $this->images[$size] ?? null;
    }

    public function __toString(): string
    {
        return $this->name ?? 'Category #' . $this->id;
    }
}
