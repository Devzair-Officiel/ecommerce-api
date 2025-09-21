<?php

namespace App\Entity;

use App\Entity\Trait\TimestampableTrait;
use App\Traits\DateTrait;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Annotation\Groups;

#[ORM\Entity(repositoryClass: "App\Repository\CouponRepository")]
#[ORM\HasLifecycleCallbacks]
#[ORM\Index(columns: ['code'], name: 'idx_coupon_code')]
#[ORM\Index(columns: ['site_id', 'is_active'], name: 'idx_coupon_site_active')]
class Coupon
{
    use DateTrait;

    public const TYPE_PERCENTAGE = 'percentage';
    public const TYPE_FIXED = 'fixed';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    #[Groups(['coupon_list', 'coupon_detail', 'admin_read'])]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Site::class)]
    #[ORM\JoinColumn(nullable: false)]
    private ?Site $site = null;

    #[ORM\Column(length: 50, unique: true)]
    #[Groups(['coupon_list', 'coupon_detail', 'public_read'])]
    private ?string $code = null;

    #[ORM\Column(length: 20)]
    #[Groups(['coupon_detail', 'admin_read'])]
    private string $type = self::TYPE_PERCENTAGE;

    #[ORM\Column(type: 'decimal', precision: 10, scale: 2)]
    #[Groups(['coupon_detail', 'admin_read'])]
    private ?string $value = null;

    #[ORM\Column(type: 'decimal', precision: 10, scale: 2, nullable: true)]
    #[Groups(['coupon_detail', 'admin_read'])]
    private ?string $minAmount = null;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    #[Groups(['coupon_detail', 'admin_read'])]
    private ?\DateTimeImmutable $validFrom = null;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    #[Groups(['coupon_detail', 'admin_read'])]
    private ?\DateTimeImmutable $validUntil = null;

    #[ORM\Column(nullable: true)]
    #[Groups(['coupon_detail', 'admin_read'])]
    private ?int $usageLimit = null;

    #[ORM\Column]
    #[Groups(['coupon_detail', 'admin_read'])]
    private int $usageCount = 0;

    #[ORM\Column]
    #[Groups(['coupon_list', 'admin_read'])]
    private bool $isActive = true;

    // === GETTERS/SETTERS BASIQUES ===

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
        if (!in_array($type, [self::TYPE_PERCENTAGE, self::TYPE_FIXED])) {
            throw new \InvalidArgumentException('Type de coupon invalide');
        }
        $this->type = $type;
        return $this;
    }

    public function getValue(): ?string
    {
        return $this->value;
    }

    public function setValue(string $value): static
    {
        if ((float) $value < 0) {
            throw new \InvalidArgumentException('La valeur du coupon ne peut pas être négative');
        }
        $this->value = $value;
        return $this;
    }

    public function getMinAmount(): ?string
    {
        return $this->minAmount;
    }

    public function setMinAmount(?string $minAmount): static
    {
        if ($minAmount !== null && (float) $minAmount < 0) {
            throw new \InvalidArgumentException('Le montant minimum ne peut pas être négatif');
        }
        $this->minAmount = $minAmount;
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
        if ($validUntil && $this->validFrom && $validUntil <= $this->validFrom) {
            throw new \InvalidArgumentException('La date de fin doit être postérieure à la date de début');
        }
        $this->validUntil = $validUntil;
        return $this;
    }

    public function getUsageLimit(): ?int
    {
        return $this->usageLimit;
    }

    public function setUsageLimit(?int $usageLimit): static
    {
        if ($usageLimit !== null && $usageLimit < 0) {
            throw new \InvalidArgumentException('La limite d\'utilisation ne peut pas être négative');
        }
        $this->usageLimit = $usageLimit;
        return $this;
    }

    public function getUsageCount(): int
    {
        return $this->usageCount;
    }

    public function setUsageCount(int $usageCount): static
    {
        if ($usageCount < 0) {
            throw new \InvalidArgumentException('Le compteur d\'utilisation ne peut pas être négatif');
        }
        $this->usageCount = $usageCount;
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

    // === MÉTHODES MÉTIER (Simple état, pas de logique complexe) ===

    #[Groups(['coupon_detail'])]
    public function isPercentage(): bool
    {
        return $this->type === self::TYPE_PERCENTAGE;
    }

    #[Groups(['coupon_detail'])]
    public function isFixed(): bool
    {
        return $this->type === self::TYPE_FIXED;
    }

    #[Groups(['coupon_detail'])]
    public function hasUsageLimit(): bool
    {
        return $this->usageLimit !== null;
    }

    #[Groups(['coupon_detail'])]
    public function hasMinAmount(): bool
    {
        return $this->minAmount !== null;
    }

    #[Groups(['coupon_detail'])]
    public function getRemainingUsage(): ?int
    {
        if (!$this->hasUsageLimit()) {
            return null;
        }
        return max(0, $this->usageLimit - $this->usageCount);
    }

    // === MÉTHODES D'ACTION ===

    public function incrementUsage(): static
    {
        $this->usageCount++;
        return $this;
    }

    public function activate(): static
    {
        $this->isActive = true;
        return $this;
    }

    public function deactivate(): static
    {
        $this->isActive = false;
        return $this;
    }
}
