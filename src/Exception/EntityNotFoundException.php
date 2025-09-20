<?php

namespace App\Exception;

use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Exception levée lorsqu'une entité n'est pas trouvée.
 * 
 * - Utilise une clé de traduction pour personnaliser le message d'erreur.
 * - Stocke les paramètres de traduction pour une meilleure internationalisation.
 */
class EntityNotFoundException extends NotFoundHttpException
{
    private array $translationParameters;

    /**
     * @param string $entityKey Clé de traduction représentant l'entité non trouvée.
     * @param array<string, mixed> $params Paramètres optionnels pour la traduction.
     * @param \Throwable|null $previous Exception précédente, si applicable.
     */
    public function __construct(string $entityKey, array $params = [], ?\Throwable $previous = null)
    {
        $this->translationParameters = $params + ['%entity%' => $entityKey];
        $message = 'entity.not_found'; // Clé de traduction par défaut

        parent::__construct($message, $previous, 404);
    }

    public function getTranslationParameters(): array
    {
        return $this->translationParameters;
    }
}
