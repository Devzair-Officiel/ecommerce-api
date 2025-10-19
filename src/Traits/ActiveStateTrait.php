<?php

declare(strict_types=1);

namespace App\Traits;

use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Annotation\Groups;
use Symfony\Component\Serializer\Annotation\Context;
use Symfony\Component\Serializer\Normalizer\DateTimeNormalizer;

/**
 * Trait pour gérer l'état actif/inactif d'une entité.
 * 
 * Usage : Désactivation temporaire ou permanente, archivage, retrait de vente.
 * 
 * Exemples :
 * - Produit retiré temporairement (closedAt = now, réversible)
 * - User banni (closedAt = now)
 * - Promotion expirée (closedAt = date d'expiration)
 * 
 * ⚠️ Différence avec SoftDelete :
 * - ActiveState : L'entité reste VISIBLE mais NON DISPONIBLE
 * - SoftDelete : L'entité devient INVISIBLE (comme supprimée)
 * 
 * Logique :
 * - closedAt = NULL → Entité ACTIVE
 * - closedAt = DATE → Entité INACTIVE depuis cette date
 */
trait ActiveStateTrait
{
    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    #[Context([DateTimeNormalizer::FORMAT_KEY => 'd/m/Y H:i:s'])]
    #[Groups(['active_state'])]
    private ?DateTimeImmutable $closedAt = null;

    public function getClosedAt(): ?DateTimeImmutable
    {
        return $this->closedAt;
    }

    public function setClosedAt(?DateTimeImmutable $closedAt): static
    {
        $this->closedAt = $closedAt;
        return $this;
    }

    /**
     * Vérifie si l'entité est active.
     */
    public function isActive(): bool
    {
        return $this->closedAt === null;
    }

    /**
     * Vérifie si l'entité est inactive.
     */
    public function isInactive(): bool
    {
        return $this->closedAt !== null;
    }

    /**
     * Helper : Activer l'entité
     */
    public function activate(): static
    {
        $this->closedAt = null;
        return $this;
    }

    /**
     * Helper : Désactiver l'entité
     */
    public function deactivate(): static
    {
        $this->closedAt = new DateTimeImmutable();
        return $this;
    }

    /**
     * Helper : Archiver l'entité (alias de deactivate)
     */
    public function archive(): static
    {
        return $this->deactivate();
    }
}
