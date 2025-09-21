<?php

namespace App\Entity;

use App\Traits\DateTrait;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Annotation\Groups;

#[ORM\Entity]
#[ORM\HasLifecycleCallbacks]
#[ORM\Index(columns: ['site_id', 'slug'], name: 'idx_category_site_slug')]
#[ORM\Index(columns: ['parent_id'], name: 'idx_category_parent')]
class Category
{
    use DateTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    #[Groups(['category_list', 'category_detail', 'product_detail'])]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Site::class, inversedBy: 'categories')]
    #[ORM\JoinColumn(nullable: false)]
    private ?Site $site = null;

    #[ORM\Column(length: 100)]
    #[Groups(['category_list', 'category_detail', 'product_detail', 'public_read'])]
    private ?string $name = null;

    #[ORM\Column(length: 120, unique: true)]
    #[Groups(['category_list', 'category_detail', 'public_read'])]
    private ?string $slug = null;

    #[ORM\Column(type: 'text', nullable: true)]
    #[Groups(['category_detail', 'public_read'])]
    private ?string $description = null;

    #[ORM\Column(length: 255, nullable: true)]
    #[Groups(['category_detail', 'public_read'])]
    private ?string $image = null;

    #[ORM\ManyToOne(targetEntity: self::class, inversedBy: 'children')]
    #[Groups(['category_detail'])]
    private ?self $parent = null;

    #[ORM\OneToMany(mappedBy: 'parent', targetEntity: self::class)]
    #[Groups(['category_detail'])]
    private Collection $children;

    #[ORM\Column]
    #[Groups(['category_list', 'admin_read'])]
    private int $position = 0;

    #[ORM\Column]
    #[Groups(['category_list', 'admin_read'])]
    private bool $isActive = true;

    #[ORM\ManyToMany(targetEntity: Product::class, mappedBy: 'categories')]
    private Collection $products;

    public function __construct()
    {
        $this->children = new ArrayCollection();
        $this->products = new ArrayCollection();
    }

    // Getters/Setters...
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
    public function getDescription(): ?string
    {
        return $this->description;
    }
    public function setDescription(?string $description): static
    {
        $this->description = $description;
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
    public function getParent(): ?self
    {
        return $this->parent;
    }
    public function setParent(?self $parent): static
    {
        $this->parent = $parent;
        return $this;
    }
    public function getChildren(): Collection
    {
        return $this->children;
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
    public function isActive(): bool
    {
        return $this->isActive;
    }
    public function setIsActive(bool $isActive): static
    {
        $this->isActive = $isActive;
        return $this;
    }
    public function getProducts(): Collection
    {
        return $this->products;
    }
}