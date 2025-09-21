<?php

declare(strict_types=1);

namespace App\Service\Processing;

use App\Exception\EntityNotFoundException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\PropertyAccess\PropertyAccessorInterface;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

/**
 * Gestionnaire de relations entre entités utilisant PropertyAccessor de Symfony.
 *
 * Cette classe centralise la logique de gestion des relations (associations, mises à jour)
 * pour éviter la duplication de code dans les services métier.
 *
 * Fonctionnalités principales :
 * - Association automatique d'entités via configuration déclarative
 * - Gestion des collections Doctrine (OneToMany, ManyToMany)
 * - Gestion des relations simples (ManyToOne)
 * - Création conditionnelle d'entités manquantes
 * - Validation des données de relation
 */
class RelationManager
{
    public function __construct(
        private EntityManagerInterface $em,
        private PropertyAccessorInterface $propertyAccessor
    ) {}

    /**
     * Traite les relations d'une entité selon une configuration donnée.
     * 
     * @param object $entity L'entité principale
     * @param array $relationConfig Configuration des relations
     * @param array $jsonData Données JSON de la requête
     */
    public function processEntityRelations(object $entity, array $relationConfig, array $jsonData): void
    {
        foreach ($relationConfig as $fieldName => $config) {
            if (!isset($jsonData[$fieldName])) {
                continue; // Champ absent = pas obligatoire
            }

            $this->associateEntities(
                mainEntity: $entity,
                relationField: $fieldName,
                relatedEntityClass: $config['targetEntity'],
                identifierKey: $config['identifierKey'] ?? 'id',
                relationData: $jsonData[$fieldName],
                allowCreation: $config['allowCreation'] ?? false
            );
        }
    }

    /**
     * Met à jour les relations d'une entité.
     */
    public function updateEntityRelations(object $entity, array $relationConfig, array $jsonData): void
    {
        foreach ($relationConfig as $fieldName => $config) {
            if (!array_key_exists($fieldName, $jsonData)) {
                continue; // Champ absent = pas de modification
            }

            $this->updateRelation(
                mainEntity: $entity,
                relationField: $fieldName,
                relatedEntityClass: $config['targetEntity'],
                identifierKey: $config['identifierKey'] ?? 'id',
                newRelationData: $jsonData[$fieldName],
                allowCreation: $config['allowCreation'] ?? false
            );
        }
    }

    /**
     * Associe des entités à une relation.
     */
    private function associateEntities(
        object $mainEntity,
        string $relationField,
        string $relatedEntityClass,
        string $identifierKey,
        mixed $relationData,
        bool $allowCreation
    ): void {
        if (empty($relationData)) {
            return;
        }

        // Vérifier que la propriété est accessible
        if (!$this->propertyAccessor->isWritable($mainEntity, $relationField)) {
            throw new \LogicException(sprintf(
                "La propriété '%s' n'est pas accessible en écriture sur %s",
                $relationField,
                get_class($mainEntity)
            ));
        }

        $dataArray = $this->normalizeRelationData($relationData);
        $repository = $this->em->getRepository($relatedEntityClass);

        foreach ($dataArray as $item) {
            $this->validateRelationItem($item, $identifierKey);

            $identifierValue = $item[$identifierKey];
            $relatedEntity = $repository->findOneBy([$identifierKey => $identifierValue]);

            if (!$relatedEntity) {
                $relatedEntity = $this->createEntityIfAllowed(
                    entityClass: $relatedEntityClass,
                    identifierKey: $identifierKey,
                    identifierValue: $identifierValue,
                    allowCreation: $allowCreation
                );
            }

            $this->addToRelation($mainEntity, $relationField, $relatedEntity);
        }
    }

    /**
     * Met à jour une relation complète.
     */
    private function updateRelation(
        object $mainEntity,
        string $relationField,
        string $relatedEntityClass,
        string $identifierKey,
        mixed $newRelationData,
        bool $allowCreation
    ): void {
        // Suppression explicite
        if ($newRelationData === null) {
            $this->propertyAccessor->setValue($mainEntity, $relationField, null);
            return;
        }

        $currentValue = $this->propertyAccessor->getValue($mainEntity, $relationField);

        if ($this->isCollection($currentValue)) {
            $this->updateCollectionRelation(
                mainEntity: $mainEntity,
                relationField: $relationField,
                relatedEntityClass: $relatedEntityClass,
                identifierKey: $identifierKey,
                newRelationData: $newRelationData,
                allowCreation: $allowCreation
            );
        } else {
            $this->updateSingleRelation(
                mainEntity: $mainEntity,
                relationField: $relationField,
                relatedEntityClass: $relatedEntityClass,
                identifierKey: $identifierKey,
                relationData: $newRelationData,
                allowCreation: $allowCreation
            );
        }
    }

