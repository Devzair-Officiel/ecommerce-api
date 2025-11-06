<?php

declare(strict_types=1);

namespace App\Entity\Order;

use App\Entity\Site\Site;
use App\Entity\User\User;
use App\Traits\DateTrait;
use App\Entity\Cart\Coupon;
use App\Traits\SiteAwareTrait;
use Doctrine\DBAL\Types\Types;
use App\Enum\Order\OrderStatus;
use Doctrine\ORM\Mapping as ORM;
use App\Repository\Order\OrderRepository;
use Doctrine\Common\Collections\Collection;
use Doctrine\Common\Collections\ArrayCollection;
use Symfony\Component\Serializer\Annotation\Groups;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Commande validée et figée.
 * 
 * Responsabilités :
 * - Snapshot immutable d'un panier validé
 * - Totaux figés (HT, TVA, remises, port, TTC)
 * - Adresses figées (livraison + facturation)
 * - Suivi du cycle de vie (via OrderStatus)
 * - Traçabilité RGPD (IP, metadata)
 * 
 * Principe d'immutabilité :
 * - Les prix, adresses et produits sont copiés au moment de la commande
 * - Si le catalogue change, la commande reste inchangée
 * - Garantit conformité légale et comptable
 * 
 * Relations :
 * - ManyToOne avec User (nullable pour invités)
 * - ManyToOne avec Site (multi-tenant)
 * - OneToMany avec OrderItem (lignes de commande)
 * - OneToMany avec OrderStatusHistory (historique états)
 * - ManyToOne avec Coupon (référence, peut être null si supprimé)
 * - OneToOne avec Payment (sera créé plus tard)
 * - OneToOne avec Shipment (sera créé plus tard)
 */
