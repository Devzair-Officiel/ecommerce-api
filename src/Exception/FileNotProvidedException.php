<?php

declare(strict_types=1);

namespace App\Exception;

use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Exception levée lorsqu'aucun fichier n'est fourni lors de l'upload.
 */
class FileNotProvidedException extends HttpException
{
    public function __construct(
        string $messageKey = 'error.no_file',
        int $statusCode = 400,
        ?\Throwable $previous = null,
        private array $translationParameters = []
    ) {
        parent::__construct($statusCode, $messageKey, $previous);
    }

    public function getTranslationParameters(): array
    {
        return $this->translationParameters;
    }
}
