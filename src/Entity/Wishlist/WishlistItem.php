<?php

declare(strict_types=1);

namespace App\Entity\Wishlist;

use App\Entity\Product\Product;
use App\Entity\Product\ProductVariant;
use App\Repository\Wishlist\WishlistItemRepository;
use App\Traits\DateTrait;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Annotation\Groups;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Item d'une wishlist.
 * 
 * Représente un produit ajouté à une liste de souhaits.
 * 
 * Différence avec CartItem :
 * - Pas de quantité précise (juste "je veux ce produit")
 * - Pas de snapshot prix (prix actuel toujours affiché)
 * - Note personnelle possible
 * - Priorité (souhaité, très souhaité, indispensable)
 */
#[ORM\Entity(repositoryClass: WishlistItemRepository::class)]
#[ORM\Index(columns: ['wishlist_id'], name: 'idx_wishlist_item_wishlist')]
#[ORM\Index(columns: ['variant_id'], name: 'idx_wishlist_item_variant')]
#[ORM\HasLifecycleCallbacks]
class WishlistItem
{
    use DateTrait;

    public const PRIORITY_LOW = 1;
    public const PRIORITY_MEDIUM = 2;
    public const PRIORITY_HIGH = 3;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::INTEGER)]
    #[Groups(['wishlist:read', 'wishlist:items'])]
    private ?int $id = null;

    /**
     * Wishlist parente.
     */
    #[ORM\ManyToOne(targetEntity: Wishlist::class, inversedBy: 'items')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    #[Assert\NotNull]
    private ?Wishlist $wishlist = null;

    /**
     * Variante du produit souhaitée.
     */
    #[ORM\ManyToOne(targetEntity: ProductVariant::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    #[Groups(['wishlist:read', 'wishlist:items'])]
    private ?ProductVariant $variant = null;

    /**
     * Produit parent (référence).
     */
    #[ORM\ManyToOne(targetEntity: Product::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    #[Groups(['wishlist:read', 'wishlist:items'])]
    private ?Product $product = null;

    /**
     * Priorité (1: faible, 2: moyenne, 3: haute).
     */
    #[ORM\Column(type: Types::SMALLINT, options: ['default' => 2])]
    #[Assert\Range(min: 1, max: 3)]
    #[Groups(['wishlist:read', 'wishlist:items', 'wishlist:write'])]
    private int $priority = self::PRIORITY_MEDIUM;

    /**
     * Note personnelle sur l'item.
     * 
     * Ex: "Pour mon anniversaire", "Taille M préférée"
     */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    #[Assert\Length(max: 500)]
    #[Groups(['wishlist:read', 'wishlist:items', 'wishlist:write'])]
    private ?string $note = null;

    /**
     * Quantité souhaitée (optionnel).
     * 
     * Par défaut 1. Peut être augmenté si plusieurs items identiques souhaités.
     */
    #[ORM\Column(type: Types::INTEGER, options: ['default' => 1])]
    #[Assert\Positive]
    #[Assert\Range(min: 1, max: 999)]
    #[Groups(['wishlist:read', 'wishlist:items', 'wishlist:write'])]
    private int $quantity = 1;

    // ===============================================
    // GETTERS & SETTERS
    // ===============================================

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getWishlist(): ?Wishlist
    {
        return $this->wishlist;
    }

    public function setWishlist(?Wishlist $wishlist): static
    {
        $this->wishlist = $wishlist;
        return $this;
    }

    public function getVariant(): ?ProductVariant
    {
        return $this->variant;
    }

    public function setVariant(?ProductVariant $variant): static
    {
        $this->variant = $variant;

        if ($variant) {
            $this->product = $variant->getProduct();
        }

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

    public function getPriority(): int
    {
        return $this->priority;
    }

    public function setPriority(int $priority): static
    {
        $this->priority = $priority;
        return $this;
    }

    public function getNote(): ?string
    {
        return $this->note;
    }

    public function setNote(?string $note): static
    {
        $this->note = $note;
        return $this;
    }

    public function getQuantity(): int
    {
        return $this->quantity;
    }

    public function setQuantity(int $quantity): static
    {
        $this->quantity = max(1, $quantity);
        return $this;
    }

    // ===============================================
    // HELPERS MÉTIER
    // ===============================================

    /**
     * Récupère le prix actuel de la variante.
     * 
     * Différence avec CartItem :
     * - Pas de snapshot, toujours prix actuel
     * - Peut être null si variante supprimée
     */
    public function getCurrentPrice(): ?float
    {
        if (!$this->variant) {
            return null;
        }

        // Prix B2C par défaut (wishlist = utilisateurs finaux)
        return $this->variant->getPriceFor('EUR', 'B2C', 1);
    }

    /**
     * Vérifie si la variante est toujours disponible.
     */
    public function isAvailable(): bool
    {
        if (!$this->variant) {
            return false;
        }

        return $this->variant->isActive()
            && !$this->variant->isDeleted()
            && $this->variant->isInStock();
    }

    /**
     * Récupère le nom complet pour affichage.
     */
    public function getDisplayName(): string
    {
        if ($this->variant) {
            return $this->variant->getFullName();
        }

        return $this->product?->getName() ?? 'Produit indisponible';
    }

    /**
     * Récupère l'image pour affichage.
     */
    public function getDisplayImage(): ?string
    {
        if ($this->variant) {
            return $this->variant->getFinalImage();
        }

        return $this->product?->getMainImage();
    }

    /**
     * Récupère le label de priorité.
     */
    public function getPriorityLabel(): string
    {
        return match ($this->priority) {
            self::PRIORITY_LOW => 'Souhaité',
            self::PRIORITY_MEDIUM => 'Très souhaité',
            self::PRIORITY_HIGH => 'Indispensable',
            default => 'Souhaité'
        };
    }

    // ===============================================
    // FORMATAGE
    // ===============================================

    /**
     * Résumé de l'item pour affichage.
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->getDisplayName(),
            'image' => $this->getDisplayImage(),
            'price' => $this->getCurrentPrice(),
            'quantity' => $this->quantity,
            'priority' => $this->priority,
            'priority_label' => $this->getPriorityLabel(),
            'note' => $this->note,
            'is_available' => $this->isAvailable(),
            'variant_id' => $this->variant?->getId(),
            'product_id' => $this->product?->getId(),
        ];
    }

    public function __toString(): string
    {
        return sprintf('%s (priorité: %s)', $this->getDisplayName(), $this->getPriorityLabel());
    }
}
