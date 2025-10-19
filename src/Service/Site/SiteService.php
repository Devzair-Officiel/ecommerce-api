<?php

declare(strict_types=1);

namespace App\Service\Site;

use App\Entity\Site\Site;
use App\Service\Core\AbstractService;
use App\Repository\Site\SiteRepository;
use App\Service\Core\RelationProcessor;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Serializer\SerializerInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;

class SiteService extends AbstractService
{
    public function __construct(
        EntityManagerInterface $em,
        SerializerInterface $serializer,
        ValidatorInterface $validator,
        RelationProcessor $relationProcessor,
        private readonly SiteRepository $siteRepository
    ) {
        parent::__construct($em, $serializer, $validator, $relationProcessor);
    }

    protected function getEntityClass(): string
    {
        return Site::class;
    }

    protected function getRepository(): SiteRepository
    {
        return $this->siteRepository;
    }

    // protected function getRelationConfig(): array
    // {
    //     return [
    //         'laboratory' => [
    //             'type' => 'many_to_one',
    //             'target_entity' => Laboratory::class,
    //             'identifier_key' => 'id',
    //         ],
    //         'sessions' => [
    //             'type' => 'many_to_many',
    //             'target_entity' => Session::class,
    //             'identifier_key' => 'id',
    //         ],
    //     ];
    // }
}
