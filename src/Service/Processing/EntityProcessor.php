<?php

declare(strict_types=1);

namespace App\Service\Processing;

use App\Exception\ValidationException;
use App\Utils\ValidationUtils;
use ReflectionClass;
use Symfony\Component\Serializer\SerializerInterface;
use Symfony\Component\Serializer\Normalizer\AbstractNormalizer;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Service unifié pour le traitement d'entités :
 * - Désérialisation JSON -> Objet
 * - Validation des contraintes
 * - Hydratation manuelle via réflexion
 * 
 * Remplace : DeserializationUtils, EntityHydrator, JsonValidationUtils
 */
class EntityProcessor
{
    public function __construct(
        private SerializerInterface $serializer,
        private ValidationUtils $validationUtils,
        private TranslatorInterface $translator
    ) {}

    /**
     * Méthode principale : désérialise et valide en une seule étape.
     */
    public function processFromJson(
        string $json,
        string $entityClass,
        ?object $existingEntity = null
    ): object {
        // 1. Désérialisation
        $entity = $this->deserializeJson($json, $entityClass, $existingEntity);

        // 2. Validation des contraintes
        $this->validationUtils->validateConstraint($entity);

        return $entity;
    }

    /**
     * Hydratation manuelle avec validation des clés JSON.
     */
    public function hydrateFromArray(
        array $data,
        string $entityClass,
        ?object $existingEntity = null,
        array $excludedProperties = ['id', 'createdAt', 'updatedAt']
    ): object {
        // Validation des clés
        $this->validateJsonKeys($data, $entityClass, $excludedProperties);

        // Hydratation
        $entity = $this->hydrateObject($data, $entityClass, $excludedProperties, $existingEntity);

        // Validation des contraintes
        $this->validationUtils->validateConstraint($entity);

        return $entity;
    }

    /**
     * Vérifie que les clés JSON correspondent aux propriétés de la classe.
     */
    public function validateJsonKeys(array $data, string $entityClass, array $excludedProperties = []): void
    {
        $invalidKeys = $this->getInvalidKeys($data, $entityClass, $excludedProperties);

        if (!empty($invalidKeys)) {
            throw new ValidationException(
                errors: array_map(
                    fn($key) => [
                        'field' => $key,
                        'message' => $this->translator->trans('validation.invalid_field', ['%field%' => $key])
                    ],
                    $invalidKeys
                ),
                messageKey: 'validation.invalid_fields'
            );
        }
    }

    // === Méthodes privées ===

    private function deserializeJson(string $json, string $entityClass, ?object $existingEntity): object
    {
        try {
            return $this->serializer->deserialize(
                $json,
                $entityClass,
                'json',
                [AbstractNormalizer::OBJECT_TO_POPULATE => $existingEntity]
            );
        } catch (\Throwable $e) {
            throw new ValidationException(
                errors: [['field' => 'json', 'message' => 'validation.json_invalid']],
                messageKey: 'validation.json_invalid',
                previous: $e
            );
        }
    }

    private function getInvalidKeys(array $data, string $entityClass, array $excludedProperties): array
    {
        $reflectionClass = new ReflectionClass($entityClass);
        $classProperties = array_map(
            fn($property) => $property->getName(),
            $reflectionClass->getProperties()
        );

        $allowedProperties = array_diff($classProperties, $excludedProperties);
        return array_diff(array_keys($data), $allowedProperties);
    }

    private function hydrateObject(
        array $data,
        string $entityClass,
        array $excludedProperties,
        ?object $instance = null
    ): object {
        $object = $instance ?? new $entityClass();
        $reflectionClass = new ReflectionClass($entityClass);

        foreach ($data as $key => $value) {
            if (in_array($key, $excludedProperties) || !$reflectionClass->hasProperty($key)) {
                continue;
            }

            $property = $reflectionClass->getProperty($key);
            $property->setAccessible(true);

            $transformedValue = $this->transformValue($value, $property->getType());
            $property->setValue($object, $transformedValue);
        }

        return $object;
    }

    private function transformValue(mixed $value, ?\ReflectionType $type): mixed
    {
        if (!$type || $value === null) {
            return $value;
        }

        if ($type instanceof \ReflectionNamedType) {
            return match ($type->getName()) {
                'int' => (int) $value,
                'float' => (float) $value,
                'bool' => (bool) $value,
                'string' => (string) $value,
                '\DateTimeImmutable' => $this->parseDateTime($value),
                '\DateTime' => $this->parseDateTime($value, false),
                default => $value
            };
        }

        return $value;
    }

    private function parseDateTime(mixed $value, bool $immutable = true): mixed
    {
        if (is_string($value)) {
            try {
                $dateTime = new \DateTime($value);
                return $immutable ? \DateTimeImmutable::createFromMutable($dateTime) : $dateTime;
            } catch (\Exception) {
                return $value;
            }
        }
        return $value;
    }
}
