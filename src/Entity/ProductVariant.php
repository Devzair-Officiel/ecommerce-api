<?php

namespace App\Entity;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Annotation\Groups;

#[ORM\Entity]
#[ORM\Index(columns: ['product_id', 'sku'], name: 'idx_variant_product_sku')]
class ProductVariant
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    #[Groups(['variant_list', 'variant_detail', 'product_detail', 'cart_item'])]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Product::class, inversedBy: 'variants')]
    #[ORM\JoinColumn(nullable: false)]
    private ?Product $product = null;

    #[ORM\Column(length: 50)]
    #[Groups(['variant_list', 'variant_detail', 'product_detail', 'cart_item', 'public_read'])]
    private ?string $name = null; // 250ml, 500ml, 1L

    #[ORM\Column(length: 50, unique: true)]
    #[Groups(['variant_detail', 'admin_read'])]
    private ?string $sku = null;

    #[ORM\Column(type: 'decimal', precision: 10, scale: 2)]
    #[Groups(['variant_list', 'variant_detail', 'product_detail', 'cart_item', 'public_read'])]
    private ?string $price = null;

    #[ORM\Column]
    #[Groups(['variant_detail', 'admin_read'])]
    private int $stock = 0;

    #[ORM\Column(type: 'decimal', precision: 8, scale: 3, nullable: true)]
    #[Groups(['variant_detail', 'public_read'])]
    private ?string $weight = null;

    #[ORM\Column]
    #[Groups(['variant_list', 'admin_read'])]
    private bool $isActive = true;

    #[ORM\Column]
    #[Groups(['variant_list', 'admin_read'])]
    private int $position = 0;

    #[ORM\OneToMany(mappedBy: 'variant', targetEntity: CartItem::class)]
    private Collection $cartItems;

    public function __construct()
    {
        $this->cartItems = new ArrayCollection();
    }

    // Getters/Setters...
    public function getId(): ?int
    {
        return $this->id;
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
    public function getName(): ?string
    {
        return $this->name;
    }
    public function setName(string $name): static
    {
        $this->name = $name;
        return $this;
    }
    public function getSku(): ?string
    {
        return $this->sku;
    }
    public function setSku(string $sku): static
    {
        $this->sku = $sku;
        return $this;
    }
    public function getPrice(): ?string
    {
        return $this->price;
    }
    public function setPrice(string $price): static
    {
        $this->price = $price;
        return $this;
    }
    public function getStock(): int
    {
        return $this->stock;
    }
    public function setStock(int $stock): static
    {
        $this->stock = $stock;
        return $this;
    }
    public function getWeight(): ?string
    {
        return $this->weight;
    }
    public function setWeight(?string $weight): static
    {
        $this->weight = $weight;
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
    public function getPosition(): int
    {
        return $this->position;
    }
    public function setPosition(int $position): static
    {
        $this->position = $position;
        return $this;
    }

    #[Groups(['variant_detail', 'product_detail'])]
    public function isInStock(): bool
    {
        return $this->stock > 0;
    }

    public function getCartItems(): Collection
    {
        return $this->cartItems;
    }
}