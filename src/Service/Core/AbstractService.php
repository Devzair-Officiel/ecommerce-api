<?php

declare(strict_types=1);

namespace App\Service\Core;

use DateTimeImmutable;
use App\Utils\ValidationUtils;
use App\Exception\ConflictException;
use App\Exception\ValidationException;
use App\Service\Core\RelationProcessor;
use App\Exception\BusinessRuleException;
use Doctrine\ORM\EntityManagerInterface;
use App\Exception\EntityNotFoundException;
use Symfony\Component\Serializer\SerializerInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * Service CRUD simplifié et moderne.
 * 
 * Philosophie :
 * - CRUD simple par défaut 
 * - Hooks optionnels seulement si nécessaire
 * - Transactions optionnelles
 * - Relations gérées par un service dédié
 * - Utilisation native des outils Symfony
 */
abstract class AbstractService
{
    public function __construct(
        protected readonly EntityManagerInterface $em,
        protected readonly SerializerInterface $serializer,
        protected readonly ValidatorInterface $validator,
        protected readonly RelationProcessor $relationProcessor
    ) {}

    abstract protected function getEntityClass(): string;
    abstract protected function getRepository();

    // ===============================================
    // CRUD SIMPLE (la majorité des cas)
    // ===============================================

    /**
     * Création simple - la plupart de tes entités utiliseront ça.
     */
    public function create(array $data, array $context = []): object
    {
        $entity = $this->deserializeToEntity($data);
        $this->validateEntity($entity);

        $this->processRelations($entity, $data, 'create');

        $this->em->persist($entity);
        $this->em->flush();

        return $entity;
    }

    /**
     * Mise à jour simple.
     */
    public function update(int $id, array $data, array $context = []): object
    {
        $entity = $this->findEntityById($id);

        $this->deserializeToEntity($data, $entity);
        $this->validateEntity($entity);

        $this->processRelations($entity, $data, 'update');

        $this->em->flush();

        return $entity;
    }

    /**
     * Suppression simple (soft delete si supporté).
     */
    public function delete(int $id): object
    {
        $entity = $this->findEntityById($id);

        if (method_exists($entity, 'setIsDeleted')) {
            $entity->setIsDeleted(true);
            $this->em->flush();
        } else {
            $this->em->remove($entity);
            $this->em->flush();
        }

        return $entity;
    }

    // ===============================================
    // CRUD AVEC HOOKS (pour cas complexes)
    // ===============================================

    /**
     * Création avec hooks et transaction - pour les services qui en ont besoin.
     */
    public function createWithHooks(array $data, array $context = []): object
    {
        return $this->em->wrapInTransaction(function () use ($data, $context) {
            $entity = $this->deserializeToEntity($data);

            $this->beforeCreate($entity, $data, $context);
            $this->validateEntity($entity);
            $this->processRelations($entity, $data, 'create');

            $this->em->persist($entity);
            $this->em->flush();

            $this->afterCreate($entity, $context);

            return $entity;
        });
    }

    /**
     * Mise à jour avec hooks et transaction.
     */
    public function updateWithHooks(int $id, array $data, array $context = []): object
    {
        return $this->em->wrapInTransaction(function () use ($id, $data, $context) {
            $entity = $this->findEntityById($id);
            $previousState = clone $entity;

            $this->deserializeToEntity($data, $entity);

            $this->beforeUpdate($entity, $data, array_merge($context, ['previous_state' => $previousState]));
            $this->validateEntity($entity);
            $this->processRelations($entity, $data, 'update');

            $this->em->flush();

            $this->afterUpdate($entity, $context);

            return $entity;
        });
    }

    /**
     * Suppression avec hooks et transaction.
     */
    public function deleteWithHooks(int $id, array $context = []): object
    {
        return $this->em->wrapInTransaction(function () use ($id, $context) {
            $entity = $this->findEntityById($id);

            $this->beforeDelete($entity, $context);

            if (method_exists($entity, 'setIsDeleted')) {
                $entity->setIsDeleted(true);
                $this->em->flush();
            } else {
                $this->em->remove($entity);
                $this->em->flush();
            }

            $this->afterDelete($entity, $context);

            return $entity;
        });
    }

