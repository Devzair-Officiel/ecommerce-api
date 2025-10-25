<?php

declare(strict_types=1);

namespace App\Controller\Site;

use App\Utils\ApiResponseUtils;
use App\Service\Core\AbstractService;
use App\Service\Site\SiteService;
use Symfony\Component\HttpFoundation\Request;
use App\Controller\Core\AbstractCrudController;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Serializer\SerializerInterface;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/sites', name: 'api_sites_')]
class SiteController extends AbstractCrudController
{
    public function __construct(
        private SiteService $siteService,
        ApiResponseUtils $apiResponseUtils,
        SerializerInterface $serializer,

    ) {
        parent::__construct($apiResponseUtils, $serializer);
    }

    // Configuration obligatoire
    protected function getService(): AbstractService
    {
        return $this->siteService;
    }

    protected function getEntityKey(): string
    {
        return 'site';
    }

    protected function getRouteNamePrefix(): string
    {
        return 'api_sites_';
    }

    // Groupes de sérialisation
    protected function getListGroups(): array
    {
        return ['site_list', 'date'];
    }
    protected function getDetailGroups(): array
    {
        return ['site_detail', 'site_list', 'date'];
    }

    // Routes héritées automatiquement via les attributs sur les méthodes parentes
    #[Route('', name: 'get_all', methods: ['GET'])]
    public function list(Request $request): JsonResponse
    {
        return parent::list($request);
    }

    #[Route('/{id}', name: 'get', methods: ['GET'])]
    public function show(int $id): JsonResponse
    {
        return parent::show($id);
    }

    #[Route('', name: 'register', methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {
        return parent::create($request);
    }

    #[Route('/{id}', name: 'updated', methods: ['PUT'])]
    public function update(int $id, Request $request): JsonResponse
    {
        return parent::update($id, $request);
    }

    #[Route('/{id}', name: 'delete', methods: ['DELETE'])]
    public function delete(int $id): JsonResponse
    {
        return parent::delete($id);
    }

    #[Route('/{id}/status', name: 'toggle_status', methods: ['PUT', 'PATCH'])]
    public function toogleStatus(int $id, Request $request): JsonResponse
    {
        return parent::toogleStatus($id, $request);
    }
}
