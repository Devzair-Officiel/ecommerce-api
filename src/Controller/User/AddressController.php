<?php

declare(strict_types=1);

namespace App\Controller\User;

use App\Controller\Core\AbstractApiController;
use App\Entity\User\User;
use App\Exception\ValidationException;
use App\Service\User\AddressService;
use App\Utils\ApiResponseUtils;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Serializer\SerializerInterface;

/**
 * Contrôleur pour la gestion des adresses utilisateur.
 * 
 * Endpoints :
 * - CRUD adresses (utilisateur connecté uniquement)
 * - Définir adresse par défaut
 * - Récupérer adresses par type (facturation/livraison)
 */
#[Route('/addresses', name: 'api_addresses_')]
#[IsGranted('ROLE_USER')]
class AddressController extends AbstractApiController
{
    public function __construct(
        ApiResponseUtils $apiResponseUtils,
        SerializerInterface $serializer,
        private readonly AddressService $addressService
    ) {
        parent::__construct($apiResponseUtils, $serializer);
    }

    /**
     * Liste des adresses de l'utilisateur connecté.
     */
    #[Route('', name: 'list', methods: ['GET'])]
    public function list(#[CurrentUser] User $user): JsonResponse
    {
        $addresses = $this->addressService->getUserAddresses($user);

        return $this->apiResponseUtils->success(
            data: $this->serialize($addresses, ['address:list', 'date']),
            messageKey: 'entity.list_retrieved',
            entityKey: 'address'
        );
    }

    /**
     * Détail d'une adresse.
     */
    #[Route('/{id}', name: 'show', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function show(int $id, #[CurrentUser] User $user): JsonResponse
    {
        // Vérifier que l'adresse appartient à l'utilisateur
        if (!$this->addressService->belongsToUser($id, $user)) {
            return $this->apiResponseUtils->accessDenied();
        }

        $address = $this->addressService->findEntityById($id);

        return $this->showResponse(
            $address,
            ['address:read', 'date'],
            'address'
        );
    }

    /**
     * Création d'une adresse.
     */
    #[Route('', name: 'create', methods: ['POST'])]
    public function create(Request $request, #[CurrentUser] User $user): JsonResponse
    {
        $data = $this->getJsonData($request);

        try {
            // Validation des champs requis
            $this->requireFields($data, ['fullName', 'street', 'postalCode', 'city', 'phone'], 'Champs requis manquants');

            $address = $this->addressService->createAddress($data, $user);

            return $this->createResponse(
                $address,
                ['address:read', 'date'],
                'address'
            );
        } catch (ValidationException $e) {
            return $this->validationError($e->getFormattedErrors());
        }
    }

    /**
     * Mise à jour d'une adresse.
     */
    #[Route('/{id}', name: 'update', methods: ['PUT', 'PATCH'], requirements: ['id' => '\d+'])]
    public function update(int $id, Request $request, #[CurrentUser] User $user): JsonResponse
    {
        // Vérifier que l'adresse appartient à l'utilisateur
        if (!$this->addressService->belongsToUser($id, $user)) {
            return $this->apiResponseUtils->accessDenied();
        }

        $data = $this->getJsonData($request);

        try {
            $address = $this->addressService->updateAddress($id, $data);

            return $this->updateResponse(
                $address,
                ['address:read', 'date'],
                'address'
            );
        } catch (ValidationException $e) {
            return $this->validationError($e->getFormattedErrors());
        }
    }

    /**
     * Suppression d'une adresse.
     */
    #[Route('/{id}', name: 'delete', methods: ['DELETE'], requirements: ['id' => '\d+'])]
    public function delete(int $id, #[CurrentUser] User $user): JsonResponse
    {
        // Vérifier que l'adresse appartient à l'utilisateur
        if (!$this->addressService->belongsToUser($id, $user)) {
            return $this->apiResponseUtils->accessDenied();
        }

        $address = $this->addressService->deleteAddress($id);

        return $this->deleteResponse(
            ['id' => $id, 'fullName' => $address->getFullName()],
            'address'
        );
    }

    // ===============================================
    // ENDPOINTS SPÉCIALISÉS
    // ===============================================

    /**
     * Définir une adresse comme adresse par défaut.
     */
    #[Route('/{id}/set-default', name: 'set_default', methods: ['PATCH'], requirements: ['id' => '\d+'])]
    public function setDefault(int $id, #[CurrentUser] User $user): JsonResponse
    {
        try {
            $address = $this->addressService->setAsDefault($id, $user);

            return $this->apiResponseUtils->success(
                data: $this->serialize($address, ['address:read', 'date']),
                messageKey: 'address.set_as_default',
                entityKey: 'address'
            );
        } catch (\LogicException $e) {
            return $this->apiResponseUtils->error(
                errors: [['message' => $e->getMessage()]],
                messageKey: 'error.access_denied',
                status: 403
            );
        }
    }

    /**
     * Récupérer l'adresse par défaut.
     */
    #[Route('/default', name: 'default', methods: ['GET'])]
    public function getDefault(#[CurrentUser] User $user): JsonResponse
    {
        $address = $this->addressService->getDefaultAddress($user);

        if (!$address) {
            return $this->notFoundError('address', ['type' => 'default']);
        }

        return $this->showResponse(
            $address,
            ['address:read', 'date'],
            'address'
        );
    }

    /**
     * Récupérer les adresses de facturation.
     */
    #[Route('/billing', name: 'billing', methods: ['GET'])]
    public function getBillingAddresses(#[CurrentUser] User $user): JsonResponse
    {
        $addresses = $this->addressService->getBillingAddresses($user);

        return $this->apiResponseUtils->success(
            data: $this->serialize($addresses, ['address:list', 'date']),
            messageKey: 'address.billing_retrieved',
            entityKey: 'address'
        );
    }

    /**
     * Récupérer les adresses de livraison.
     */
    #[Route('/shipping', name: 'shipping', methods: ['GET'])]
    public function getShippingAddresses(#[CurrentUser] User $user): JsonResponse
    {
        $addresses = $this->addressService->getShippingAddresses($user);

        return $this->apiResponseUtils->success(
            data: $this->serialize($addresses, ['address:list', 'date']),
            messageKey: 'address.shipping_retrieved',
            entityKey: 'address'
        );
    }
}
