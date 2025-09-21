<?php

namespace App\Controller;

use App\Service\ProductService;
use App\Utils\ApiResponseUtils;
use App\Utils\SerializationUtils;
use App\Exception\ValidationException;
use App\Exception\EntityNotFoundException;
use App\Service\Search\ProductSearchService;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;


#[Route(name: 'api_products_')]
class ProductController extends AbstractController
{
    public function __construct(
        private readonly ProductService $productService,
        private readonly ProductSearchService $searchService,
        private readonly SerializationUtils $serializer,
        private readonly ApiResponseUtils $apiResponse
    ) {}

    #[Route('', methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {
        try {
            $product = $this->productService->createProduct($request->getContent());

            $data = $this->serializer->serialize($product, ['product_detail', 'date']);

            return $this->apiResponse->created($data, 'product');
        } catch (ValidationException $e) {
            return $this->apiResponse->validationFailed($e->getErrors());
        } catch (\InvalidArgumentException $e) {
            return $this->apiResponse->error([['message' => $e->getMessage()]]);
        }
    }

    #[Route('/{id}', methods: ['PUT'], requirements: ['id' => '\d+'])]
    public function update(int $id, Request $request): JsonResponse
    {
        try {
            $product = $this->productService->updateProduct($id, $request->getContent());

            $data = $this->serializer->serialize($product, ['product_detail', 'date']);

            return $this->apiResponse->updated($data, 'product');
        } catch (EntityNotFoundException $e) {
            return $this->apiResponse->notFound('product', ['id' => $id]);
        } catch (ValidationException $e) {
            return $this->apiResponse->validationFailed($e->getErrors());
        }
    }

    #[Route('/{id}', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function show(int $id): JsonResponse
    {
        try {
            $product = $this->productService->getProduct($id);

            $data = $this->serializer->serialize($product, ['product_detail', 'date']);

            return $this->apiResponse->retrieved($data, 'product');
        } catch (EntityNotFoundException $e) {
            return $this->apiResponse->notFound('product', ['id' => $id]);
        }
    }

    #[Route('', methods: ['GET'])]
    public function list(Request $request): JsonResponse
    {
        $filters = $request->query->all();
        $result = $this->searchService->searchProducts($filters);

        $result['items'] = $this->serializer->serialize($result['items'], ['product_list']);

        return $this->apiResponse->success($result);
    }

    #[Route('/{id}', methods: ['DELETE'], requirements: ['id' => '\d+'])]
    public function delete(int $id): JsonResponse
    {
        try {
            $product = $this->productService->deleteProduct($id);

            $data = $this->serializer->serialize($product, ['product_detail']);

            return $this->apiResponse->deleted($data, 'product');
        } catch (EntityNotFoundException $e) {
            return $this->apiResponse->notFound('product', ['id' => $id]);
        } catch (\DomainException $e) {
            return $this->apiResponse->error([['message' => $e->getMessage()]], status: 409);
        }
    }

    #[Route('/{id}/toggle-status', methods: ['PATCH'], requirements: ['id' => '\d+'])]
    public function toggleStatus(int $id, Request $request): JsonResponse
    {
        try {
            $data = json_decode($request->getContent(), true);
            $active = filter_var($data['active'] ?? true, FILTER_VALIDATE_BOOLEAN);

            $product = $this->productService->toggleStatus($id, $active);

            $responseData = $this->serializer->serialize($product, ['product_detail']);

            return $this->apiResponse->updated($responseData, 'product');
        } catch (EntityNotFoundException $e) {
            return $this->apiResponse->notFound('product', ['id' => $id]);
        }
    }

    #[Route('/{id}/stock', methods: ['PATCH'], requirements: ['id' => '\d+'])]
    public function updateStock(int $id, Request $request): JsonResponse
    {
        try {
            $data = json_decode($request->getContent(), true);

            if (!isset($data['stock']) || !is_numeric($data['stock'])) {
                return $this->apiResponse->error([['message' => 'Stock value is required and must be numeric']]);
            }

            $product = $this->productService->updateStock($id, (int) $data['stock']);

            $responseData = $this->serializer->serialize($product, ['product_detail']);

            return $this->apiResponse->updated($responseData, 'product');
        } catch (EntityNotFoundException $e) {
            return $this->apiResponse->notFound('product', ['id' => $id]);
        } catch (\InvalidArgumentException $e) {
            return $this->apiResponse->error([['message' => $e->getMessage()]]);
        }
    }

    #[Route('/featured/{siteId}', methods: ['GET'], requirements: ['siteId' => '\d+'])]
    public function featured(int $siteId, Request $request): JsonResponse
    {
        $limit = min(50, max(1, (int) $request->query->get('limit', 10)));

        $products = $this->searchService->getFeaturedProducts($siteId, $limit);

        $data = $this->serializer->serialize($products, ['product_list', 'public_read']);

        return $this->apiResponse->success($data);
    }
}