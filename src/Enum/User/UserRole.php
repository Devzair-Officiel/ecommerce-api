<?php

declare(strict_types=1);

namespace App\Enum\User;

/**
 * Rôles utilisateur pour la gestion des permissions.
 * 
 * Hiérarchie (du moins au plus privilégié) :
 * USER < MODERATOR < ADMIN < SUPER_ADMIN
 */
enum UserRole: string
{
    case ROLE_USER = 'ROLE_USER';               // Client standard
    case ROLE_MODERATOR = 'ROLE_MODERATOR';     // Modérateur (validation reviews, products...)
    case ROLE_ADMIN = 'ROLE_ADMIN';             // Admin site (gestion complète d'un site)
    case ROLE_SUPER_ADMIN = 'ROLE_SUPER_ADMIN'; // Super admin (multi-site, config globale)

    /**
     * Label traduit du rôle.
     */
    public function label(): string
    {
        return match ($this) {
            self::ROLE_USER => 'Client',
            self::ROLE_MODERATOR => 'Modérateur',
            self::ROLE_ADMIN => 'Administrateur',
            self::ROLE_SUPER_ADMIN => 'Super Administrateur',
        };
    }

    /**
     * Retourne les rôles accessibles publiquement (non-admin).
     */
    public static function publicRoles(): array
    {
        return [self::ROLE_USER];
    }

    /**
     * Retourne les rôles administratifs.
     */
    public static function adminRoles(): array
    {
        return [self::ROLE_MODERATOR, self::ROLE_ADMIN, self::ROLE_SUPER_ADMIN];
    }

    /**
     * Vérifie si le rôle est administratif.
     */
    public function isAdmin(): bool
    {
        return in_array($this, self::adminRoles(), true);
    }

    /**
     * Vérifie si le rôle peut gérer plusieurs sites.
     */
    public function canManageMultipleSites(): bool
    {
        return $this === self::ROLE_SUPER_ADMIN;
    }
}
