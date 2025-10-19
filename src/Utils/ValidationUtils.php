<?php

declare(strict_types=1);

namespace App\Utils;

use Symfony\Component\Validator\ConstraintViolationListInterface;

/**
 * Utilitaire simplifié pour la validation.
 * 
 * Ne garde que le formatage des erreurs qui est utile pour l'API.
 */
final class ValidationUtils
{
    /**
     * Formate les erreurs de validation en tableau structuré pour l'API.
     *
     * @param ConstraintViolationListInterface $errors Liste des erreurs de validation
     * @return array Tableau des erreurs formatées
     */
    public static function formatValidationErrors(ConstraintViolationListInterface $errors): array
    {
        $formattedErrors = [];

        foreach ($errors as $violation) {
            $field = $violation->getPropertyPath() ?: 'general';

            // Grouper les erreurs par champ
            if (!isset($formattedErrors[$field])) {
                $formattedErrors[$field] = [];
            }

            $formattedErrors[$field][] = [
                'code' => 'validation_error',
                'message' => $violation->getMessage(),
                'invalid_value' => $violation->getInvalidValue()
            ];
        }

        return $formattedErrors;
    }

    /**
     * Formate les erreurs en structure plate pour compatibilité.
     */
    public static function formatValidationErrorsFlat(ConstraintViolationListInterface $errors): array
    {
        return array_map(
            static fn($violation) => [
                'code' => 'validation_error',
                'message' => $violation->getMessage(),
                'field' => $violation->getPropertyPath() ?: 'general',
            ],
            iterator_to_array($errors)
        );
    }
}
