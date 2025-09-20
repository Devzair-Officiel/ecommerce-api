<?php

declare(strict_types=1);

namespace App\Exception;

use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * Exception levée lorsqu'une entité ne peut pas être supprimée à cause de relations existantes.
 * Utilisable de manière générique avec système de traduction.
 */
class EntityDeletionProhibitedException extends ConflictHttpException
{
    private array $translationParameters;

    /**
     * @param string $entityKey Nom de l'entité que l'on tente de supprimer.
     * @param string $relatedEntityKey Nom de l'entité qui empêche la suppression.
     * @param array<string, mixed> $params Paramètres supplémentaires (ex: id).
     */
    public function __construct(string $entityKey, string $relatedEntityKey, array $params = [], ?\Throwable $previous = null)
    {
        $this->translationParameters = $params + [
            '%entity%' => $entityKey,
            '%related_entity%' => $relatedEntityKey,
        ];

        parent::__construct('entity.deletion_prohibited', $previous);
    }

    public function getTranslationParameters(): array
    {
        return $this->translationParameters;
    }
}
