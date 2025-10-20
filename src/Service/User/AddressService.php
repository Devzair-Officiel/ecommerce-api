<?php

declare(strict_types=1);

namespace App\Service\User;

use App\Entity\User\Address;
use App\Entity\User\User;
use App\Repository\User\AddressRepository;
use App\Service\Core\AbstractService;
use App\Service\Core\RelationProcessor;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Serializer\SerializerInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * Service métier pour la gestion des adresses.
 * 
 * Responsabilités :
 * - CRUD des adresses utilisateur
 * - Gestion de l'adresse par défaut (une seule par user)
 * - Validation des adresses selon le pays
 */
class AddressService extends AbstractService
{
    public function __construct(
        EntityManagerInterface $em,
        SerializerInterface $serializer,
        ValidatorInterface $validator,
        RelationProcessor $relationProcessor,
        private readonly AddressRepository $addressRepository
    ) {
        parent::__construct($em, $serializer, $validator, $relationProcessor);
    }

    protected function getEntityClass(): string
    {
        return Address::class;
    }

    protected function getRepository(): AddressRepository
    {
        return $this->addressRepository;
    }

    // ===============================================
    // CRÉATION
    // ===============================================

    /**
     * Crée une adresse pour un utilisateur.
     * 
     * @param array $data Données de l'adresse
     * @param User $user Utilisateur propriétaire
     * @return Address Adresse créée
     */
    public function createAddress(array $data, User $user): Address
    {
        return $this->createWithHooks($data, ['user' => $user]);
    }

    /**
     * Hook avant création : Assigner l'utilisateur et gérer l'adresse par défaut.
     */
    protected function beforeCreate(object $entity, array $data, array $context): void
    {
        /** @var Address $entity */

        // Assigner l'utilisateur depuis le contexte
        if (isset($context['user'])) {
            $entity->setUser($context['user']);
        }

        // Si c'est la première adresse, la mettre par défaut automatiquement
        if ($this->addressRepository->countByUser($entity->getUser()) === 0) {
            $entity->setIsDefault(true);
        }

        // Si l'adresse doit être par défaut, retirer le flag des autres
        if ($entity->isDefault()) {
            $this->addressRepository->unsetDefaultForUser($entity->getUser());
        }
    }

    // ===============================================
    // MISE À JOUR
    // ===============================================

    /**
     * Met à jour une adresse.
     */
    public function updateAddress(int $id, array $data): Address
    {
        return $this->updateWithHooks($id, $data);
    }

    /**
     * Hook avant mise à jour : Gérer l'adresse par défaut.
     */
    protected function beforeUpdate(object $entity, array $data, array $context): void
    {
        /** @var Address $entity */

        // Si l'adresse devient par défaut, retirer le flag des autres
        if (isset($data['isDefault']) && $data['isDefault'] === true) {
            $this->addressRepository->unsetDefaultForUser($entity->getUser());
        }
    }

    // ===============================================
    // SUPPRESSION
    // ===============================================

    /**
     * Supprime une adresse (soft delete).
     */
    public function deleteAddress(int $id): Address
    {
        return $this->deleteWithHooks($id);
    }

    /**
     * Hook après suppression : Si c'était l'adresse par défaut, en définir une autre.
     */
    protected function afterDelete(object $entity, array $context): void
    {
        /** @var Address $entity */

        // Si l'adresse supprimée était par défaut, assigner une autre adresse par défaut
        if ($entity->isDefault()) {
            $addresses = $this->addressRepository->findByUser($entity->getUser());

            if (!empty($addresses)) {
                $firstAddress = $addresses[0];
                $firstAddress->setIsDefault(true);
                $this->em->flush();
            }
        }
    }

    // ===============================================
    // MÉTHODES MÉTIER SPÉCIFIQUES
    // ===============================================

    /**
     * Définit une adresse comme adresse par défaut.
     * 
     * @param int $id ID de l'adresse
     * @param User $user Utilisateur (pour vérifier la propriété)
     * @return Address Adresse mise à jour
     */
    public function setAsDefault(int $id, User $user): Address
    {
        $address = $this->findEntityById($id);

        // Vérifier que l'adresse appartient bien à l'utilisateur
        if ($address->getUser()->getId() !== $user->getId()) {
            throw new \LogicException('Cette adresse ne vous appartient pas.');
        }

        // Retirer le flag par défaut des autres adresses
        $this->addressRepository->unsetDefaultForUser($user);

        // Définir cette adresse comme par défaut
        $address->setIsDefault(true);
        $this->em->flush();

        return $address;
    }

    /**
     * Récupère toutes les adresses d'un utilisateur.
     * 
     * @param User $user Utilisateur
     * @return Address[] Liste des adresses
     */
    public function getUserAddresses(User $user): array
    {
        return $this->addressRepository->findByUser($user);
    }

    /**
     * Récupère l'adresse par défaut d'un utilisateur.
     * 
     * @param User $user Utilisateur
     * @return Address|null Adresse par défaut ou null
     */
    public function getDefaultAddress(User $user): ?Address
    {
        return $this->addressRepository->findDefaultByUser($user);
    }

    /**
     * Récupère les adresses de facturation d'un utilisateur.
     */
    public function getBillingAddresses(User $user): array
    {
        return $this->addressRepository->findByUserAndType($user, \App\Enum\User\AddressType::BILLING);
    }

    /**
     * Récupère les adresses de livraison d'un utilisateur.
     */
    public function getShippingAddresses(User $user): array
    {
        return $this->addressRepository->findByUserAndType($user, \App\Enum\User\AddressType::SHIPPING);
    }

    /**
     * Vérifie qu'une adresse appartient à un utilisateur.
     */
    public function belongsToUser(int $addressId, User $user): bool
    {
        $address = $this->findEntityById($addressId);
        return $address->getUser()->getId() === $user->getId();
    }

    // ===============================================
    // SURCHARGES CRUD STANDARDS
    // ===============================================

    public function create(array $data, array $context = []): object
    {
        if (!isset($context['user'])) {
            throw new \InvalidArgumentException('User context is required for address creation.');
        }

        return $this->createAddress($data, $context['user']);
    }

    public function update(int $id, array $data, array $context = []): object
    {
        return $this->updateAddress($id, $data);
    }

    public function delete(int $id, array $context = []): object
    {
        return $this->deleteAddress($id);
    }

    /**
     * Configuration des relations (aucune pour Address).
     */
    protected function getRelationConfig(): array
    {
        return [];
    }
}
