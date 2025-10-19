<?php

declare(strict_types=1);

namespace App\Enum\Site;

/**
 * Statuts possibles pour un site (multi-tenant).
 */
enum SiteStatus: string
{
    case ACTIVE = 'active';       // Site actif et accessible
    case INACTIVE = 'inactive';   // Site temporairement désactivé
    case MAINTENANCE = 'maintenance'; // Site en maintenance
    case ARCHIVED = 'archived';   // Site archivé (ancien client)

    /**
     * Retourne le label traduit du statut.
     */
    public function label(): string
    {
        return match ($this) {
            self::ACTIVE => 'Actif',
            self::INACTIVE => 'Inactif',
            self::MAINTENANCE => 'En maintenance',
            self::ARCHIVED => 'Archivé',
        };
    }

    /**
     * Retourne les statuts accessibles publiquement.
     */
    public static function publicStatuses(): array
    {
        return [self::ACTIVE];
    }
}
