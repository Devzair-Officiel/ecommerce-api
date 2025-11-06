<?php

declare(strict_types=1);

namespace App\Entity\Order;

use App\Entity\User\User;
use App\Enum\Order\OrderStatus;
use App\Repository\Order\OrderStatusHistoryRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Annotation\Groups;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Historique des changements d'état d'une commande.
 * 
 * Responsabilités :
 * - Tracer chaque transition de statut (audit trail)
 * - Identifier qui a fait le changement (user, admin, système)
 * - Horodater précisément chaque action
 * - Stocker la raison du changement (RGPD, litiges)
 * 
 * Cas d'usage :
 * - Analyse des délais de traitement
 * - Résolution de litiges clients
 * - Conformité RGPD (droit d'accès)
 * - Audit interne
 */
#[ORM\Entity(repositoryClass: OrderStatusHistoryRepository::class)]
#[ORM\Table(name: 'order_status_history')]
#[ORM\Index(columns: ['order_id'], name: 'idx_order_status_history_order')]
#[ORM\Index(columns: ['to_status'], name: 'idx_order_status_history_to_status')]
#[ORM\Index(columns: ['created_at'], name: 'idx_order_status_history_created')]
class OrderStatusHistory
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::INTEGER)]
    #[Groups(['order:read:admin'])]
    private ?int $id = null;

    /**
     * Commande concernée.
     */
    #[ORM\ManyToOne(targetEntity: Order::class, inversedBy: 'statusHistory')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    #[Assert\NotNull(message: 'La commande est requise.')]
    private ?Order $order = null;

    /**
     * État avant la transition.
     */
    #[ORM\Column(type: Types::STRING, length: 20, enumType: OrderStatus::class)]
    #[Groups(['order:read', 'order:history'])]
    private ?OrderStatus $fromStatus = null;

    /**
     * État après la transition.
     */
    #[ORM\Column(type: Types::STRING, length: 20, enumType: OrderStatus::class)]
    #[Assert\NotNull(message: 'Le nouveau statut est requis.')]
    #[Groups(['order:read', 'order:history'])]
    private OrderStatus $toStatus;

    /**
     * Utilisateur ayant effectué le changement.
     * 
     * null = changement automatique (webhook, cron)
     */
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    #[Groups(['order:read:admin', 'order:history'])]
    private ?User $changedBy = null;

    /**
     * Type d'acteur (system, customer, admin).
     * 
     * Permet de différencier les changements même si changedBy est null.
     */
    #[ORM\Column(type: Types::STRING, length: 20)]
    #[Assert\Choice(choices: ['system', 'customer', 'admin'], message: 'Type d\'acteur invalide.')]
    #[Groups(['order:read', 'order:history'])]
    private string $changedByType = 'system';

    /**
     * Raison du changement (optionnel mais recommandé).
     * 
     * Exemples :
     * - "Paiement Stripe confirmé"
     * - "Annulation client"
     * - "Produit en rupture de stock"
     * - "Colis remis au transporteur"
     */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    #[Assert\Length(max: 1000, maxMessage: 'La raison est trop longue (max 1000 caractères).')]
    #[Groups(['order:read', 'order:history'])]
    private ?string $reason = null;

    /**
     * Métadonnées additionnelles (JSON).
     * 
     * Exemples :
     * - transaction_id (Stripe)
     * - tracking_number (transporteur)
     * - ip_address (pour annulation client)
     * - admin_notes (notes internes)
     */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    #[Groups(['order:read:admin'])]
    private ?array $metadata = null;

    /**
     * Date de la transition.
     */
    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    #[Groups(['order:read', 'order:history'])]
    private \DateTimeImmutable $createdAt;

    // ===============================================
    // CONSTRUCTEUR
    // ===============================================

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
    }

    // ===============================================
    // GETTERS & SETTERS
    // ===============================================

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

    public function getFromStatus(): ?OrderStatus
    {
        return $this->fromStatus;
    }

    public function setFromStatus(?OrderStatus $fromStatus): static
    {
        $this->fromStatus = $fromStatus;
        return $this;
    }

    public function getToStatus(): OrderStatus
    {
        return $this->toStatus;
    }

    public function setToStatus(OrderStatus $toStatus): static
    {
        $this->toStatus = $toStatus;
        return $this;
    }

    public function getChangedBy(): ?User
    {
        return $this->changedBy;
    }

    public function setChangedBy(?User $changedBy): static
    {
        $this->changedBy = $changedBy;
        return $this;
    }

    public function getChangedByType(): string
    {
        return $this->changedByType;
    }

    public function setChangedByType(string $changedByType): static
    {
        $this->changedByType = $changedByType;
        return $this;
    }

    public function getReason(): ?string
    {
        return $this->reason;
    }

    public function setReason(?string $reason): static
    {
        $this->reason = $reason;
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

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    // ===============================================
    // HELPERS MÉTIER
    // ===============================================

    /**
     * Vérifie si le changement a été fait par un admin.
     */
    public function isAdminChange(): bool
    {
        return $this->changedByType === 'admin';
    }

    /**
     * Vérifie si le changement a été fait par le client.
     */
    public function isCustomerChange(): bool
    {
        return $this->changedByType === 'customer';
    }

    /**
     * Vérifie si le changement a été fait automatiquement.
     */
    public function isSystemChange(): bool
    {
        return $this->changedByType === 'system';
    }

    /**
     * Récupère une métadonnée spécifique.
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

    /**
     * Label de la transition pour affichage.
     */
    public function getTransitionLabel(): string
    {
        $from = $this->fromStatus?->getLabel() ?? 'Nouveau';
        $to = $this->toStatus->getLabel();

        return sprintf('%s → %s', $from, $to);
    }

    /**
     * Description complète pour affichage.
     */
    public function getDescription(): string
    {
        $actor = match ($this->changedByType) {
            'admin' => $this->changedBy?->getEmail() ?? 'Administrateur',
            'customer' => 'Client',
            'system' => 'Système',
        };

        $description = sprintf(
            '%s par %s',
            $this->getTransitionLabel(),
            $actor
        );

        if ($this->reason) {
            $description .= ' : ' . $this->reason;
        }

        return $description;
    }

    // ===============================================
    // FORMATAGE
    // ===============================================

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'from_status' => $this->fromStatus?->value,
            'to_status' => $this->toStatus->value,
            'from_label' => $this->fromStatus?->getLabel(),
            'to_label' => $this->toStatus->getLabel(),
            'changed_by_type' => $this->changedByType,
            'changed_by' => $this->changedBy?->getEmail(),
            'reason' => $this->reason,
            'created_at' => $this->createdAt->format('c'),
        ];
    }

    public function __toString(): string
    {
        return $this->getDescription();
    }
}
