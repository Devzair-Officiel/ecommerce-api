<?php

declare(strict_types=1);

namespace App\Exception;

/**
 * Exception pour les ressources en conflit.
 * 
 * Ex: Tentative de création d'un email déjà existant.
 */
class ConflictException extends AppException
{
    public function __construct(
        string $resource,
        string $conflictField,
        mixed $conflictValue,
        array $context = []
    ) {
        $message = "Conflict: {$resource} with {$conflictField} '{$conflictValue}' already exists";

        parent::__construct($message, array_merge($context, [
            'resource' => $resource,
            'conflict_field' => $conflictField,
            'conflict_value' => $conflictValue
        ]));
    }

    public function getStatusCode(): int
    {
        return 409; // Conflict
    }

    public function getMessageKey(): string
    {
        return 'resource.conflict';
    }
}