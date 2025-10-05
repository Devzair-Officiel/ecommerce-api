<?php

declare(strict_types=1);

namespace App\Exception;


/**
 * Exception pour les erreurs de validation Symfony.
 * 
 * Utilisée quand les contraintes de validation (Assert) échouent.
 * Contient un tableau d'erreurs formatées par ValidationUtils.
 */
class ValidationException extends AppException
{
    public function __construct(
        private array $violations = [],
        string $messageKey = 'validation.failed',
        array $translationParameters = []
    ) {
        parent::__construct('Validation failed', $translationParameters);
    }

    public function getStatusCode(): int
    {
        return 422; // Unprocessable Entity (standard pour validation)
    }

    public function getMessageKey(): string
    {
        return 'validation.failed';
    }

    /**
     * Retourne les violations formatées.
     * Format : ['field' => [['message' => '...', 'code' => '...']], ...]
     */
    public function getFormattedErrors(): array
    {
        return $this->violations;
    }

    /** Alias pour compatibilité */
    public function getViolations(): array
    {
        return $this->violations;
    }

    /** Alias pour compatibilité */
    public function getErrors(): array
    {
        return $this->violations;
    }
}