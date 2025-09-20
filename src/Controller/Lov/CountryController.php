<?php 

declare(strict_types=1);

namespace App\Controller\Lov;

use App\Controller\Lov\AbstractLovController;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Validator\Constraints\Country;

#[Route('/countries', name: 'country_')]
class CountryController extends AbstractLovController
{
    protected function getEntityClass(): string
    {
        return Country::class;
    }
    
    protected function getSerializationGroup(): string
    {
        return 'default_lov';
    }

    protected function getEntityName(): string
    {
        return 'country';
    }

    /**
     * Extrait et valide les filtres pour les laboratory.
     */
    protected function getAllowedFilterKeys(): array
    {
        return ['title', 'sortBy', 'sortOrder', 'valid'];
    }
}