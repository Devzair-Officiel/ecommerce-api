<?php

namespace App\Entity;

use App\Traits\DateTrait;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Annotation\Groups;

#[ORM\Entity]
#[ORM\HasLifecycleCallbacks]
#[ORM\Index(columns: ['subdomain'], name: 'idx_site_subdomain')]
#[ORM\Index(columns: ['is_active'], name: 'idx_site_active')]
class Site
{
    use DateTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    #[Groups(['site_list', 'site_detail', 'admin_read'])]
    private ?int $id = null;

    #[ORM\Column(length: 100)]
    #[Groups(['site_list', 'site_detail', 'public_read'])]
    private ?string $name = null;

    #[ORM\Column(length: 50, unique: true)]
    #[Groups(['site_list', 'site_detail', 'admin_read'])]
    private ?string $subdomain = null;

    #[ORM\Column(length: 255, nullable: true)]
    #[Groups(['site_detail', 'public_read'])]
    private ?string $domain = null;

    #[ORM\Column]
    #[Groups(['site_list', 'admin_read'])]
    private bool $isActive = true;

    #[ORM\Column(type: 'json')]
    #[Groups(['site_detail', 'admin_read'])]
    private array $settings = [];

    #[ORM\OneToMany(mappedBy: 'site', targetEntity: Product::class)]
    private Collection $products;

    #[ORM\OneToMany(mappedBy: 'site', targetEntity: Category::class)]
    private Collection $categories;

    #[ORM\OneToMany(mappedBy: 'site', targetEntity: Order::class)]
    private Collection $orders;

    #[ORM\OneToOne(mappedBy: 'site', targetEntity: Theme::class, cascade: ['persist'])]
    #[Groups(['site_detail'])]
    private ?Theme $theme = null;

    public function __construct()
    {
        $this->products = new ArrayCollection();
        $this->categories = new ArrayCollection();
        $this->orders = new ArrayCollection();
    }

    // Getters/Setters...
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
    public function getSubdomain(): ?string
    {
        return $this->subdomain;
    }
    public function setSubdomain(string $subdomain): static
    {
        $this->subdomain = $subdomain;
        return $this;
    }
    public function getDomain(): ?string
    {
        return $this->domain;
    }
    public function setDomain(?string $domain): static
    {
        $this->domain = $domain;
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

    #[Groups(['product_list', 'product_detail'])]
    public function getMainImage(): ?string
    {
        return $this->images[0] ?? null;
    }

    #[Groups(['product_detail'])]
    public function isOnSale(): bool
    {
        return $this->originalPrice && $this->originalPrice > $this->price;
    }

    #[Groups(['product_detail'])]
    public function isInStock(): bool
    {
        return $this->stock > 0;
    }

    public function getCategories(): Collection
    {
        return $this->categories;
    }
    public function getVariants(): Collection
    {
        return $this->variants;
    }
    public function getCartItems(): Collection
    {
        return $this->cartItems;
    }
}