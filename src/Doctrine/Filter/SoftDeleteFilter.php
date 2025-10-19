<?php

declare(strict_types=1);

namespace App\Doctrine\Filter;

use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\Query\Filter\SQLFilter;

/**
 * Filtre Doctrine pour exclure automatiquement les entités soft-deleted.
 * 
 * Activation globale : Toutes les requêtes excluent les entités où isDeleted = true.
 * Désactivation ponctuelle : Possible dans les repositories pour accès admin.
 */
class SoftDeleteFilter extends SQLFilter
{
    public function addFilterConstraint(ClassMetadata $targetEntity, $targetTableAlias): string
    {
        // Vérifier si l'entité utilise SoftDeletableTrait
        if (!$targetEntity->hasField('isDeleted')) {
            return '';
        }

        // Ajouter la condition WHERE isDeleted = false
        return sprintf('%s.is_deleted = false', $targetTableAlias);
    }
}
