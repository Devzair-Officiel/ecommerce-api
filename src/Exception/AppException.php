<?php

declare(strict_types=1);

namespace App\Exception;

/**
 * Exception de base pour toutes les exceptions métier de l'application.
 */
abstract class AppException extends \Exception
{
    public function __construct(
        string $message = '',
        protected array $context = [],
        int $code = 0,
        ?\Throwable $previous = null
    ) {
        parent::__construct($message, $code, $previous);
    }

    public function getContext(): array
    {
        return $this->context;
    }

    /** Code HTTP par défaut pour cette exception */
    abstract public function getStatusCode(): int;

    /** Clé de traduction pour le message */
    abstract public function getMessageKey(): string;

    /** Paramètres pour la traduction */
    public function getMessageParameters(): array
    {
        return $this->context;
    }
}
