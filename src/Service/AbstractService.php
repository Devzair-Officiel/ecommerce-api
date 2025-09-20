<?php

declare(strict_types=1);

namespace App\Service;

use App\Utils\JsonValidationUtils;
use App\Utils\DeserializationUtils;
use Doctrine\ORM\EntityManagerInterface;
use App\Exception\EntityNotFoundException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

/**
 * Service abstrait offrant des fonctionnalités génériques pour la gestion des entités.
 *
 * Cette classe fournit :
 * - La récupération sécurisée d'une entité par son ID.
 * - La création et l'association dynamique d'entités.
 * - La mise à jour des relations entre entités.
 * - La suppression d'une entité.
 *
 * Elle est destinée à être étendue par des services spécifiques aux entités de l'application.
 */
abstract class AbstractService
{
    public function __construct(
        protected EntityManagerInterface $em,
        protected DeserializationUtils $deserializationUtils,
        protected JsonValidationUtils $jsonValidationUtils
    ) {}

    /**
     * Récupère une entité par son ID ou lève une exception si elle n'existe pas.
     * 
     * @param string $entityClass
     * @param int $id
     * @throws \App\Exception\EntityNotFoundException
     */
    public function findEntityById(string $entityClass, int $id)
    {
        $entity = $this->em->getRepository($entityClass)->find($id);

        if (!$entity) {
            throw new EntityNotFoundException(strtolower(basename(str_replace('\\', '/', $entityClass))), ['id' => $id]);
        }

        return $entity;
    }

    /**
     * Marque une entité comme desactivée en lui assignant une date de clôture et valid à false.
     *
     * @param object $entity L'entité à fermer.
     */
    public function disableEntity(object $entity): void
    {
        $entityName = strtolower(basename(str_replace('\\', '/', $entity::class)));

        if (!$entity->isValid()) {
            throw new BadRequestHttpException(sprintf('%s.already_deactivated', $entityName));
        }

        $entity->setValid(false);
        $entity->setClosedAt(new \DateTimeImmutable());
        $this->em->flush();
    }

    /**
     * Marque une entité comme activée en lui assignant une date de clôture à null et valid à true.
     *
     * @param object $entity L'entité à ouvrir.
     */
    public function enableEntity(object $entity): void
    {
        $entityName = strtolower(basename(str_replace('\\', '/', $entity::class)));

        if (!$entity->isValid()) {
            throw new BadRequestHttpException(sprintf('%s.already_deactivated', $entityName));
        }

        $entity->setValid(true);
        $entity->setClosedAt(null);
        $this->em->flush();
    }

    /**
     * Supprime définitivement une entité de la base de données.
     *
     * @param object $entity L'entité à supprimer.
     */
    public function deleteEntity(object $entity): void
    {
        $this->em->remove($entity);
        $this->em->flush();
    }


