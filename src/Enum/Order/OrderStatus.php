<?php

declare(strict_types=1);

namespace App\Enum\Order;

/**
 * États d'une commande avec transitions autorisées.
 * 
 * Cycle de vie standard :
 * PENDING → CONFIRMED → PROCESSING → SHIPPED → DELIVERED → COMPLETED
 * 
 * Chemins alternatifs :
 * - Annulation : * → CANCELLED
 * - Remboursement : * → REFUNDED
 * - Échec : PENDING → FAILED
 */
enum OrderStatus: string
{
/**
     * En attente de paiement.
     * État initial après création du panier.
     */
    case PENDING = 'pending';

/**
     * Paiement confirmé mais pas encore traité.
     * Webhook Stripe reçu avec succès.
     */
    case CONFIRMED = 'confirmed';

/**
     * Commande en préparation.
     * Stock décrémenté, picking en cours.
     */
    case PROCESSING = 'processing';

/**
     * Expédiée au transporteur.
     * Numéro de suivi généré.
     */
    case SHIPPED = 'shipped';

/**
     * Livrée au client.
     * Confirmé par transporteur ou client.
     */
    case DELIVERED = 'delivered';

/**
     * Terminée avec succès.
     * Plus de modification possible.
     */
    case COMPLETED = 'completed';

/**
     * Annulée (par client ou admin).
     * Stock re-crédité si déjà décrémenté.
     */
    case CANCELLED = 'cancelled';

/**
     * Remboursée.
     * Remboursement Stripe effectué.
     */
    case REFUNDED = 'refunded';

/**
     * Échec de paiement.
     * Stripe a refusé la transaction.
     */
    case FAILED = 'failed';

/**
     * En attente d'action (problème détecté).
     * Ex : adresse invalide, stock insuffisant après paiement.
     */
    case ON_HOLD = 'on_hold';

    // ===============================================
    // HELPERS - LABELS
    // ===============================================

    /**
     * Label français pour affichage.
     */
    public function getLabel(): string
    {
        return match ($this) {
            self::PENDING => 'En attente',
            self::CONFIRMED => 'Confirmée',
            self::PROCESSING => 'En préparation',
            self::SHIPPED => 'Expédiée',
            self::DELIVERED => 'Livrée',
            self::COMPLETED => 'Terminée',
            self::CANCELLED => 'Annulée',
            self::REFUNDED => 'Remboursée',
            self::FAILED => 'Échec paiement',
            self::ON_HOLD => 'En attente',
        };
    }

    /**
     * Couleur pour affichage UI (Tailwind/Bootstrap).
     */
    public function getColor(): string
    {
        return match ($this) {
            self::PENDING => 'orange',
            self::CONFIRMED => 'blue',
            self::PROCESSING => 'purple',
            self::SHIPPED => 'indigo',
            self::DELIVERED => 'teal',
            self::COMPLETED => 'green',
            self::CANCELLED => 'red',
            self::REFUNDED => 'yellow',
            self::FAILED => 'red',
            self::ON_HOLD => 'gray',
        };
    }

    /**
     * Icône Font Awesome / Heroicons.
     */
    public function getIcon(): string
    {
        return match ($this) {
            self::PENDING => 'clock',
            self::CONFIRMED => 'check-circle',
            self::PROCESSING => 'cog',
            self::SHIPPED => 'truck',
            self::DELIVERED => 'home',
            self::COMPLETED => 'check-double',
            self::CANCELLED => 'times-circle',
            self::REFUNDED => 'undo',
            self::FAILED => 'exclamation-triangle',
            self::ON_HOLD => 'pause-circle',
        };
    }

    // ===============================================
    // HELPERS - TRANSITIONS
    // ===============================================

    /**
     * États autorisés après celui-ci (transitions valides).
     * 
     * @return array<OrderStatus>
     */
    public function getAllowedTransitions(): array
    {
        return match ($this) {
            self::PENDING => [
                self::CONFIRMED,
                self::FAILED,
                self::CANCELLED,
            ],
            self::CONFIRMED => [
                self::PROCESSING,
                self::ON_HOLD,
                self::CANCELLED,
            ],
            self::PROCESSING => [
                self::SHIPPED,
                self::ON_HOLD,
                self::CANCELLED,
            ],
            self::SHIPPED => [
                self::DELIVERED,
                self::ON_HOLD,
            ],
            self::DELIVERED => [
                self::COMPLETED,
                self::REFUNDED,
            ],
            self::ON_HOLD => [
                self::PROCESSING,
                self::CANCELLED,
                self::REFUNDED,
            ],
            // États terminaux (aucune transition possible)
            self::COMPLETED,
            self::CANCELLED,
            self::REFUNDED,
            self::FAILED => [],
        };
    }

