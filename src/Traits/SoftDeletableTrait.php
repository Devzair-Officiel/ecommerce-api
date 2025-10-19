<?php

declare(strict_types=1);

namespace App\Traits;

use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Annotation\Groups;
use Symfony\Component\Serializer\Annotation\Context;
use Symfony\Component\Serializer\Normalizer\DateTimeNormalizer;

/**
 * Trait pour la suppression logique (soft delete).
 * 
 * Usage : Supprimer une entité sans la retirer physiquement de la BDD.
 * 
 * Avantages :
 * - Préserve l'intégrité référentielle (FK restent valides)
 * - Permet la restauration (annuler une suppression)
 * - Audit trail (qui a supprimé quoi et quand)
 * - Légal (RGPD : preuve de suppression)
 * - Analytics corrects (historique intact)
 * 
 * ⚠️ Une entité soft-deleted est INVISIBLE partout sauf :
 * - Interface admin (corbeille, restauration)
 * - Exports/historiques (pour audit)
 * 
 * Filtrage automatique : Utiliser Doctrine Filter pour exclure
 * les entités supprimées des requêtes par défaut.
 */
trait SoftDeletableTrait
{
    #[ORM\Column(type: 'boolean', options: ['default' => false])]
    #[Groups(['soft_delete'])]
    private bool $isDeleted = false;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    #[Context([DateTimeNormalizer::FORMAT_KEY => 'd/m/Y H:i:s'])]
    #[Groups(['soft_delete'])]
    private ?DateTimeImmutable $deletedAt = null;

    public function isDeleted(): bool
    {
        return $this->isDeleted;
    }

    public function setIsDeleted(bool $isDeleted): static
    {
        $this->isDeleted = $isDeleted;

        // Auto-set deletedAt lors de la suppression
        if ($isDeleted && $this->deletedAt === null) {
            $this->deletedAt = new DateTimeImmutable();
        }

        // Reset deletedAt lors de la restauration
        if (!$isDeleted) {
            $this->deletedAt = null;
        }

        return $this;
    }

    public function getDeletedAt(): ?DateTimeImmutable
    {
        return $this->deletedAt;
    }

    public function setDeletedAt(?DateTimeImmutable $deletedAt): static
    {
        $this->deletedAt = $deletedAt;
        return $this;
    }

    /**
     * Helper : Supprimer logiquement l'entité
     */
    public function softDelete(): static
    {
        return $this->setIsDeleted(true);
    }

    /**
     * Helper : Restaurer l'entité supprimée
     */
    public function restore(): static
    {
        return $this->setIsDeleted(false);
    }
}
