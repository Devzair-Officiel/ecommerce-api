<?php

declare(strict_types=1);

namespace App\Entity\Wishlist;

use App\Entity\Site\Site;
use App\Entity\User\User;
use App\Repository\Wishlist\WishlistRepository;
use App\Traits\DateTrait;
use App\Traits\SoftDeletableTrait;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Annotation\Groups;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Liste de souhaits utilisateur (wishlist).
 * 
 * Fonctionnalités :
 * - Enregistrer produits pour achat ultérieur
 * - Plusieurs wishlists par utilisateur (ex: "Noël", "Anniversaire")
 * - Partage public via token (cadeaux)
 * - Conversion en panier en un clic
 * 
 * Différences avec Cart :
 * - Wishlist : Sauvegarde long terme, pas de quantité précise
 * - Cart : Temporaire, quantités définies, checkout imminent
 */
#[ORM\Entity(repositoryClass: WishlistRepository::class)]
#[ORM\Index(columns: ['user_id'], name: 'idx_wishlist_user')]
#[ORM\Index(columns: ['share_token'], name: 'idx_wishlist_share_token')]
#[ORM\HasLifecycleCallbacks]
class Wishlist
{
    use DateTrait;
    use SoftDeletableTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::INTEGER)]
    #[Groups(['wishlist:read'])]
    private ?int $id = null;

    /**
     * Nom de la wishlist (ex: "Ma wishlist", "Noël 2024").
     */
    #[ORM\Column(type: Types::STRING, length: 100)]
    #[Assert\NotBlank(message: 'Le nom de la wishlist est requis.')]
    #[Assert\Length(max: 100)]
    #[Groups(['wishlist:read', 'wishlist:write'])]
    private string $name = 'Ma wishlist';

    /**
     * Description optionnelle.
     */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    #[Assert\Length(max: 500)]
    #[Groups(['wishlist:read', 'wishlist:write'])]
    private ?string $description = null;

    /**
     * Propriétaire de la wishlist.
     */
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    #[Assert\NotNull]
    #[Groups(['wishlist:read:admin'])]
    private ?User $user = null;

    /**
     * Site concerné (multi-tenant).
     */
    #[ORM\ManyToOne(targetEntity: Site::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    #[Assert\NotNull]
    #[Groups(['wishlist:read'])]
    private ?Site $site = null;

    /**
     * Wishlist par défaut de l'utilisateur.
     * 
     * Chaque user a une wishlist par défaut.
     * Ajout rapide sans choisir de wishlist spécifique.
     */
    #[ORM\Column(type: Types::BOOLEAN, options: ['default' => true])]
    #[Groups(['wishlist:read', 'wishlist:write'])]
    private bool $isDefault = true;

    /**
     * Wishlist publique (partageable).
     * 
     * Si true, accessible via shareToken.
     */
    #[ORM\Column(type: Types::BOOLEAN, options: ['default' => false])]
    #[Groups(['wishlist:read', 'wishlist:write'])]
    private bool $isPublic = false;

    /**
     * Token de partage (UUID).
     * 
     * Permet de partager la wishlist sans compte.
     * Ex: https://monsite.com/wishlist/550e8400-...
     * 
     * Généré automatiquement si isPublic = true.
     */
    #[ORM\Column(type: Types::STRING, length: 36, nullable: true, unique: true)]
    #[Groups(['wishlist:read'])]
    private ?string $shareToken = null;

    // ===============================================
    // RELATIONS
    // ===============================================

    /**
     * @var Collection<int, WishlistItem>
     */
    #[ORM\OneToMany(targetEntity: WishlistItem::class, mappedBy: 'wishlist', cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['createdAt' => 'DESC'])]
    #[Groups(['wishlist:read', 'wishlist:items'])]
    private Collection $items;

    // ===============================================
    // CONSTRUCTEUR
    // ===============================================

    public function __construct()
    {
        $this->items = new ArrayCollection();
    }

    // ===============================================
    // GETTERS & SETTERS
    // ===============================================

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): static
    {
        $this->name = $name;
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

    public function getUser(): ?User
    {
        return $this->user;
    }

    public function setUser(?User $user): static
    {
        $this->user = $user;
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

    public function isDefault(): bool
    {
        return $this->isDefault;
    }

    public function setIsDefault(bool $isDefault): static
    {
        $this->isDefault = $isDefault;
        return $this;
    }

    public function isPublic(): bool
    {
        return $this->isPublic;
    }

    public function setIsPublic(bool $isPublic): static
    {
        $this->isPublic = $isPublic;

        // Générer shareToken si public
        if ($isPublic && $this->shareToken === null) {
            $this->generateShareToken();
        }

        return $this;
    }

    public function getShareToken(): ?string
    {
        return $this->shareToken;
    }

    public function setShareToken(?string $shareToken): static
    {
        $this->shareToken = $shareToken;
        return $this;
    }

    /**
     * @return Collection<int, WishlistItem>
     */
    public function getItems(): Collection
    {
        return $this->items;
    }

    public function addItem(WishlistItem $item): static
    {
        if (!$this->items->contains($item)) {
            $this->items->add($item);
            $item->setWishlist($this);
        }

        return $this;
    }

    public function removeItem(WishlistItem $item): static
    {
        if ($this->items->removeElement($item)) {
            if ($item->getWishlist() === $this) {
                $item->setWishlist(null);
            }
        }

        return $this;
    }

    // ===============================================
    // HELPERS MÉTIER
    // ===============================================

    /**
     * Vérifie si une variante est dans la wishlist.
     */
    public function hasVariant(int $variantId): bool
    {
        foreach ($this->items as $item) {
            if ($item->getVariant()?->getId() === $variantId) {
                return true;
            }
        }

        return false;
    }

    /**
     * Trouve un item par variante.
     */
    public function findItemByVariant(int $variantId): ?WishlistItem
    {
        foreach ($this->items as $item) {
            if ($item->getVariant()?->getId() === $variantId) {
                return $item;
            }
        }

        return null;
    }

    /**
     * Compte le nombre d'items.
     */
    public function getItemsCount(): int
    {
        return $this->items->count();
    }

    /**
     * Vérifie si la wishlist est vide.
     */
    public function isEmpty(): bool
    {
        return $this->items->isEmpty();
    }

    /**
     * Vide la wishlist.
     */
    public function clear(): static
    {
        $this->items->clear();
        return $this;
    }

    /**
     * Calcule le montant total de la wishlist.
     * 
     * Somme des prix de tous les items (quantité 1 par défaut).
     */
    public function getTotalValue(): float
    {
        return array_reduce(
            $this->items->toArray(),
            fn(float $sum, WishlistItem $item) => $sum + ($item->getCurrentPrice() ?? 0),
            0.0
        );
    }

    /**
     * Génère un token de partage unique.
     */
    public function generateShareToken(): static
    {
        $data = random_bytes(16);
        $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
        $data[8] = chr(ord($data[8]) & 0x3f | 0x80);

        $this->shareToken = vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));

        return $this;
    }

    /**
     * Récupère l'URL de partage.
     */
    public function getShareUrl(string $baseUrl): ?string
    {
        if (!$this->isPublic || !$this->shareToken) {
            return null;
        }

        return rtrim($baseUrl, '/') . '/wishlist/' . $this->shareToken;
    }

    // ===============================================
    // FORMATAGE
    // ===============================================

    /**
     * Résumé de la wishlist.
     */
    public function getSummary(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'items_count' => $this->getItemsCount(),
            'total_value' => $this->getTotalValue(),
            'is_default' => $this->isDefault,
            'is_public' => $this->isPublic,
            'share_url' => $this->isPublic ? "/wishlist/{$this->shareToken}" : null,
        ];
    }

    public function __toString(): string
    {
        return sprintf('%s (%d items)', $this->name, $this->getItemsCount());
    }
}
