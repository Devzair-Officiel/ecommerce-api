<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Annotation\Groups;

#[ORM\Entity]
class OrderItem
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    #[Groups(['order_item', 'order_detail'])]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Order::class, inversedBy: 'items')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Order $order = null;

    #[ORM\Column(length: 200)]
    #[Groups(['order_item', 'order_detail'])]
    private ?string $productName = null;

    #[ORM\Column(length: 50, nullable: true)]
    #[Groups(['order_item', 'order_detail'])]
    private ?string $variantName = null;

    #[ORM\Column(length: 50, nullable: true)]
    #[Groups(['order_item', 'order_detail'])]
    private ?string $sku = null;

    #[ORM\Column]
    #[Groups(['order_item', 'order_detail'])]
    private int $quantity = 1;

    // Note : Doctrine mappe DECIMAL sur string côté PHP (pour éviter les flottants)
    #[ORM\Column(type: 'decimal', precision: 10, scale: 2)]
    #[Groups(['order_item', 'order_detail'])]
    private ?string $unitPrice = null;

    #[ORM\Column(type: 'decimal', precision: 10, scale: 2)]
    #[Groups(['order_item', 'order_detail'])]
    private ?string $totalPrice = null;

    #[ORM\Column(type: 'json', nullable: true)]
    #[Groups(['order_item', 'order_detail'])]
    private array $productData = [];

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

    public function getProductName(): ?string
    {
        return $this->productName;
    }
    public function setProductName(string $productName): static
    {
        $this->productName = $productName;
        return $this;
    }

    public function getVariantName(): ?string
    {
        return $this->variantName;
    }
    public function setVariantName(?string $variantName): static
    {
        $this->variantName = $variantName;
        return $this;
    }

    public function getSku(): ?string
    {
        return $this->sku;
    }
    public function setSku(?string $sku): static
    {
        $this->sku = $sku;
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

    public function getTotalPrice(): ?string
    {
        return $this->totalPrice;
    }
    public function setTotalPrice(string $totalPrice): static
    {
        $this->totalPrice = $totalPrice;
        return $this;
    }

    public function getProductData(): array
    {
        return $this->productData;
    }
    public function setProductData(array $productData): static
    {
        $this->productData = $productData;
        return $this;
    }
}