    /**
     * Vérifie si une transition est autorisée.
     */
    public function canTransitionTo(OrderStatus $newStatus): bool
    {
        return in_array($newStatus, $this->getAllowedTransitions(), true);
    }

    // ===============================================
    // HELPERS - ÉTATS GROUPÉS
    // ===============================================

    /**
     * Vérifie si la commande est dans un état terminal.
     * États où plus aucune modification n'est possible.
     */
    public function isFinal(): bool
    {
        return in_array($this, [
            self::COMPLETED,
            self::CANCELLED,
            self::REFUNDED,
            self::FAILED,
        ], true);
    }

    /**
     * Vérifie si la commande est annulable.
     */
    public function isCancellable(): bool
    {
        return in_array($this, [
            self::PENDING,
            self::CONFIRMED,
            self::PROCESSING,
            self::ON_HOLD,
        ], true);
    }

    /**
     * Vérifie si la commande est remboursable.
     */
    public function isRefundable(): bool
    {
        return in_array($this, [
            self::DELIVERED,
            self::COMPLETED,
            self::ON_HOLD,
        ], true);
    }

    /**
     * Vérifie si la commande est en cours de traitement.
     */
    public function isInProgress(): bool
    {
        return in_array($this, [
            self::CONFIRMED,
            self::PROCESSING,
            self::SHIPPED,
        ], true);
    }

    /**
     * Vérifie si la commande a réussi (livrée ou complétée).
     */
    public function isSuccessful(): bool
    {
        return in_array($this, [
            self::DELIVERED,
            self::COMPLETED,
        ], true);
    }

    /**
     * Vérifie si la commande a échoué ou été annulée.
     */
    public function hasFailedOrCancelled(): bool
    {
        return in_array($this, [
            self::CANCELLED,
            self::REFUNDED,
            self::FAILED,
        ], true);
    }

    /**
     * Vérifie si le stock doit être décrémenté pour cet état.
     */
    public function shouldDecrementStock(): bool
    {
        return in_array($this, [
            self::CONFIRMED,
            self::PROCESSING,
            self::SHIPPED,
            self::DELIVERED,
            self::COMPLETED,
        ], true);
    }

    /**
     * Vérifie si le stock doit être re-crédité pour cet état.
     */
    public function shouldRestoreStock(): bool
    {
        return in_array($this, [
            self::CANCELLED,
            self::REFUNDED,
        ], true);
    }

    // ===============================================
    // HELPERS - NOTIFICATIONS
    // ===============================================

    /**
     * Vérifie si une notification client doit être envoyée.
     */
    public function shouldNotifyCustomer(): bool
    {
        return in_array($this, [
            self::CONFIRMED,
            self::SHIPPED,
            self::DELIVERED,
            self::CANCELLED,
            self::REFUNDED,
            self::ON_HOLD,
        ], true);
    }

    /**
     * Message de notification pour le client.
     */
    public function getCustomerNotificationMessage(): string
    {
        return match ($this) {
            self::CONFIRMED => 'Votre commande a été confirmée et sera bientôt préparée.',
            self::SHIPPED => 'Votre commande a été expédiée. Vous recevrez bientôt votre colis.',
            self::DELIVERED => 'Votre commande a été livrée. Merci pour votre confiance !',
            self::CANCELLED => 'Votre commande a été annulée.',
            self::REFUNDED => 'Votre commande a été remboursée.',
            self::ON_HOLD => 'Votre commande nécessite une action de votre part.',
            default => '',
        };
    }

    // ===============================================
    // STATIC HELPERS
    // ===============================================

    /**
     * Tous les états qui nécessitent un paiement valide.
     */
    public static function paidStatuses(): array
    {
        return [
            self::CONFIRMED,
            self::PROCESSING,
            self::SHIPPED,
            self::DELIVERED,
            self::COMPLETED,
            self::REFUNDED,
        ];
    }

    /**
     * Tous les états actifs (commande non terminée).
     */
    public static function activeStatuses(): array
    {
        return [
            self::PENDING,
            self::CONFIRMED,
            self::PROCESSING,
            self::SHIPPED,
            self::ON_HOLD,
        ];
    }
}