    // ===============================================
    // RECHERCHE & DESACTIVATION
    // ===============================================

    public function findEntityById(int $id): object
    {
        $entity = $this->em->find($this->getEntityClass(), $id);

        if (!$entity) {
            throw new EntityNotFoundException($this->getEntityClass(), ['id' => $id]);
        }

        return $entity;
    }

    public function findByCriteria(array $criteria): ?object
    {
        return $this->getRepository()->findOneBy($criteria);
    }

    public function search(int $page, int $limit, array $filters = []): array
    {
        return $this->getRepository()->findWithPagination($page, $limit, $filters);
    }

    public function toggleStatus(int $id, bool $isValid)
    {
        $entity = $this->findEntityById($id);

        // Verifier que l'entité supporte IsValid
        if (!method_exists($entity, 'setIsValid')) {
            throw new \BadMethodCallException(
                sprintf('Entity %s does not support status management', get_class($entity))
            );
        }

        $entity->setIsValid($isValid);

        if ($entity->isValid() === true) {
            $entity->setClosedAt(null);
        } else {
            $entity->setClosedAt(new DateTimeImmutable());
        }

        $this->em->flush();

        // Hook Après changement
        $this->afterStatusChange($entity, $isValid);

        return $entity;
    }

    // ===============================================
    // HOOKS OPTIONNELS (par défaut vides)
    // ===============================================

    protected function beforeCreate(object $entity, array $data, array $context): void {}
    protected function afterCreate(object $entity, array $context): void {}
    protected function beforeUpdate(object $entity, array $data, array $context): void {}
    protected function afterUpdate(object $entity, array $context): void {}
    protected function beforeDelete(object $entity, array $context): void {}
    protected function afterDelete(object $entity, array $context): void {}
    protected function beforeStatusChange(object $entity, bool $isValid): void {}
    protected function afterStatusChange(object $entity, bool $isValid): void {}


    // ===============================================
    // MÉTHODES UTILITAIRES
    // ===============================================
    protected function deserializeToEntity(array $data, ?object $existingEntity = null): object
    {
        $json = json_encode($data);

        // ignorer les relations déclarées dans getRelationConfig()
        $relationProps = array_keys($this->getRelationConfig());
        $context = [
            'ignored_attributes' => $relationProps, // ← clé importante
            // 'groups' => ['user_write'], // optionnel si tu utilises des groupes
        ];

        try {
            if ($existingEntity) {
                $context['object_to_populate'] = $existingEntity;
            }
            return $this->serializer->deserialize($json, $this->getEntityClass(), 'json', $context);
        } catch (\Exception $e) {
            throw new ValidationException([
                'json' => [['message' => 'Invalid JSON structure' . $e->getMessage()]]
            ]);
        }
    }

    protected function validateEntity(object $entity, array $groups = ['Default']): void
    {
        $violations = $this->validator->validate($entity, null, $groups);

        if (count($violations) > 0) {
            $errors = ValidationUtils::formatValidationErrors($violations);
            throw new ValidationException($errors);
        }
    }

    /**
     * Gestion des relations déléguée au RelationProcessor.
     */
    protected function processRelations(object $entity, array $data, string $operation): void
    {
        $relationConfig = $this->getRelationConfig();

        if (!empty($relationConfig)) {
            $this->relationProcessor->processEntityRelations($entity, $data, $relationConfig, $operation);
        }
    }

    /**
     * Configuration des relations - à surcharger si nécessaire.
     * Format: ['property_name' => ['type' => 'many_to_one|many_to_many', 'target_entity' => Entity::class]]
     */
    protected function getRelationConfig(): array
    {
        return [];
    }

    /**
     * Helper pour lever une exception de conflit.
     */
    protected function throwConflictIfExists(?object $existing, string $field, mixed $value): void
    {
        if ($existing) {
            $entityName = (new \ReflectionClass($this->getEntityClass()))->getShortName();
            throw new ConflictException($entityName, $field, $value);
        }
    }

    /**
     * Helper pour les règles métier.
     */
    protected function throwBuisinessRule(string $rule, string $message): never
    {
        throw new BusinessRuleException($rule, $message);
    }
}
