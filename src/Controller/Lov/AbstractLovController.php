<?php

declare(strict_types=1);

namespace App\Controller\Lov;

use App\Service\LovService;
use App\Utils\ApiResponseUtils;
use App\Utils\SerializationUtils;
use App\Utils\DeserializationUtils;
use App\Controller\AbstractApiController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Routing\Annotation\Route;

abstract class AbstractLovController extends AbstractApiController
{
    protected LovService $lovService;

    public function __construct(
        LovService $lovService,
        ApiResponseUtils $apiResponseUtils,
        SerializationUtils $serializationUtils,
        DeserializationUtils $deserializationUtils
    ) {
        parent::__construct($apiResponseUtils, $serializationUtils, $deserializationUtils);
        $this->lovService = $lovService;
    }

    #[Route('', name: 'get_all', methods: ['GET'])]
    public function getAllLovs(Request $request, UrlGeneratorInterface $urlGenerator): JsonResponse
    {
        // dd($urlGenerator);
        return $this->handlePaginatedRequest(
            $request,
            fn(int $page, int $limit, array $filters) => $this->lovService->getLovs($this->getEntityClass(), $page, $limit, $filters),
            'country_get_all',
            $urlGenerator
        );
    }

    #[Route('/{id}', name: 'get', methods: ['GET'])]
    public function getOne(int $id): JsonResponse
    {
        $item = $this->lovService->getOne($this->getEntityClass(), $id);

        if (!$item) {
            return $this->apiResponseUtils->error(
                errors: ['id' => $id],
                messageKey: 'entity.not_found',
                entityKey: $this->getEntityName(),
                status: Response::HTTP_NOT_FOUND
            );
        }

        $serializedItem = $this->serializationUtils->serialize($item, [$this->getSerializationGroup()]);

        return $this->apiResponseUtils->success(
            data: $serializedItem,
            messageKey: 'entity.retrieved',
            entityKey: $this->getEntityName()
        );
    }

    #[Route('', name: 'register', methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {
        $data = $this->deserializationUtils->deserializeAndValidate($request->getContent(), $this->getEntityClass());

        $content = json_decode($request->getContent(), true);

        $this->lovService->create($data, $content);

        $serializedItem = $this->serializationUtils->serialize($data, [$this->getSerializationGroup()]);

        return $this->apiResponseUtils->success(
            data: $serializedItem,
            messageKey: 'entity.created',
            entityKey: $this->getEntityName(),
            status: Response::HTTP_CREATED
        );
    }

    #[Route('/{id}', name: 'update', methods: ['PUT'])]
    public function update(int $id, Request $request): JsonResponse
    {
        // Récupérer l'entité à mettre à jour
        $existingItem = $this->lovService->getOne($this->getEntityClass(), $id);

        if (!$existingItem) {
            return $this->apiResponseUtils->error(
                errors: ['id' => $id],
                messageKey: 'entity.not_found',
                entityKey: $this->getEntityName(),
                status: Response::HTTP_NOT_FOUND
            );
        }

        // Désérialisation et validation des nouvelles données
        $updatedData = $this->deserializationUtils->deserializeAndValidate(
            $request->getContent(),
            $this->getEntityClass(),
            $existingItem
        );

        $jsonData = json_decode($request->getContent(), true);

        // Mise à jour dans le service
        $updatedItem = $this->lovService->update($updatedData, $jsonData);

        // Sérialisation et retour
        $serializedItem = $this->serializationUtils->serialize($updatedItem, [$this->getSerializationGroup()]);

        return $this->apiResponseUtils->success(
            data: $serializedItem,
            messageKey: 'entity.updated',
            entityKey: $this->getEntityName(),
            status: Response::HTTP_OK
        );
    }

    #[Route('/{id}', name: 'delete', methods: ['DELETE'])]
    public function delete(int $id): JsonResponse
    {
        $item = $this->lovService->getOne($this->getEntityClass(), $id);

        if (!$item) {
            return $this->apiResponseUtils->error(
                errors: ['id' => $id, "item" => $item ],
                messageKey: 'entity.not_found',
                entityKey: $this->getEntityName(),
                status: Response::HTTP_NOT_FOUND
            );
        }

        $this->lovService->delete($item);

        return $this->apiResponseUtils->success(
            data: ['id' => $id],
            messageKey: 'entity.delete',
            entityKey: $this->getEntityName(),
            status: Response::HTTP_OK
        );
    }

    abstract protected function getEntityClass(): string;
    abstract protected function getSerializationGroup(): string;
    abstract protected function getEntityName(): string;

    protected function extractFilters(array $queryParams): array
    {
        return [];
    }
}