    /**
     * Ajoute une entité à une relation.
     */
    private function addToRelation(object $mainEntity, string $relationField, object $relatedEntity): void
    {
        $currentValue = $this->propertyAccessor->getValue($mainEntity, $relationField);

        if ($this->isCollection($currentValue)) {
            if (!$currentValue->contains($relatedEntity)) {
                $currentValue->add($relatedEntity);
            }
        } else {
            $this->propertyAccessor->setValue($mainEntity, $relationField, $relatedEntity);
        }
    }

    /**
     * Met à jour une collection en la vidant puis la repeupler.
     */
    private function updateCollectionRelation(
        object $mainEntity,
        string $relationField,
        string $relatedEntityClass,
        string $identifierKey,
        array $newRelationData,
        bool $allowCreation
    ): void {
        $currentCollection = $this->propertyAccessor->getValue($mainEntity, $relationField);
        $currentCollection->clear();

        $repository = $this->em->getRepository($relatedEntityClass);

        foreach ($newRelationData as $item) {
            $this->validateRelationItem($item, $identifierKey);

            $identifierValue = $item[$identifierKey];
            $relatedEntity = $repository->findOneBy([$identifierKey => $identifierValue]);

            if (!$relatedEntity) {
                $relatedEntity = $this->createEntityIfAllowed(
                    entityClass: $relatedEntityClass,
                    identifierKey: $identifierKey,
                    identifierValue: $identifierValue,
                    allowCreation: $allowCreation
                );
            }

            $currentCollection->add($relatedEntity);
        }
    }

    /**
     * Met à jour une relation simple.
     */
    private function updateSingleRelation(
        object $mainEntity,
        string $relationField,
        string $relatedEntityClass,
        string $identifierKey,
        array $relationData,
        bool $allowCreation
    ): void {
        $this->validateRelationItem($relationData, $identifierKey);

        $repository = $this->em->getRepository($relatedEntityClass);
        $identifierValue = $relationData[$identifierKey];
        $relatedEntity = $repository->findOneBy([$identifierKey => $identifierValue]);

        if (!$relatedEntity) {
            $relatedEntity = $this->createEntityIfAllowed(
                entityClass: $relatedEntityClass,
                identifierKey: $identifierKey,
                identifierValue: $identifierValue,
                allowCreation: $allowCreation
            );
        }

        $this->propertyAccessor->setValue($mainEntity, $relationField, $relatedEntity);
    }

    // === MÉTHODES UTILITAIRES ===

    private function isCollection(mixed $value): bool
    {
        return $value instanceof \Doctrine\Common\Collections\Collection;
    }

    private function normalizeRelationData(mixed $relationData): array
    {
        if (!is_array($relationData)) {
            throw new BadRequestHttpException("Format de relation invalide");
        }

        // Si c'est un objet associatif unique, l'encapsuler
        if (array_keys($relationData) !== range(0, count($relationData) - 1)) {
            return [$relationData];
        }

        return $relationData;
    }

    private function validateRelationItem(array $item, string $identifierKey): void
    {
        if (!isset($item[$identifierKey])) {
            throw new BadRequestHttpException(
                "Le champ '{$identifierKey}' est requis pour la relation"
            );
        }
    }

    private function createEntityIfAllowed(
        string $entityClass,
        string $identifierKey,
        mixed $identifierValue,
        bool $allowCreation
    ): object {
        if (!$allowCreation) {
            throw new EntityNotFoundException(
                strtolower(basename(str_replace('\\', '/', $entityClass))),
                [$identifierKey => $identifierValue]
            );
        }

        $entity = new $entityClass();

        if ($this->propertyAccessor->isWritable($entity, $identifierKey)) {
            $this->propertyAccessor->setValue($entity, $identifierKey, $identifierValue);
        }

        $this->em->persist($entity);
        return $entity;
    }
}