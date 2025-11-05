<?php

declare(strict_types=1);

namespace App\Entity\Cart;

use App\Entity\Site\Site;
use App\Repository\Cart\CouponRepository;
use App\Traits\ActiveStateTrait;
use App\Traits\DateTrait;
use App\Traits\SoftDeletableTrait;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Annotation\Groups;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Serializer\Annotation\Ignore;

/**
 * Code promo / Coupon de réduction.
 * 
 * Types de réductions :
 * - PERCENTAGE : X% de réduction (ex: 10% → 0.10)
 * - FIXED_AMOUNT : Montant fixe (ex: 5€)
 * - FREE_SHIPPING : Livraison gratuite
 * 
 * Conditions d'application :
 * - Montant minimum de commande
 * - Produits/catégories spécifiques
 * - Utilisateurs spécifiques (B2C/B2B)
 * - Date de validité
 * 
 * Limites :
 * - Nombre d'utilisations total
 * - Utilisations par utilisateur
 * - Première commande seulement
 */
#[ORM\Entity(repositoryClass: CouponRepository::class)]
#[ORM\Index(columns: ['code'], name: 'idx_coupon_code')]
#[ORM\Index(columns: ['valid_from', 'valid_until'], name: 'idx_coupon_validity')]
#[ORM\UniqueConstraint(name: 'UNIQ_COUPON_CODE_SITE', fields: ['code', 'site'])]
#[ORM\HasLifecycleCallbacks]
class Coupon
{
    use DateTrait;
    use ActiveStateTrait;
    use SoftDeletableTrait;

    public const TYPE_PERCENTAGE = 'percentage';
    public const TYPE_FIXED_AMOUNT = 'fixed_amount';
    public const TYPE_FREE_SHIPPING = 'free_shipping';

