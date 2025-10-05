<?php

declare(strict_types=1);

namespace App\Exception;

/**
 * Exception pour les entités non trouvées.
 */
class EntityNotFoundException extends AppException
{
    public function __construct(
        private string $entityClass,
        private array $criteria,
        string $message = ''
    ) {
        $entityName = $this->getEntityName($entityClass);
        $message = $message ?: "Entity '{$entityName}' not found";

        parent::__construct($message, [
            'entity' => $entityName,
            'criteria' => $criteria
        ]);
    }

    public function getStatusCode(): int
    {
        return 404;
    }

    public function getMessageKey(): string
    {
        return 'entity.not_found';
    }

    public function getEntityClass(): string
    {
        return $this->entityClass;
    }

    public function getCriteria(): array
    {
        return $this->criteria;
    }

    private function getEntityName(string $entityClass): string
    {
        $parts = explode('\\', $entityClass);
        return strtolower(end($parts));
    }
}