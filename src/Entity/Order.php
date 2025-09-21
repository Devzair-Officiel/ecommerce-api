<?php

namespace App\Entity;

use App\Traits\DateTrait;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Annotation\Groups;

#[ORM\Entity]
#[ORM\Table(name: '`orders`')]
#[ORM\HasLifecycleCallbacks]
#[ORM\Index(columns: ['site_id', 'status'], name: 'idx_order_site_status')]
#[ORM\Index(columns: ['user_id'], name: 'idx_order_user')]
class Order
{
    use DateTrait;

    public const STATUS_PENDING = 'pending';
    public const STATUS_PAID = 'paid';
    public const STATUS_PROCESSING = 'processing';
    public const STATUS_SHIPPED = 'shipped';
    public const STATUS_DELIVERED = 'delivered';
    public const STATUS_CANCELLED = 'cancelled';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    #[Groups(['order_list', 'order_detail', 'admin_read'])]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Site::class, inversedBy: 'orders')]
    #[ORM\JoinColumn(nullable: false)]
    private ?Site $site = null;

    #[ORM\ManyToOne(targetEntity: User::class, inversedBy: 'orders')]
    #[ORM\JoinColumn(nullable: false)]
    #[Groups(['order_detail', 'admin_read'])]
    private ?User $user = null;

    #[ORM\Column(length: 20, unique: true)]
    #[Groups(['order_list', 'order_detail', 'public_read'])]
    private ?string $reference = null;

    #[ORM\Column(length: 20)]
    #[Groups(['order_list', 'order_detail', 'public_read'])]
    private string $status = self::STATUS_PENDING;

    #[ORM\Column(type: 'decimal', precision: 10, scale: 2)]
    #[Groups(['order_list', 'order_detail', 'public_read'])]
    private ?string $totalPrice = null;

    #[ORM\Column(type: 'decimal', precision: 10, scale: 2)]
    #[Groups(['order_detail', 'public_read'])]
    private ?string $shippingPrice = '0.00';

    #[ORM\Column(type: 'decimal', precision: 10, scale: 2, nullable: true)]
    #[Groups(['order_detail', 'public_read'])]
    private ?string $taxAmount = null;

    #[ORM\Column(type: 'json')]
    #[Groups(['order_detail'])]
    private array $shippingAddress = [];

    #[ORM\Column(type: 'json')]
    #[Groups(['order_detail'])]
    private array $billingAddress = [];

    #[ORM\Column(length: 50, nullable: true)]
    #[Groups(['order_detail', 'admin_read'])]
    private ?string $paymentMethod = null;

    #[ORM\Column(length: 100, nullable: true)]
    #[Groups(['order_detail', 'admin_read'])]
    private ?string $paymentReference = null;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    #[Groups(['order_detail', 'admin_read'])]
    private ?\DateTimeImmutable $paidAt = null;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    #[Groups(['order_detail', 'public_read'])]
    private ?\DateTimeImmutable $shippedAt = null;

    #[ORM\Column(type: 'text', nullable: true)]
    #[Groups(['order_detail', 'admin_read'])]
    private ?string $notes = null;

    #[ORM\OneToMany(mappedBy: 'order', targetEntity: OrderItem::class, cascade: ['persist', 'remove'])]
    #[Groups(['order_detail'])]
    private Collection $items;

    public function __construct()
    {
        $this->items = new ArrayCollection();
        $this->reference = 'ORD-' . strtoupper(uniqid());
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
    public function getUser(): ?User
    {
        return $this->user;
    }
    public function setUser(?User $user): static
    {
        $this->user = $user;
        return $this;
    }
    public function getReference(): ?string
    {
        return $this->reference;
    }
    public function setReference(string $reference): static
    {
        $this->reference = $reference;
        return $this;
    }
    public function getStatus(): string
    {
        return $this->status;
    }
    public function setStatus(string $status): static
    {
        $this->status = $status;
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
    public function getShippingPrice(): ?string
    {
        return $this->shippingPrice;
    }
    public function setShippingPrice(string $shippingPrice): static
    {
        $this->shippingPrice = $shippingPrice;
        return $this;
    }
    public function getTaxAmount(): ?string
    {
        return $this->taxAmount;
    }
    public function setTaxAmount(?string $taxAmount): static
    {
        $this->taxAmount = $taxAmount;
        return $this;
    }
    public function getShippingAddress(): array
    {
        return $this->shippingAddress;
    }
    public function setShippingAddress(array $shippingAddress): static
    {
        $this->shippingAddress = $shippingAddress;
        return $this;
    }
    public function getBillingAddress(): array
    {
        return $this->billingAddress;
    }
    public function setBillingAddress(array $billingAddress): static
    {
        $this->billingAddress = $billingAddress;
        return $this;
    }
    public function getPaymentMethod(): ?string
    {
        return $this->paymentMethod;
    }
    public function setPaymentMethod(?string $paymentMethod): static
    {
        $this->paymentMethod = $paymentMethod;
        return $this;
    }
    public function getPaymentReference(): ?string
    {
        return $this->paymentReference;
    }
    public function setPaymentReference(?string $paymentReference): static
    {
        $this->paymentReference = $paymentReference;
        return $this;
    }
    public function getPaidAt(): ?\DateTimeImmutable
    {
        return $this->paidAt;
    }
    public function setPaidAt(?\DateTimeImmutable $paidAt): static
    {
        $this->paidAt = $paidAt;
        return $this;
    }
    public function getShippedAt(): ?\DateTimeImmutable
    {
        return $this->shippedAt;
    }
    public function setShippedAt(?\DateTimeImmutable $shippedAt): static
    {
        $this->shippedAt = $shippedAt;
        return $this;
    }
    public function getNotes(): ?string
    {
        return $this->notes;
    }
    public function setNotes(?string $notes): static
    {
        $this->notes = $notes;
        return $this;
    }
    public function getItems(): Collection
    {
        return $this->items;
    }

    public function addItem(OrderItem $item): static
    {
        if (!$this->items->contains($item)) {
            $this->items->add($item);
            $item->setOrder($this);
        }
        return $this;
    }

    public function removeItem(OrderItem $item): static
    {
        if ($this->items->removeElement($item)) {
            if ($item->getOrder() === $this) {
                $item->setOrder(null);
            }
        }
        return $this;
    }
}