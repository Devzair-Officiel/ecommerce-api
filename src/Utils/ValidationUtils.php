<?php

declare(strict_types=1);

namespace App\Utils;

use App\Exception\ValidationException;
use Symfony\Component\Validator\ConstraintViolationListInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * Utilitaire pour la validation des objets avec Symfony Validator.
 * 
 * - Valide un objet en appliquant ses contraintes de validation.
 * - Formate les erreurs de validation en tableau structuré.
 */
final class ValidationUtils
{
    public function __construct(private ValidatorInterface $validator) {}

    /**
     * Formate les erreurs de validation en un tableau structuré.
     *
     * @param ConstraintViolationListInterface $errors Liste des erreurs de validation.
     *
     * @return array Un tableau contenant les erreurs formatées.
     */
    public static function formatValidationErrors(ConstraintViolationListInterface $errors): array
    {
        return array_map(
            static fn($violation) => [
                'code' => 'validation_error', // Code d'erreur générique pour les violations
                'message' => $violation->getMessage(), // Message d'erreur (ex : "This value should not be blank.")
                'field' => $violation->getPropertyPath(), // Le champ concerné par l'erreur (ex : "email", "password")
            ],
            iterator_to_array($errors) // Convertit la liste en tableau pour pouvoir l'itérer
        );
    }

    /**
     * Valide les contraintes de validation sur un objet et lève une exception en cas d'erreur.
     *
     * @param object $data L'objet à valider.
     *
     * @throws ValidationException Si des erreurs de validation sont détectées.
     */
    public function validateConstraint(object $data): void
    {
        $errors = $this->validator->validate($data);
        if (count($errors) > 0) {
            $formattedErrors = ValidationUtils::formatValidationErrors($errors);
            throw new ValidationException(
                $formattedErrors,
                translationParameters: ['%field%' => $formattedErrors[0]['field']]
            );
        }
    }
}