#[ORM\Entity(repositoryClass: OrderRepository::class)]
#[ORM\Table(name: '`order`')]
#[ORM\Index(columns: ['reference'], name: 'idx_order_reference')]
#[ORM\Index(columns: ['user_id'], name: 'idx_order_user')]
#[ORM\Index(columns: ['site_id'], name: 'idx_order_site')]
#[ORM\Index(columns: ['status'], name: 'idx_order_status')]
#[ORM\Index(columns: ['created_at'], name: 'idx_order_created')]
#[ORM\HasLifecycleCallbacks]
class Order
{
    use DateTrait;
    use SiteAwareTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::INTEGER)]
    #[Groups(['order:read', 'order:list'])]
    private ?int $id = null;

    /**
     * Numéro de commande unique (format: YYYY-MM-XXXXX).
     * 
     * Généré automatiquement par OrderRepository::generateNextReference().
     * Exemples : 2025-01-00001, 2025-12-03456
     * 
     * Utilisé pour :
     * - Affichage client (confirmation, facture)
     * - Recherche support
     * - Exports comptables
     */
    #[ORM\Column(type: Types::STRING, length: 20, unique: true)]
    #[Assert\NotBlank(message: 'La référence est obligatoire.')]
    #[Assert\Length(max: 20)]
    #[Groups(['order:read', 'order:list'])]
    private string $reference;

    /**
     * Client propriétaire (null pour invités).
     * 
     * Pour invités : email stocké dans customerSnapshot.
     */
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    #[Groups(['order:read:admin'])]
    private ?User $user = null;

    /**
     * État actuel de la commande.
     */
    #[ORM\Column(type: Types::STRING, length: 20, enumType: OrderStatus::class)]
    #[Assert\NotNull(message: 'Le statut est requis.')]
    #[Groups(['order:read', 'order:list'])]
    private OrderStatus $status = OrderStatus::PENDING;

    /**
     * Devise de la commande (EUR, USD, GBP...).
     * 
     * Figée depuis le panier. Ne change plus.
     */
    #[ORM\Column(type: Types::STRING, length: 3)]
    #[Assert\NotBlank]
    #[Assert\Currency]
    #[Groups(['order:read', 'order:list'])]
    private string $currency = 'EUR';

    /**
     * Langue de la commande (fr, en, es...).
     * 
     * Pour emails de confirmation et documents.
     */
    #[ORM\Column(type: Types::STRING, length: 5)]
    #[Assert\NotBlank]
    #[Assert\Choice(choices: ['fr', 'en', 'es'], message: 'Langue non supportée.')]
    #[Groups(['order:read', 'order:list'])]
    private string $locale = 'fr';

    /**
     * Type de client (B2C, B2B).
     * 
     * Impact prix et conditions de paiement.
     */
    #[ORM\Column(type: Types::STRING, length: 3)]
    #[Assert\Choice(choices: ['B2C', 'B2B'], message: 'Type client invalide.')]
    #[Groups(['order:read', 'order:list'])]
    private string $customerType = 'B2C';

    // ===============================================
    // TOTAUX FIGÉS (IMMUTABLES)
    // ===============================================

    /**
     * Montant total HT (avant remise et frais).
     * 
     * Somme des lignes : Σ(prix unitaire × quantité)
     */
    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 2)]
    #[Assert\PositiveOrZero]
    #[Groups(['order:read', 'order:list'])]
    private float $subtotal = 0.0;

    /**
     * Montant de la remise appliquée (coupon).
     */
    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 2)]
    #[Assert\PositiveOrZero]
    #[Groups(['order:read', 'order:list'])]
    private float $discountAmount = 0.0;

    /**
     * Taux de TVA appliqué (en %).
     * 
     * Exemples : 20.0 (FR), 21.0 (BE), 7.7 (CH)
     * Figé au moment de la commande.
     */
    #[ORM\Column(type: Types::DECIMAL, precision: 5, scale: 2)]
    #[Assert\PositiveOrZero]
    #[Assert\Range(min: 0, max: 100)]
    #[Groups(['order:read', 'order:list'])]
    private float $taxRate = 20.0;

    /**
     * Montant de la TVA (calculé et figé).
     * 
     * Formule : (subtotal - discountAmount) × (taxRate / 100)
     */
    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 2)]
    #[Assert\PositiveOrZero]
    #[Groups(['order:read', 'order:list'])]
    private float $taxAmount = 0.0;

    /**
     * Frais de port figés.
     */
    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 2)]
    #[Assert\PositiveOrZero]
    #[Groups(['order:read', 'order:list'])]
    private float $shippingCost = 0.0;

    /**
     * Montant total TTC final.
     * 
     * Formule : subtotal - discountAmount + taxAmount + shippingCost
     */
    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 2)]
    #[Assert\PositiveOrZero]
    #[Groups(['order:read', 'order:list'])]
    private float $grandTotal = 0.0;

    // ===============================================
    // SNAPSHOTS JSON (IMMUTABLES)
    // ===============================================

    /**
     * Adresse de livraison figée (JSON).
     * 
     * Structure :
     * {
     *   "firstName": "Jean",
     *   "lastName": "Dupont",
     *   "company": "Ma Société",
     *   "street": "123 Rue Bio",
     *   "additionalAddress": "Bâtiment A",
     *   "postalCode": "75001",
     *   "city": "Paris",
     *   "countryCode": "FR",
     *   "phone": "+33123456789"
     * }
     */
    #[ORM\Column(type: Types::JSON)]
    #[Assert\NotNull(message: 'L\'adresse de livraison est requise.')]
    #[Groups(['order:read'])]
    private array $shippingAddress = [];

    /**
     * Adresse de facturation figée (JSON).
     * 
     * Même structure que shippingAddress.
     * Peut être identique à shippingAddress.
     */
    #[ORM\Column(type: Types::JSON)]
    #[Assert\NotNull(message: 'L\'adresse de facturation est requise.')]
    #[Groups(['order:read'])]
    private array $billingAddress = [];

    /**
     * Snapshot du coupon appliqué (JSON).
     * 
     * Structure :
     * {
     *   "code": "BIENVENUE10",
     *   "type": "percentage",
     *   "value": 10,
     *   "description": "Remise de bienvenue -10%"
     * }
     * 
     * null si pas de coupon.
     */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    #[Groups(['order:read'])]
    private ?array $appliedCoupon = null;

    /**
     * Snapshot des informations client (JSON).
     * 
     * Structure :
     * {
     *   "email": "client@example.com",
     *   "firstName": "Jean",
     *   "lastName": "Dupont",
     *   "phone": "+33123456789",
     *   "isGuest": false
     * }
     * 
     * Permet de garder les infos même si user supprimé (RGPD).
     */
    #[ORM\Column(type: Types::JSON)]
    #[Assert\NotNull]
    #[Groups(['order:read'])]
    private array $customerSnapshot = [];

    /**
     * Métadonnées additionnelles (JSON).
     * 
     * Exemples :
     * - ip_address (RGPD, fraude)
     * - user_agent (analytics)
     * - utm_source, utm_campaign (tracking)
     * - device_type (mobile/desktop)
     * - payment_method (stripe, paypal...)
     */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    #[Groups(['order:read:admin'])]
    private ?array $metadata = null;

    // ===============================================
    // NOTES ET MESSAGES
    // ===============================================

    /**
     * Notes internes pour l'équipe (non visible client).
     * 
     * Exemples :
     * - "Client VIP"
     * - "Problème résolu avec SAV"
     * - "Vérifier stock avant expédition"
     */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    #[Assert\Length(max: 2000)]
    #[Groups(['order:read:admin', 'order:write:admin'])]
    private ?string $adminNotes = null;

    /**
     * Message du client (optionnel).
     * 
     * Exemples :
     * - "C'est un cadeau, merci de l'emballer"
     * - "Livrer après 18h SVP"
     */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    #[Assert\Length(max: 1000)]
    #[Groups(['order:read', 'order:write'])]
    private ?string $customerMessage = null;

    // ===============================================
    // TIMESTAMPS SPÉCIFIQUES
    // ===============================================

    /**
     * Date de validation de la commande (paiement confirmé).
     */
    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    #[Groups(['order:read', 'order:list'])]
    private ?\DateTimeImmutable $validatedAt = null;

    /**
     * Date d'annulation.
     */
    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    #[Groups(['order:read'])]
    private ?\DateTimeImmutable $cancelledAt = null;

    /**
     * Date de livraison effective.
     */
    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    #[Groups(['order:read'])]
    private ?\DateTimeImmutable $deliveredAt = null;

    // ===============================================
    // RELATIONS
    // ===============================================

    /**
     * Coupon utilisé (référence, peut devenir null).
     * 
     * Le snapshot appliedCoupon garde les infos même si supprimé.
     */
    #[ORM\ManyToOne(targetEntity: Coupon::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    #[Groups(['order:read:admin'])]
    private ?Coupon $coupon = null;

    /**
     * @var Collection<int, OrderItem>
     */
    #[ORM\OneToMany(targetEntity: OrderItem::class, mappedBy: 'order', cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['id' => 'ASC'])]
    #[Groups(['order:read', 'order:items'])]
    private Collection $items;

    /**
     * @var Collection<int, OrderStatusHistory>
     */
    #[ORM\OneToMany(targetEntity: OrderStatusHistory::class, mappedBy: 'order', cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['createdAt' => 'ASC'])]
    #[Groups(['order:read:admin', 'order:history'])]
    private Collection $statusHistory;

    // ===============================================
    // CONSTRUCTEUR
    // ===============================================

    public function __construct()
    {
        $this->items = new ArrayCollection();
        $this->statusHistory = new ArrayCollection();
    }

    // ===============================================
    // GETTERS & SETTERS
    // ===============================================

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getReference(): string
    {
        return $this->reference;
    }

    public function setReference(string $reference): static
    {
        $this->reference = $reference;
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

    public function getStatus(): OrderStatus
    {
        return $this->status;
    }

    public function setStatus(OrderStatus $status): static
    {
        $this->status = $status;
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

    public function getLocale(): string
    {
        return $this->locale;
    }

    public function setLocale(string $locale): static
    {
        $this->locale = $locale;
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

    public function getSubtotal(): float
    {
        return $this->subtotal;
    }

    public function setSubtotal(float $subtotal): static
    {
        $this->subtotal = $subtotal;
        return $this;
    }

    public function getDiscountAmount(): float
    {
        return $this->discountAmount;
    }

    public function setDiscountAmount(float $discountAmount): static
    {
        $this->discountAmount = $discountAmount;
        return $this;
    }

    public function getTaxRate(): float
    {
        return $this->taxRate;
    }

    public function setTaxRate(float $taxRate): static
    {
        $this->taxRate = $taxRate;
        return $this;
    }

    public function getTaxAmount(): float
    {
        return $this->taxAmount;
    }

    public function setTaxAmount(float $taxAmount): static
    {
        $this->taxAmount = $taxAmount;
        return $this;
    }

    public function getShippingCost(): float
    {
        return $this->shippingCost;
    }

    public function setShippingCost(float $shippingCost): static
    {
        $this->shippingCost = $shippingCost;
        return $this;
    }

    public function getGrandTotal(): float
    {
        return $this->grandTotal;
    }

    public function setGrandTotal(float $grandTotal): static
    {
        $this->grandTotal = $grandTotal;
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

    public function getAppliedCoupon(): ?array
    {
        return $this->appliedCoupon;
    }

    public function setAppliedCoupon(?array $appliedCoupon): static
    {
        $this->appliedCoupon = $appliedCoupon;
        return $this;
    }

    public function getCustomerSnapshot(): array
    {
        return $this->customerSnapshot;
    }

    public function setCustomerSnapshot(array $customerSnapshot): static
    {
        $this->customerSnapshot = $customerSnapshot;
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

    public function getAdminNotes(): ?string
    {
        return $this->adminNotes;
    }

    public function setAdminNotes(?string $adminNotes): static
    {
        $this->adminNotes = $adminNotes;
        return $this;
    }

    public function getCustomerMessage(): ?string
    {
        return $this->customerMessage;
    }

    public function setCustomerMessage(?string $customerMessage): static
    {
        $this->customerMessage = $customerMessage;
        return $this;
    }

    public function getValidatedAt(): ?\DateTimeImmutable
    {
        return $this->validatedAt;
    }

    public function setValidatedAt(?\DateTimeImmutable $validatedAt): static
    {
        $this->validatedAt = $validatedAt;
        return $this;
    }

    public function getCancelledAt(): ?\DateTimeImmutable
    {
        return $this->cancelledAt;
    }

    public function setCancelledAt(?\DateTimeImmutable $cancelledAt): static
    {
        $this->cancelledAt = $cancelledAt;
        return $this;
    }

    public function getDeliveredAt(): ?\DateTimeImmutable
    {
        return $this->deliveredAt;
    }

    public function setDeliveredAt(?\DateTimeImmutable $deliveredAt): static
    {
        $this->deliveredAt = $deliveredAt;
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
     * @return Collection<int, OrderItem>
     */
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

    /**
     * @return Collection<int, OrderStatusHistory>
     */
    public function getStatusHistory(): Collection
    {
        return $this->statusHistory;
    }

    public function addStatusHistory(OrderStatusHistory $history): static
    {
        if (!$this->statusHistory->contains($history)) {
            $this->statusHistory->add($history);
            $history->setOrder($this);
        }

        return $this;
    }

    // ===============================================
    // HELPERS MÉTIER - ITEMS
    // ===============================================

    /**
     * Compte le nombre total d'articles.
     */
    public function getTotalItemsCount(): int
    {
        return array_reduce(
            $this->items->toArray(),
            fn(int $sum, OrderItem $item) => $sum + $item->getQuantity(),
            0
        );
    }

    /**
     * Compte le nombre de lignes.
     */
    public function getTotalLinesCount(): int
    {
        return $this->items->count();
    }

    /**
     * Vérifie si la commande est vide.
     */
    public function isEmpty(): bool
    {
        return $this->items->isEmpty();
    }

    // ===============================================
    // HELPERS MÉTIER - ÉTAT
    // ===============================================

    /**
     * Change le statut avec création d'historique.
     */
    public function changeStatus(
        OrderStatus $newStatus,
        ?User $changedBy = null,
        string $changedByType = 'system',
        ?string $reason = null,
        ?array $metadata = null
    ): static {
        // Vérifier transition autorisée
        if (!$this->status->canTransitionTo($newStatus)) {
            throw new \LogicException(sprintf(
                'Transition invalide : %s → %s',
                $this->status->value,
                $newStatus->value
            ));
        }

        $oldStatus = $this->status;
        $this->status = $newStatus;

        // Créer l'entrée d'historique
        $history = new OrderStatusHistory();
        $history->setOrder($this);
        $history->setFromStatus($oldStatus);
        $history->setToStatus($newStatus);
        $history->setChangedBy($changedBy);
        $history->setChangedByType($changedByType);
        $history->setReason($reason);
        $history->setMetadata($metadata);

        $this->addStatusHistory($history);

        // Mettre à jour timestamps spécifiques
        match ($newStatus) {
            OrderStatus::CONFIRMED => $this->validatedAt ??= new \DateTimeImmutable(),
            OrderStatus::CANCELLED => $this->cancelledAt ??= new \DateTimeImmutable(),
            OrderStatus::DELIVERED => $this->deliveredAt ??= new \DateTimeImmutable(),
            default => null,
        };

        return $this;
    }

    /**
     * Vérifie si la commande peut être annulée.
     */
    public function isCancellable(): bool
    {
        return $this->status->isCancellable();
    }

    /**
     * Vérifie si la commande peut être remboursée.
     */
    public function isRefundable(): bool
    {
        return $this->status->isRefundable();
    }

    /**
     * Vérifie si la commande est dans un état final.
     */
    public function isFinal(): bool
    {
        return $this->status->isFinal();
    }

    /**
     * Vérifie si la commande est payée.
     */
    public function isPaid(): bool
    {
        return in_array($this->status, OrderStatus::paidStatuses(), true);
    }

    /**
     * Vérifie si la commande est d'un invité.
     */
    public function isGuestOrder(): bool
    {
        return $this->user === null;
    }

    // ===============================================
    // HELPERS MÉTIER - CALCULS
    // ===============================================

    /**
     * Calcule le montant HT après remise.
     */
    public function getSubtotalAfterDiscount(): float
    {
        return max(0, $this->subtotal - $this->discountAmount);
    }

    /**
     * Calcule le montant TTC sans frais de port.
     */
    public function getTotalWithoutShipping(): float
    {
        return $this->getSubtotalAfterDiscount() + $this->taxAmount;
    }

    /**
     * Pourcentage de remise appliqué.
     */
    public function getDiscountPercentage(): float
    {
        if ($this->subtotal <= 0) {
            return 0.0;
        }

        return ($this->discountAmount / $this->subtotal) * 100;
    }

    /**
     * Économie totale réalisée.
     */
    public function getTotalSavings(): float
    {
        return $this->discountAmount + array_reduce(
            $this->items->toArray(),
            fn(float $sum, OrderItem $item) => $sum + ($item->getSavings() ?? 0),
            0.0
        );
    }

    // ===============================================
    // HELPERS MÉTIER - SNAPSHOTS
    // ===============================================

    /**
     * Récupère une valeur du snapshot client.
     */
    public function getCustomerValue(string $key, mixed $default = null): mixed
    {
        return $this->customerSnapshot[$key] ?? $default;
    }

    /**
     * Email du client (depuis snapshot).
     */
    public function getCustomerEmail(): string
    {
        return $this->getCustomerValue('email', 'unknown@example.com');
    }

    /**
     * Nom complet du client.
     */
    public function getCustomerFullName(): string
    {
        $firstName = $this->getCustomerValue('firstName', '');
        $lastName = $this->getCustomerValue('lastName', '');

        return trim($firstName . ' ' . $lastName) ?: 'Client';
    }

    /**
     * Récupère une métadonnée.
     */
    public function getMetadataValue(string $key, mixed $default = null): mixed
    {
        return $this->metadata[$key] ?? $default;
    }

    /**
     * Ajoute une métadonnée.
     */
    public function addMetadata(string $key, mixed $value): static
    {
        if ($this->metadata === null) {
            $this->metadata = [];
        }

        $this->metadata[$key] = $value;
        return $this;
    }

    // ===============================================
    // FORMATAGE
    // ===============================================

    /**
     * Résumé de la commande pour affichage.
     */
    public function getSummary(): array
    {
        return [
            'reference' => $this->reference,
            'status' => $this->status->value,
            'status_label' => $this->status->getLabel(),
            'items_count' => $this->getTotalItemsCount(),
            'lines_count' => $this->getTotalLinesCount(),
            'subtotal' => $this->subtotal,
            'discount' => $this->discountAmount,
            'tax_rate' => $this->taxRate,
            'tax_amount' => $this->taxAmount,
            'shipping' => $this->shippingCost,
            'total' => $this->grandTotal,
            'savings' => $this->getTotalSavings(),
            'currency' => $this->currency,
            'created_at' => $this->createdAt?->format('c'),
            'validated_at' => $this->validatedAt?->format('c'),
        ];
    }

    public function __toString(): string
    {
        return sprintf(
            'Order %s (%s) - %.2f %s',
            $this->reference,
            $this->status->getLabel(),
            $this->grandTotal,
            $this->currency
        );
    }
}