    /**
     * Crée et associe des entités à une relation donnée.
     *
     * - Si l'entité associée existe déjà, elle est récupérée.
     * - Sinon, une nouvelle entité est créée si `$allowCreation` est `true`, sinon une erreur est levée.
     * - La relation est ajoutée dynamiquement à l'entité principale.
     *
     * @param object  $mainEntity         L'entité principale.
     * @param string  $relationMethod     Nom de la relation (ex: "tags").
     * @param string  $relatedEntityClass Classe de l'entité associée.
     * @param string  $identifierKey      Clé unique identifiant l'entité (ex: "id", "slug").
     * @param array   $jsonData           Tableau des nouvelles données à associer.
     * @param bool    $allowCreation      Si `true`, crée une nouvelle entité si elle n'existe pas.
     *
     * @throws BadRequestHttpException    Si la clé d'identification est absente des données.
     * @throws EntityNotFoundException    Si l'entité associée n'existe pas et `$allowCreation` est `false`.
     */
    public function createAndAssociateEntities(
        object $mainEntity,
        string $relationMethod,
        string $relatedEntityClass,
        string $identifierKey,
        array $jsonData,
        bool $allowCreation = true
    ): void {
        $repository = $this->em->getRepository($relatedEntityClass);

        $adder = 'add' . ucfirst($relationMethod);
        $setter = 'set' . ucfirst($relationMethod);

        foreach ($jsonData as $relatedData) {
            if (!isset($relatedData[$identifierKey])) {
                throw new BadRequestHttpException("Le champ '{$identifierKey}' est requis.");
            }

            $identifierValue = $relatedData[$identifierKey];
            $entity = $repository->findOneBy([$identifierKey => $identifierValue]);

            if (!$entity) {
                if (!$allowCreation) {
                    $className = strtolower((new \ReflectionClass($relatedEntityClass))->getShortName());
                    throw new EntityNotFoundException($className, [$identifierKey => $identifierValue]);
                }

                $entity = new $relatedEntityClass();
                $entitySetter = 'set' . ucfirst($identifierKey);

                if (method_exists($entity, $entitySetter)) {
                    $entity->$entitySetter($identifierValue);
                }

                $this->em->persist($entity);
            }

            // Association selon les méthodes disponibles sur l'entité principale
            if (method_exists($mainEntity, $adder)) {
                // ex: $user->addTag($tag)
                $mainEntity->$adder($entity);
            } elseif (method_exists($mainEntity, $setter)) {
                // ex: $user->setLaboratory($lab)
                $mainEntity->$setter($entity);
            } else {
                throw new \LogicException(sprintf(
                    "Aucune méthode d'association trouvée pour '%s' dans l'entité %s. Attendu : %s() ou %s().",
                    $relationMethod,
                    get_class($mainEntity),
                    $adder,
                    $setter
                ));
            }
        }
    }


