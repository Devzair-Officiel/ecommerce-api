<?php

declare(strict_types=1);

namespace App\Exception;

use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Exception levée en cas de problème de pagination.
 * 
 * - Utilise une clé de traduction pour personnaliser le message d'erreur.
 * - Permet d'ajouter des paramètres de traduction pour une meilleure internationalisation.
 */
class PaginationException extends HttpException
{
    private array $translationParameters;

    /**
     * @param string $messageKey Clé de traduction pour le message d'erreur (par défaut : `pagination.invalid_page`).
     * @param int $statusCode Code HTTP de l'erreur (par défaut : 400).
     * @param array<string, mixed> $translationParameters Paramètres optionnels pour la traduction.
     * @param \Throwable|null $previous Exception précédente, si applicable.
     */
    public function __construct(
        string $messageKey = 'pagination.invalid_page',
        int $statusCode = 400,
        array $translationParameters = [], // Ajout des paramètres pour la traduction
        ?\Throwable $previous = null
    ) {
        $this->translationParameters = $translationParameters;

        parent::__construct($statusCode, $messageKey, $previous);
    }

    public function getTranslationParameters(): array
    {
        return $this->translationParameters;
    }
}
