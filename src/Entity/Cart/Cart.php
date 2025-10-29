<?php

declare(strict_types=1);

namespace App\Entity\Cart;

use App\Entity\Site\Site;
use App\Entity\User\User;
use App\Entity\Cart\Coupon;
use App\Repository\Cart\CartRepository;
use App\Traits\DateTrait;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Annotation\Groups;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Uid\Uuid;

/**
 * Panier utilisateur persistant (multi-device).
 * 
 * Responsabilités :
 * - Stockage persistant des articles en attente d'achat
 * - Support invités via JWT (sessionToken)
 * - Multi-devise et multi-site
 * - Expiration automatique après inactivité
 * - Calcul totaux (HT, TTC, économies)
 * 
 * Workflow invité → utilisateur :
 * 1. Invité : JWT contient sessionToken → Cart.sessionToken
 * 2. Inscription : Fusion panier invité dans User
 * 3. Login : Récupération panier existant
 * 
 * Expiration :
 * - Invité : 7 jours d'inactivité
 * - Utilisateur : 30 jours d'inactivité
 */
#[ORM\Entity(repositoryClass: CartRepository::class)]
#[ORM\Index(columns: ['session_token'], name: 'idx_cart_session_token')]
#[ORM\Index(columns: ['user_id'], name: 'idx_cart_user')]
#[ORM\Index(columns: ['expires_at'], name: 'idx_cart_expires_at')]
#[ORM\HasLifecycleCallbacks]
class Cart
{
    use DateTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::INTEGER)]
    #[Groups(['cart:read'])]
    private ?int $id = null;

    /**
     * Token de session pour invités (UUID généré côté API).
     * 
     * Stocké dans JWT payload :
     * {
     *   "sub": "guest",
     *   "cart_token": "550e8400-e29b-41d4-a716-446655440000",
     *   "exp": 1234567890
     * }
     * 
     * Permet de retrouver le panier invité entre requêtes.
     * Devient null après association à un User.
     */
    #[ORM\Column(type: Types::STRING, length: 36, nullable: true, unique: true)]
    #[Groups(['cart:read'])]
    private ?string $sessionToken = null;

    /**
     * Utilisateur propriétaire (null pour invités).
     * 
     * OneToOne : Un utilisateur a UN SEUL panier actif.
     * Nullable : Permet paniers invités.
     */
    #[ORM\OneToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'CASCADE')]
    #[Groups(['cart:read:admin'])]
    private ?User $user = null;

    /**
     * Site concerné (multi-tenant).
     * 
     * Un panier appartient à UN site.
     * Important pour prix, stock et devise.
     */
    #[ORM\ManyToOne(targetEntity: Site::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    #[Assert\NotNull(message: 'Le site est requis.')]
    #[Groups(['cart:read'])]
    private ?Site $site = null;

    /**
     * Devise du panier (EUR, USD, GBP...).
     * 
     * Définie à la création, reste fixe pendant toute la session.
     * Les prix des items doivent correspondre à cette devise.
     */
    #[ORM\Column(type: Types::STRING, length: 3)]
    #[Assert\NotBlank(message: 'La devise est obligatoire.')]
    #[Assert\Currency]
    #[Groups(['cart:read', 'cart:write'])]
    private string $currency = 'EUR';

    /**
     * Type de client (B2C, B2B).
     * 
     * Détermine les prix applicables.
     * Défini lors de la création (JWT ou User.roles).
     */
    #[ORM\Column(type: Types::STRING, length: 3)]
    #[Assert\Choice(choices: ['B2C', 'B2B'], message: 'Type client invalide.')]
    #[Groups(['cart:read', 'cart:write'])]
    private string $customerType = 'B2C';

    /**
     * Langue du panier (fr, en, es...).
     * 
     * Pour affichage des noms de produits.
     */
    #[ORM\Column(type: Types::STRING, length: 5)]
    #[Assert\NotBlank]
    #[Assert\Choice(choices: ['fr', 'en', 'es'], message: 'Langue non supportée.')]
    #[Groups(['cart:read', 'cart:write'])]
    private string $locale = 'fr';

    /**
     * Code promo appliqué.
     */
    #[ORM\ManyToOne(targetEntity: Coupon::class, inversedBy: 'carts')]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    #[Groups(['cart:read'])]
    private ?Coupon $coupon = null;

    /**
     * Date d'expiration du panier.
     * 
     * Calculée automatiquement :
     * - Invité : createdAt + 7 jours
     * - Utilisateur : createdAt + 30 jours
     * 
     * Mise à jour à chaque modification (ajout/suppression item).
     */
    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    #[Groups(['cart:read'])]
    private ?\DateTimeImmutable $expiresAt = null;

    /**
     * Date de dernière activité (ajout/suppression/update).
     * 
     * Utilisé pour calcul d'expiration et analytics.
     */
    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    #[Groups(['cart:read'])]
    private ?\DateTimeImmutable $lastActivityAt = null;

    /**
     * Métadonnées additionnelles (JSON).
     * 
     * Exemples :
     * - utm_source, utm_campaign (tracking)
     * - device_type (mobile/desktop)
     * - ip_address (géolocalisation)
     */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    #[Groups(['cart:read:admin'])]
    private ?array $metadata = null;

    // ===============================================
    // RELATIONS
    // ===============================================

    /**
     * @var Collection<int, CartItem>
     */
    #[ORM\OneToMany(targetEntity: CartItem::class, mappedBy: 'cart', cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['createdAt' => 'ASC'])]
    #[Groups(['cart:read', 'cart:items'])]
    private Collection $items;

    // ===============================================
    // CONSTRUCTEUR
    // ===============================================

    public function __construct()
    {
        $this->items = new ArrayCollection();
        $this->lastActivityAt = new \DateTimeImmutable();
    }

    // ===============================================
    // GETTERS & SETTERS
    // ===============================================

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getSessionToken(): ?string
    {
        return $this->sessionToken;
    }

    public function setSessionToken(?string $sessionToken): static
    {
        $this->sessionToken = $sessionToken;
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

    public function getCurrency(): string
    {
        return $this->currency;
    }

    public function setCurrency(string $currency): static
    {
        $this->currency = strtoupper($currency);
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

    public function getLocale(): string
    {
        return $this->locale;
    }

    public function setLocale(string $locale): static
    {
        $this->locale = $locale;
        return $this;
    }

    public function getExpiresAt(): ?\DateTimeImmutable
    {
        return $this->expiresAt;
    }

    public function setExpiresAt(\DateTimeImmutable $expiresAt): static
    {
        $this->expiresAt = $expiresAt;
        return $this;
    }

    public function getLastActivityAt(): ?\DateTimeImmutable
    {
        return $this->lastActivityAt;
    }

    public function setLastActivityAt(\DateTimeImmutable $lastActivityAt): static
    {
        $this->lastActivityAt = $lastActivityAt;
        return $this;
    }

    public function getMetadata(): ?array
    {
        return $this->metadata;
    }

    public function setMetadata(?array $metadata): static
    {
        $this->metadata = $metadata;
        return $this;
    }

    public function getCoupon(): ?Coupon
    {
        return $this->coupon;
    }

    public function setCoupon(?Coupon $coupon): static
    {
        $this->coupon = $coupon;
        return $this;
    }

    /**
     * @return Collection<int, CartItem>
     */
    public function getItems(): Collection
    {
        return $this->items;
    }

    public function addItem(CartItem $item): static
    {
        if (!$this->items->contains($item)) {
            $this->items->add($item);
            $item->setCart($this);
        }

        $this->touchActivity();
        return $this;
    }

    public function removeItem(CartItem $item): static
    {
        if ($this->items->removeElement($item)) {
            if ($item->getCart() === $this) {
                $item->setCart(null);
            }
        }

        $this->touchActivity();
        return $this;
    }

    // ===============================================
    // HELPERS MÉTIER - GESTION ITEMS
    // ===============================================

    /**
     * Trouve un item par variante.
     * 
     * @param int $variantId ID de la ProductVariant
     * @return CartItem|null
     */
    public function findItemByVariant(int $variantId): ?CartItem
    {
        foreach ($this->items as $item) {
            if ($item->getVariant()?->getId() === $variantId) {
                return $item;
            }
        }

        return null;
    }

    /**
     * Vérifie si une variante est déjà dans le panier.
     */
    public function hasVariant(int $variantId): bool
    {
        return $this->findItemByVariant($variantId) !== null;
    }

    /**
     * Compte le nombre total d'articles (somme des quantités).
     * 
     * Exemple : 2x Miel 250g + 1x Miel 500g = 3 articles
     */
    public function getTotalItemsCount(): int
    {
        return array_reduce(
            $this->items->toArray(),
            fn(int $sum, CartItem $item) => $sum + $item->getQuantity(),
            0
        );
    }

    /**
     * Compte le nombre de lignes distinctes.
     * 
     * Exemple : 2x Miel 250g + 1x Miel 500g = 2 lignes
     */
    public function getTotalLinesCount(): int
    {
        return $this->items->count();
    }

    /**
     * Vérifie si le panier est vide.
     */
    public function isEmpty(): bool
    {
        return $this->items->isEmpty();
    }

    /**
     * Vide complètement le panier.
     */
    public function clear(): static
    {
        $this->items->clear();
        $this->touchActivity();
        return $this;
    }

    // ===============================================
    // HELPERS MÉTIER - CALCULS PRIX
    // ===============================================

    /**
     * Calcule le montant total HT du panier.
     * 
     * Somme des (prix unitaire × quantité) pour tous les items.
     * Ne prend PAS en compte les frais de port ni les réductions.
     */
    public function getSubtotal(): float
    {
        return array_reduce(
            $this->items->toArray(),
            fn(float $sum, CartItem $item) => $sum + $item->getLineTotal(),
            0.0
        );
    }

    /**
     * Calcule la remise totale appliquée (coupons).
     * 
     * Utilise le coupon associé pour calculer la réduction.
     */
    public function getDiscountAmount(): float
    {
        if ($this->coupon === null || !$this->coupon->isValid()) {
            return 0.0;
        }

        return $this->coupon->calculateDiscount($this->getSubtotal());
    }

    /**
     * Calcule le montant total après réductions (avant frais de port).
     */
    public function getTotalAfterDiscount(): float
    {
        return max(0, $this->getSubtotal() - $this->getDiscountAmount());
    }

    /**
     * Calcule les frais de port.
     * 
     * Règles :
     * - Si coupon free_shipping : 0€
     * - Si montant >= 50€ : gratuit
     * - Sinon : 5.90€
     * 
     * TODO: Implémenter calcul réel après création de ShippingMethod.
     */
    public function getShippingCost(): float
    {
        // Coupon livraison gratuite
        if ($this->coupon && $this->coupon->offersFreeShipping()) {
            return 0.0;
        }

        // Livraison gratuite au-dessus de 50€
        $subtotal = $this->getTotalAfterDiscount();
        return $subtotal >= 50 ? 0.0 : 5.90;
    }

    /**
     * Calcule le montant total TTC final (après réductions + port).
     */
    public function getGrandTotal(): float
    {
        return $this->getTotalAfterDiscount() + $this->getShippingCost();
    }

    /**
     * Calcule le poids total du panier (en grammes).
     * 
     * Utilisé pour calcul des frais de port.
     */
    public function getTotalWeight(): int
    {
        return array_reduce(
            $this->items->toArray(),
            fn(int $sum, CartItem $item) => $sum + ($item->getTotalWeight() ?? 0),
            0
        );
    }

    /**
     * Calcule l'économie totale réalisée (tarifs dégressifs).
     */
    public function getTotalSavings(): float
    {
        return array_reduce(
            $this->items->toArray(),
            fn(float $sum, CartItem $item) => $sum + ($item->getSavings() ?? 0),
            0.0
        );
    }

    // ===============================================
    // HELPERS MÉTIER - EXPIRATION
    // ===============================================

    /**
     * Vérifie si le panier est expiré.
     */
    public function isExpired(): bool
    {
        if ($this->expiresAt === null) {
            return false;
        }

        return $this->expiresAt < new \DateTimeImmutable();
    }

    /**
     * Vérifie si le panier est un panier invité.
     */
    public function isGuestCart(): bool
    {
        return $this->user === null && $this->sessionToken !== null;
    }

    /**
     * Vérifie si le panier appartient à un utilisateur connecté.
     */
    public function isUserCart(): bool
    {
        return $this->user !== null;
    }

    /**
     * Met à jour la date d'activité et recalcule l'expiration.
     * 
     * Appelé automatiquement à chaque modification du panier.
     */
    public function touchActivity(): static
    {
        $this->lastActivityAt = new \DateTimeImmutable();
        $this->recalculateExpiration();
        return $this;
    }

    /**
     * Recalcule la date d'expiration selon le type de panier.
     * 
     * Règles :
     * - Invité : +7 jours depuis dernière activité
     * - Utilisateur : +30 jours depuis dernière activité
     */
    public function recalculateExpiration(): static
    {
        $days = $this->isGuestCart() ? 7 : 30;
        $this->expiresAt = (new \DateTimeImmutable())->modify("+{$days} days");
        return $this;
    }

    /**
     * Associe le panier à un utilisateur (lors de l'inscription/connexion).
     * 
     * Workflow :
     * 1. Utilisateur invité crée un panier (sessionToken)
     * 2. Utilisateur s'inscrit
     * 3. On associe le panier à son compte via cette méthode
     * 4. sessionToken devient null
     * 5. Expiration passe à +30 jours
     */
    public function attachToUser(User $user): static
    {
        $this->user = $user;
        $this->sessionToken = null; // Plus besoin du token
        $this->recalculateExpiration(); // Prolonge à 30 jours
        return $this;
    }

    /**
     * Génère et définit un token de session unique pour panier invité.
     * 
     * Appelé lors de la création d'un panier invité.
     * Génère un UUID v4 au format RFC 4122 (36 caractères).
     * 
     * @return string Le token généré
     */
    public function generateSessionToken(): string
    {
        $this->sessionToken = Uuid::v4()->toRfc4122();
        return $this->sessionToken;
    }

    // ===============================================
    // LIFECYCLE CALLBACKS
    // ===============================================

    /**
     * Initialise l'expiration à la création.
     */
    #[ORM\PrePersist]
    public function initializeExpiration(): void
    {
        if ($this->expiresAt === null) {
            $this->recalculateExpiration();
        }
    }

    // ===============================================
    // HELPERS FORMATAGE
    // ===============================================

    /**
     * Résumé du panier pour affichage.
     */
    public function getSummary(): array
    {
        return [
            'items_count' => $this->getTotalItemsCount(),
            'lines_count' => $this->getTotalLinesCount(),
            'subtotal' => $this->getSubtotal(),
            'discount' => $this->getDiscountAmount(),
            'shipping' => $this->getShippingCost(),
            'total' => $this->getGrandTotal(),
            'savings' => $this->getTotalSavings(),
            'currency' => $this->currency,
            'is_empty' => $this->isEmpty(),
            'expires_at' => $this->expiresAt?->format('c'),
        ];
    }

    public function __toString(): string
    {
        $type = $this->isGuestCart() ? 'Guest' : 'User';
        $identifier = $this->user?->getEmail() ?? $this->sessionToken ?? 'Unknown';
        return sprintf('Cart [%s] %s (%d items)', $type, $identifier, $this->getTotalItemsCount());
    }
}