    /**
     * Met à jour une relation entre une entité principale et une collection d'entités associées.
     * 
     * - Supprime les entités associées qui ne sont plus dans la requête.
     * - Ajoute ou met à jour les entités envoyées.
     * - Gère automatiquement les relations ManyToMany, OneToMany et ManyToOne.
     * 
     * @param object   $mainEntity         L'entité principale (ex: Denomination).
     * @param string   $relationMethod     Le nom de la relation (ex: "formePharmaceutiqueLovs").
     * @param string   $relatedEntityClass La classe de l'entité associée.
     * @param string   $identifierKey      La clé unique pour identifier les entités (ex: "title", "id").
     * @param array    $jsonData           Les nouvelles données à appliquer.
     * @param bool     $allowCreation      Si `true`, crée l'entité si elle n'existe pas. Sinon, lève une erreur.
     */
    protected function updateAssociation(
        object $mainEntity,
        string $relationMethod,
        string $relatedEntityClass,
        string $identifierKey,
        array|int|string|null $jsonData,
        bool $allowCreation = true
    ): void {
        $repository = $this->em->getRepository($relatedEntityClass);

        $relationSingular = rtrim($relationMethod, 's');
        $getRelated    = "get" . ucfirst($relationMethod);       // ex: getTeams
        $addRelated    = "add" . ucfirst($relationSingular);     // ex: addTeam
        $removeRelated = "remove" . ucfirst($relationSingular);  // ex: removeTeam
        $setRelated    = "set" . ucfirst($relationMethod);       // ex: setLaboratory

        // 🔹 1. Gestion spéciale du null explicite (cas ManyToOne à annuler)
        if ($jsonData === null) {
            if (method_exists($mainEntity, $setRelated)) {
                $mainEntity->$setRelated(null);
            }
            return;
        }

        // 🔹 2. Si on reçoit une entité seule (ManyToOne) au format simple : ex. {"id": 1}
        if (!is_array($jsonData) || array_keys($jsonData) !== range(0, count($jsonData) - 1)) {
            if (!isset($jsonData[$identifierKey])) {
                throw new \InvalidArgumentException("Identifiant '$identifierKey' manquant pour la relation $relationMethod.");
            }

            $identifierValue = $jsonData[$identifierKey];
            $entity = $repository->findOneBy([$identifierKey => $identifierValue]);

            if (!$entity) {
                if (!$allowCreation) {
                    throw new EntityNotFoundException($relatedEntityClass, [$identifierKey => $identifierValue]);
                }

                $entity = new $relatedEntityClass();
                $setterMethod = 'set' . ucfirst($identifierKey);
                if (method_exists($entity, $setterMethod)) {
                    $entity->$setterMethod($identifierValue);
                }

                $this->em->persist($entity);
            }

            if (!method_exists($mainEntity, $setRelated)) {
                throw new \LogicException("La méthode '$setRelated' est manquante pour l'entité " . get_class($mainEntity));
            }

            $mainEntity->$setRelated($entity);
            return;
        }

        // 🔹 3. On est ici dans un cas OneToMany ou ManyToMany (liste de relations)
        $identifiersInRequest = array_filter(array_map(fn($obj) => $obj[$identifierKey] ?? null, $jsonData));

        if (method_exists($mainEntity, $getRelated)) {
            $existingEntities = $mainEntity->$getRelated();

            foreach ($existingEntities as $existingEntity) {
                $existingIdentifier = $existingEntity->{"get" . ucfirst($identifierKey)}();
                if (!in_array($existingIdentifier, $identifiersInRequest, true)) {
                    if (method_exists($mainEntity, $removeRelated)) {
                        $mainEntity->$removeRelated($existingEntity);
                    } elseif (method_exists($existingEntity, $setRelated)) {
                        $existingEntity->$setRelated(null);
                    }
                }
            }
        }

        foreach ($jsonData as $relatedObject) {
            if (!isset($relatedObject[$identifierKey])) {
                continue;
            }

            $identifierValue = $relatedObject[$identifierKey];
            $entity = $repository->findOneBy([$identifierKey => $identifierValue]);

            if (!$entity) {
                if (!$allowCreation) {
                    throw new EntityNotFoundException($relatedEntityClass, [$identifierKey => $identifierValue]);
                }

                $entity = new $relatedEntityClass();
                $setterMethod = 'set' . ucfirst($identifierKey);

                if (method_exists($entity, $setterMethod)) {
                    $entity->$setterMethod($identifierValue);
                }

                $this->em->persist($entity);
            }

            // Ajout de la relation
            if (method_exists($mainEntity, $addRelated)) {
                if (!in_array($entity, $mainEntity->$getRelated()?->toArray() ?? [], true)) {
                    $mainEntity->$addRelated($entity);
                }
            } elseif (method_exists($mainEntity, $setRelated)) {
                $mainEntity->$setRelated($entity);
            } else {
                throw new \LogicException(sprintf(
                    "Aucune méthode d'association trouvée pour '%s' dans l'entité %s. Attendu : %s() ou %s().",
                    $relationMethod,
                    get_class($mainEntity),
                    $addRelated,
                    $setRelated
                ));
            }
        }
    }

    /**
     * Vérifie que les clés fournies dans le tableau JSON correspondent bien aux propriétés de la classe cible.
     *
     * Cette méthode est utile pour valider en amont les données brutes d'une requête JSON avant toute désérialisation,
     * et éviter ainsi l'instanciation d'une entité contenant des propriétés inattendues ou non mappées.
     *
     * @param array $jsonData     Données JSON sous forme de tableau associatif.
     * @param string $entityClass Nom complet de la classe de l'entité (ex: App\Entity\User\Division).
     *
     * @throws \InvalidArgumentException Si des clés invalides sont détectées dans le JSON fourni.
     */
    protected function assertValidJsonKeys(array $jsonData, string $entityClass): void
    {
        $invalidKeys = $this->jsonValidationUtils->validateKeys($jsonData, $entityClass);

        if (!empty($invalidKeys)) {
            throw new \InvalidArgumentException(
                sprintf('Clés JSON invalides pour %s : %s', $entityClass, implode(', ', $invalidKeys))
            );
        }
    }
}
