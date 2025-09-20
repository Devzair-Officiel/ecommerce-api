<?php

declare(strict_types=1);

namespace App\Utils;

use App\Exception\ValidationException;
use Symfony\Component\Serializer\SerializerInterface;
use Symfony\Component\Serializer\Normalizer\AbstractNormalizer;

/**
 * Utilitaire pour la désérialisation et la validation des objets.
 * 
 * - Désérialise un JSON en objet PHP.
 * - Valide l'objet désérialisé en utilisant `ValidationUtils`.
 * - Lève une `ValidationException` en cas d'erreur.
 */
class DeserializationUtils
{
    public function __construct(
        private SerializerInterface $serializer,
        private ValidationUtils $validatorUtils
    ) {}

    /**
     * Désérialise un JSON en objet et effectue une validation.
     *
     * @template T
     * @param string $json Le JSON à désérialiser.
     * @param class-string<T> $class Le nom de la classe cible.
     *
     * @throws ValidationException Si la validation échoue.
     *
     * @return T L'objet désérialisé.
     */
    public function deserializeAndValidate(string $json, string $class, ?object $existingObject = null): object
    {
        try {
            // Désérialisation dans une nouvelle entité ou une entité existante
            $object = $this->serializer->deserialize(
                $json,
                $class,
                'json',
                [AbstractNormalizer::OBJECT_TO_POPULATE => $existingObject]
            );
        } catch (\Throwable $e) {
            throw new ValidationException(
                errors: [['field' => 'json', 'message' => 'validation.json_invalid']],
                messageKey: 'validation.json_invalid',
                previous: $e
            );
        }

        // Validation des données
        $this->validatorUtils->validateConstraint($object);


        return $object;
    }
}
