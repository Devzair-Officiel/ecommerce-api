<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\ProductService;
use App\Utils\ApiResponseUtils;
use Symfony\Component\HttpFoundation\Request;
use App\Controller\Core\AbstractApiController;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Serializer\SerializerInterface;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/products', name: 'api_products')]
class ProductController extends AbstractApiController
{
    public function __construct(
        private ProductService $productService,
        ApiResponseUtils $apiResponseUtils,
        SerializerInterface $serializer,

    ) {
        parent::__construct($apiResponseUtils, $serializer);
    }

    /**
     * Récupérer tous les products
     */
    #[Route('', name: 'get_all', methods: ['GET'])]
    public function list(Request $request): JsonResponse
    {
        $pagination = $this->getPaginationParams($request);
        $filters = $this->extractFilters($request);

        $result = $this->productService->search(
            $pagination['page'],
            $pagination['limit'],
            $filters
        );

        return $this->listResponse(
            $result,
            ['product_list', 'date'],
            'product',
            'api_get_all_products'
        );
    }

    /**
     * Récupérer un product
     */
    #[Route('/{id}', name: 'get', methods: ['GET'])]
    public function show(int $id): JsonResponse
    {
        $product = $this->productService->findEntityById($id);

        return $this->showResponse(
            $product,
            ['product_detail', 'product_list', 'date'],
            'product'
        );
    }

    /**
     * Création d'un product
     */
    #[Route('', name: 'register', methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {
        $data = $this->getJsonData($request);

        $result = $this->productService->create($data);

        return $this->createResponse(
            $result,
            ['product_list', 'date'],
            'product',
        );
    }

    /**
     * Mise à jour d'un product
     */
    #[Route('/{id}', name: 'updated', methods: ['PUT'])]
    public function update(int $id, Request $request): JsonResponse
    {
        $data = $this->getJsonData($request);

        $product = $this->productService->update($id, $data);

        return $this->updateResponse(
            $product,
            ['product_detail', 'date'],
            'product'
        );
    }

    /**
     * Supprime un product
     */
    #[Route('/{id}', name: 'delete', methods: ['DELETE'])]
    public function delete(int $id): JsonResponse
    {
        $product = $this->productService->delete($id);

        return $this->deleteResponse(
            ['id' => $id, 'title' => $product->getTitle()],
            'product'
        );
    }

    /**
     * Change le statut d'une entité (activation/désactivation).
     * 
     * Body: { "isValid": true }  // ou false
     */
    #[Route('/{id}/status', name: 'toggle_status', methods: ['PUT', 'PATCH'])]
    public function toggleStatus(int $id, Request $request): JsonResponse
    {
        $data = $this->getJsonData($request);

        $isValid = $this->getBooleanValue($data, 'isValid');

        $product = $this->productService->toogleStatus($id, $isValid);

        return $this->statusReponse(
            data: ['id' => $id, 'title' => $product->getTitle(), 'isValid' => $product->isValid()],
            entityKey: 'product',
            isValid: $isValid
        );
    }
}
