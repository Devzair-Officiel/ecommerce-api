<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Annotation\Groups;

#[ORM\Entity]
#[ORM\Index(columns: ['cart_id', 'product_id'], name: 'idx_cartitem_cart_product')]
class CartItem
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    #[Groups(['cart_item', 'cart_detail'])]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Cart::class, inversedBy: 'items')]
    #[ORM\JoinColumn(nullable: false)]
    private ?Cart $cart = null;

    #[ORM\ManyToOne(targetEntity: Product::class, inversedBy: 'cartItems')]
    #[ORM\JoinColumn(nullable: false)]
    #[Groups(['cart_item', 'cart_detail'])]
    private ?Product $product = null;

    #[ORM\ManyToOne(targetEntity: ProductVariant::class, inversedBy: 'cartItems')]
    #[Groups(['cart_item', 'cart_detail'])]
    private ?ProductVariant $variant = null;

    #[ORM\Column]
    #[Groups(['cart_item', 'cart_detail'])]
    private int $quantity = 1;

    #[ORM\Column(type: 'decimal', precision: 10, scale: 2)]
    #[Groups(['cart_item', 'cart_detail'])]
    private ?string $unitPrice = null;

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
    public function getProduct(): ?Product
    {
        return $this->product;
    }
    public function setProduct(?Product $product): static
    {
        $this->product = $product;
        return $this;
    }
    public function getVariant(): ?ProductVariant
    {
        return $this->variant;
    }
    public function setVariant(?ProductVariant $variant): static
    {
        $this->variant = $variant;
        return $this;
    }
    public function getQuantity(): int
    {
        return $this->quantity;
    }
    public function setQuantity(int $quantity): static
    {
        $this->quantity = $quantity;
        return $this;
    }
    public function getUnitPrice(): ?string
    {
        return $this->unitPrice;
    }
    public function setUnitPrice(string $unitPrice): static
    {
        $this->unitPrice = $unitPrice;
        return $this;
    }

    #[Groups(['cart_item', 'cart_detail'])]
    public function getTotalPrice(): float
    {
        return (float) $this->unitPrice * $this->quantity;
    }
}