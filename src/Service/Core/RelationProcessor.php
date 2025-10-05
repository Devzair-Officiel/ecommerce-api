<?php

declare(strict_types=1);

namespace App\Service\Core;

use Doctrine\Inflector\Inflector;
use Doctrine\Inflector\InflectorFactory;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Service dédié à la gestion des relations.
 * 
 * Responsabilité unique : traiter les associations entre entités
 * de manière générique et réutilisable.
 */
class RelationProcessor
{
    private Inflector $inflector;

    public function __construct(private readonly EntityManagerInterface $em)
    {
        $this->inflector = InflectorFactory::create()->build();
    }

    /**
     * Traite toutes les relations d'une entité selon sa configuration.
     * 
     * @param object $entity L'entité à traiter
     * @param array $data Les données contenant les relations
     * @param array $relationConfig Configuration des relations
     * @param string $operation 'create' ou 'update'
     */
    public function processEntityRelations(object $entity, array $data, array $relationConfig, string $operation):void
    {
        foreach($relationConfig as $property => $config) {
            if(!array_key_exists($property, $data)) {
                continue; // Proprieté non présente dans les données
            }
            $this->processRelation($entity, $property, $data[$property], $config, $operation);
        }
    }

    /**
     * Traite une relation spécifique.
     */
    private function processRelation(object $entity, string $property, mixed $value, array $config, string $operation): void
    {
        match ($config['type']) {
            'many_to_one' => $this->processManyToOne($entity, $property, $value, $config),
            'many_to_many' => $this->processManyToMany($entity, $property, $value, $config, $operation),
            'one_to_many' => $this->processOneToMany($entity, $property, $value, $config, $operation),
            default => throw new \InvalidArgumentException("Unsupported relation type: {$config['type']}")
        };
    }

    /**
     * Traite les relations Many-to-One.
     */
    private function processManyToOne(object $entity, string $property, mixed $value, array $config): void
    {
        $setter = 'set' . ucfirst($property);

        if (!method_exists($entity, $setter)) {
            return;
        }

        if ($value === null) {
            $entity->$setter(null);
            return;
        }

        // Récupération de l'entité liée
        $relatedEntity = $this->em->find($config['target_entity'], $value);
        if ($relatedEntity) {
            $entity->$setter($relatedEntity);
        }
    }

    /**
     * Traite les relations Many-to-Many.
     */
    private function processManyToMany(object $entity, string $property, mixed $value, array $config, string $operation): void
    {
        if (!is_array($value)) {
            return;
        }

        // 1) Déduire le singulier (ou autoriser un override via config)
        $singular = $config['singular'] ?? $this->inflector->singularize($property);

        // 2) Construire les noms de méthodes
        $adder   = 'add'    . ucfirst($singular);
        $remover = 'remove' . ucfirst($singular);
        $getter  = 'get'    . ucfirst($property);

        // 3) Fallback si les méthodes n’existent pas avec le singulier
        if (!method_exists($entity, $adder)) {
            $adder = 'add' . ucfirst($property); // ex. addRoles si tu as choisi ça
        }
        if (!method_exists($entity, $remover)) {
            $remover = 'remove' . ucfirst($property);
        }
        if (!method_exists($entity, $getter)) {
            return;
        }

        // 4) Clear existant sur update
        if ($operation === 'update' && method_exists($entity, $remover)) {
            $collection = $entity->$getter();
            foreach ($collection->toArray() as $item) {
                $entity->$remover($item);
            }
        }

        // 5) Accepter ids ou objets {id: ...}
        $idKey = $config['identifier_key'] ?? 'id';

        foreach ($value as $item) {
            $relatedId = is_array($item) ? ($item[$idKey] ?? null) : $item;
            if ($relatedId === null) {
                continue;
            }
            $relatedEntity = $this->em->find($config['target_entity'] ?? $config['targetEntity'], $relatedId);
            if ($relatedEntity) {
                $entity->$adder($relatedEntity);
            }
        }
    }

    /**
     * Traite les relations One-to-Many (moins courant en input).
     */
    private function processOneToMany(object $entity, string $property, mixed $value, array $config, string $operation): void
    {
        // Implémentation si nécessaire selon les besoins
        // Généralement, on gère plutôt les OneToMany depuis l'autre côté
    }
}