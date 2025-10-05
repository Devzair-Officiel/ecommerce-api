<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Product;
use App\Repository\ProductRepository;
use App\Service\Core\AbstractService;
use App\Service\Core\RelationProcessor;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Serializer\SerializerInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;

class ProductService extends AbstractService
{
    public function __construct(
        EntityManagerInterface $em,
        SerializerInterface $serializer,
        ValidatorInterface $validator,
        RelationProcessor $relationProcessor,
        private readonly ProductRepository $productRepository
    ) {
        parent::__construct($em, $serializer, $validator, $relationProcessor);
    }

    protected function getEntityClass(): string
    {
        return Product::class;
    }

    protected function getRepository(): ProductRepository
    {
        return $this->productRepository;
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
