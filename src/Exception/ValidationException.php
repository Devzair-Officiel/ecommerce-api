<?php

declare(strict_types=1);

namespace App\Exception;

use Symfony\Component\HttpKernel\Exception\HttpException;

class ValidationException extends HttpException
{

    public function __construct(
        private array $errors, // Tableau des erreurs
        string $messageKey = 'validation.failed', // Clé de traduction du message principal
        int $statusCode = 400,
        \Throwable $previous = null,
        private array $translationParameters = [] // Paramètres pour le message principal
    ) {
        parent::__construct($statusCode, $messageKey, $previous);
    }

    public function getErrors(): array
    {
        return $this->errors;
    }

    public function getTranslationParameters(): array
    {
        return $this->translationParameters;
    }
}
