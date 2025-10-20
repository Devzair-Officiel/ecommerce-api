<?php

declare(strict_types=1);

namespace App\Enum\User;

/**
 * Types d'adresses.
 */
enum AddressType: string
{
    case BILLING = 'billing';   // Adresse de facturation
    case SHIPPING = 'shipping'; // Adresse de livraison
    case BOTH = 'both';         // Utilisable pour les deux

    /**
     * Label traduit du type.
     */
    public function label(): string
    {
        return match ($this) {
            self::BILLING => 'Facturation',
            self::SHIPPING => 'Livraison',
            self::BOTH => 'Facturation et Livraison',
        };
    }

    /**
     * Vérifie si l'adresse peut être utilisée pour la facturation.
     */
    public function canBeUsedForBilling(): bool
    {
        return $this === self::BILLING || $this === self::BOTH;
    }

    /**
     * Vérifie si l'adresse peut être utilisée pour la livraison.
     */
    public function canBeUsedForShipping(): bool
    {
        return $this === self::SHIPPING || $this === self::BOTH;
    }
}