    public const TYPES = [
        self::TYPE_PERCENTAGE,
        self::TYPE_FIXED_AMOUNT,
        self::TYPE_FREE_SHIPPING
    ];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::INTEGER)]
    #[Groups(['coupon:read'])]
    private ?int $id = null;

    /**
     * Code promo (ex: "WELCOME10", "NOEL2024").
     * 
     * Unique par site, insensible à la casse.
     */
    #[ORM\Column(type: Types::STRING, length: 50)]
    #[Assert\NotBlank(message: 'Le code promo est requis.')]
    #[Assert\Length(min: 3, max: 50)]
    #[Assert\Regex(pattern: '/^[A-Z0-9-_]+$/i', message: 'Code invalide (A-Z, 0-9, -, _ uniquement).')]
    #[Groups(['coupon:read', 'coupon:write'])]
    private ?string $code = null;

    /**
     * Type de réduction.
     */
    #[ORM\Column(type: Types::STRING, length: 20)]
    #[Assert\NotBlank]
    #[Assert\Choice(choices: self::TYPES)]
    #[Groups(['coupon:read', 'coupon:write'])]
    private string $type = self::TYPE_PERCENTAGE;

    /**
     * Valeur de la réduction.
     * 
     * - TYPE_PERCENTAGE : entre 0.01 et 1.00 (1% à 100%)
     * - TYPE_FIXED_AMOUNT : montant en euros (ex: 5.00)
     * - TYPE_FREE_SHIPPING : ignoré (null)
     */
    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 2, nullable: true)]
    #[Assert\PositiveOrZero]
    #[Groups(['coupon:read', 'coupon:write'])]
    private ?float $value = null;

    /**
     * Montant minimum de commande requis (en devise du site).
     */
    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 2, nullable: true)]
    #[Assert\PositiveOrZero]
    #[Groups(['coupon:read', 'coupon:write'])]
    private ?float $minimumAmount = null;

    /**
     * Montant maximum de réduction (plafond).
     * 
     * Utile pour TYPE_PERCENTAGE : "10% max 50€"
     */
    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 2, nullable: true)]
    #[Assert\PositiveOrZero]
    #[Groups(['coupon:read', 'coupon:write'])]
    private ?float $maximumDiscount = null;

    /**
     * Date de début de validité.
     */
    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    #[Groups(['coupon:read', 'coupon:write'])]
    private ?\DateTimeImmutable $validFrom = null;

    /**
     * Date de fin de validité.
     */
    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    #[Groups(['coupon:read', 'coupon:write'])]
    private ?\DateTimeImmutable $validUntil = null;

    /**
     * Nombre maximum d'utilisations totales (null = illimité).
     */
    #[ORM\Column(type: Types::INTEGER, nullable: true)]
    #[Assert\PositiveOrZero]
    #[Groups(['coupon:read', 'coupon:write'])]
    private ?int $maxUsages = null;

    /**
     * Nombre d'utilisations par utilisateur (null = illimité).
     */
    #[ORM\Column(type: Types::INTEGER, nullable: true)]
    #[Assert\PositiveOrZero]
    #[Groups(['coupon:read', 'coupon:write'])]
    private ?int $maxUsagesPerUser = null;

    /**
     * Nombre d'utilisations actuelles.
     * 
     * Incrémenté à chaque utilisation validée (Order créé).
     */
    #[ORM\Column(type: Types::INTEGER, options: ['default' => 0])]
    #[Groups(['coupon:read'])]
    private int $usageCount = 0;

    /**
     * Réservé première commande uniquement ?
     */
    #[ORM\Column(type: Types::BOOLEAN, options: ['default' => false])]
    #[Groups(['coupon:read', 'coupon:write'])]
    private bool $firstOrderOnly = false;

    /**
     * Types de clients autorisés (B2C, B2B, ou null = tous).
     */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    #[Groups(['coupon:read', 'coupon:write'])]
    private ?array $allowedCustomerTypes = null;

    /**
     * Description interne (admin uniquement).
     */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    #[Groups(['coupon:read:admin', 'coupon:write'])]
    private ?string $internalNote = null;

    /**
     * Message à afficher au client.
     * 
     * Ex: "Bienvenue ! Profitez de 10% sur votre première commande."
     */
    #[ORM\Column(type: Types::STRING, length: 255, nullable: true)]
    #[Groups(['coupon:read', 'coupon:write'])]
    private ?string $publicMessage = null;

    // ===============================================
    // RELATIONS
    // ===============================================

    /**
     * Site concerné (multi-tenant).
     */
    #[ORM\ManyToOne(targetEntity: Site::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    #[Assert\NotNull]
    #[Groups(['coupon:read'])]
    private ?Site $site = null;

    /**
     * Paniers utilisant ce coupon.
     * 
     * @var Collection<int, Cart>
     */
    #[ORM\OneToMany(targetEntity: Cart::class, mappedBy: 'coupon')]
    private Collection $carts;

    // TODO: Après création de Order
    // #[ORM\OneToMany(targetEntity: Order::class, mappedBy: 'coupon')]
    // private Collection $orders;

    // ===============================================
    // CONSTRUCTEUR
    // ===============================================

    public function __construct()
    {
        $this->carts = new ArrayCollection();
    }

    // ===============================================
    // GETTERS & SETTERS
    // ===============================================

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getCode(): ?string
    {
        return $this->code;
    }

    public function setCode(string $code): static
    {
        $this->code = strtoupper(trim($code));
        return $this;
    }

    public function getType(): string
    {
        return $this->type;
    }

    public function setType(string $type): static
    {
        $this->type = $type;
        return $this;
    }

    public function getValue(): ?float
    {
        return $this->value;
    }

    public function setValue(?float $value): static
    {
        $this->value = $value;
        return $this;
    }

    public function getMinimumAmount(): ?float
    {
        return $this->minimumAmount;
    }

    public function setMinimumAmount(?float $minimumAmount): static
    {
        $this->minimumAmount = $minimumAmount;
        return $this;
    }

    public function getMaximumDiscount(): ?float
    {
        return $this->maximumDiscount;
    }

    public function setMaximumDiscount(?float $maximumDiscount): static
    {
        $this->maximumDiscount = $maximumDiscount;
        return $this;
    }

    public function getValidFrom(): ?\DateTimeImmutable
    {
        return $this->validFrom;
    }

    public function setValidFrom(?\DateTimeImmutable $validFrom): static
    {
        $this->validFrom = $validFrom;
        return $this;
    }

    public function getValidUntil(): ?\DateTimeImmutable
    {
        return $this->validUntil;
    }

    public function setValidUntil(?\DateTimeImmutable $validUntil): static
    {
        $this->validUntil = $validUntil;
        return $this;
    }

    public function getMaxUsages(): ?int
    {
        return $this->maxUsages;
    }

    public function setMaxUsages(?int $maxUsages): static
    {
        $this->maxUsages = $maxUsages;
        return $this;
    }

    public function getMaxUsagesPerUser(): ?int
    {
        return $this->maxUsagesPerUser;
    }

    public function setMaxUsagesPerUser(?int $maxUsagesPerUser): static
    {
        $this->maxUsagesPerUser = $maxUsagesPerUser;
        return $this;
    }

    public function getUsageCount(): int
    {
        return $this->usageCount;
    }

    public function setUsageCount(int $usageCount): static
    {
        $this->usageCount = $usageCount;
        return $this;
    }

    public function incrementUsageCount(): static
    {
        $this->usageCount++;
        return $this;
    }

    public function isFirstOrderOnly(): bool
    {
        return $this->firstOrderOnly;
    }

    public function setFirstOrderOnly(bool $firstOrderOnly): static
    {
        $this->firstOrderOnly = $firstOrderOnly;
        return $this;
    }

    public function getAllowedCustomerTypes(): ?array
    {
        return $this->allowedCustomerTypes;
    }

    public function setAllowedCustomerTypes(?array $allowedCustomerTypes): static
    {
        $this->allowedCustomerTypes = $allowedCustomerTypes;
        return $this;
    }

    public function getInternalNote(): ?string
    {
        return $this->internalNote;
    }

    public function setInternalNote(?string $internalNote): static
    {
        $this->internalNote = $internalNote;
        return $this;
    }

    public function getPublicMessage(): ?string
    {
        return $this->publicMessage;
    }

    public function setPublicMessage(?string $publicMessage): static
    {
        $this->publicMessage = $publicMessage;
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

    /**
     * @return Collection<int, Cart>
     */
    public function getCarts(): Collection
    {
        return $this->carts;
    }

    // ===============================================
    // HELPERS MÉTIER - VALIDATION
    // ===============================================

    /**
     * Vérifie si le coupon est valide (non expiré, non épuisé).
     */
    public function isValid(): bool
    {
        return !$this->isExpired()
            && !$this->isExhausted()
            && $this->isActive()
            && !$this->isDeleted();
    }

    /**
     * Vérifie si le coupon est expiré (date).
     */
    public function isExpired(): bool
    {
        $now = new \DateTimeImmutable();

        if ($this->validFrom && $this->validFrom > $now) {
            return true; // Pas encore valide
        }

        if ($this->validUntil && $this->validUntil < $now) {
            return true; // Expiré
        }

        return false;
    }

    /**
     * Vérifie si le coupon a atteint sa limite d'utilisations.
     */
    public function isExhausted(): bool
    {
        if ($this->maxUsages === null) {
            return false; // Illimité
        }

        return $this->usageCount >= $this->maxUsages;
    }

    /**
     * Vérifie si l'utilisateur peut encore utiliser ce coupon.
     * 
     * @param int $userUsageCount Nombre d'utilisations de l'user
     */
    public function canUserUse(int $userUsageCount): bool
    {
        if ($this->maxUsagesPerUser === null) {
            return true; // Illimité
        }

        return $userUsageCount < $this->maxUsagesPerUser;
    }

    /**
     * Vérifie si le coupon s'applique à un type de client.
     */
    public function isValidForCustomerType(string $customerType): bool
    {
        if ($this->allowedCustomerTypes === null || empty($this->allowedCustomerTypes)) {
            return true; // Tous autorisés
        }

        return in_array($customerType, $this->allowedCustomerTypes, true);
    }

    /**
     * Vérifie si le montant du panier respecte le minimum requis.
     */
    public function meetsMinimumAmount(float $cartAmount): bool
    {
        if ($this->minimumAmount === null) {
            return true;
        }

        return $cartAmount >= $this->minimumAmount;
    }

    // ===============================================
    // HELPERS MÉTIER - CALCUL RÉDUCTION
    // ===============================================

    /**
     * Calcule le montant de la réduction pour un panier donné.
     * 
     * @param float $cartSubtotal Sous-total du panier
     * @return float Montant de la réduction
     */
    public function calculateDiscount(float $cartSubtotal): float
    {
        if (!$this->meetsMinimumAmount($cartSubtotal)) {
            return 0.0;
        }

        return match ($this->type) {
            self::TYPE_PERCENTAGE => $this->calculatePercentageDiscount($cartSubtotal),
            self::TYPE_FIXED_AMOUNT => $this->calculateFixedDiscount($cartSubtotal),
            self::TYPE_FREE_SHIPPING => 0.0, // Géré séparément
            default => 0.0
        };
    }

    /**
     * Calcule réduction en pourcentage.
     */
    private function calculatePercentageDiscount(float $cartSubtotal): float
    {
        $discount = $cartSubtotal * $this->value;

        // Appliquer plafond si défini
        if ($this->maximumDiscount !== null) {
            $discount = min($discount, $this->maximumDiscount);
        }

        // Ne pas dépasser le montant du panier
        return min($discount, $cartSubtotal);
    }

    /**
     * Calcule réduction montant fixe.
     */
    private function calculateFixedDiscount(float $cartSubtotal): float
    {
        // Ne pas dépasser le montant du panier
        return min($this->value ?? 0.0, $cartSubtotal);
    }

    /**
     * Vérifie si le coupon offre la livraison gratuite.
     */
    public function offersFreeShipping(): bool
    {
        return $this->type === self::TYPE_FREE_SHIPPING;
    }

    // ===============================================
    // HELPERS FORMATAGE
    // ===============================================

    /**
     * Retourne un résumé du coupon pour affichage.
     */
    public function getSummary(): array
    {
        return [
            'code' => $this->code,
            'type' => $this->type,
            'description' => $this->getDescription(),
            'max_usages_par_user' => $this->getMaxUsagesPerUser(),
            'public_message' => $this->getPublicMessage(),
            'is_valid' => $this->isValid(),
            'is_expired' => $this->isExpired(),
            'is_exhausted' => $this->isExhausted(),
            'remaining_usages' => $this->getRemainingUsages(),
            'valid_until' => $this->validUntil?->format('c'),
        ];
    }

    /**
     * Génère une description lisible du coupon.
     */
    public function getDescription(): string
    {
        return match ($this->type) {
            self::TYPE_PERCENTAGE => sprintf('%.0f%% de réduction', $this->value * 100),
            self::TYPE_FIXED_AMOUNT => sprintf('%.2f€ de réduction', $this->value),
            self::TYPE_FREE_SHIPPING => 'Livraison gratuite',
            default => 'Réduction'
        };
    }

    /**
     * Nombre d'utilisations restantes.
     */
    public function getRemainingUsages(): ?int
    {
        if ($this->maxUsages === null) {
            return null; // Illimité
        }

        return max(0, $this->maxUsages - $this->usageCount);
    }

    public function __toString(): string
    {
        return sprintf('%s (%s)', $this->code, $this->getDescription());
    }
}
