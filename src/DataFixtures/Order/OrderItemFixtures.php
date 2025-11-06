<?php

declare(strict_types=1);

namespace App\DataFixtures\Order;

use App\Entity\Order\Order;
use App\Entity\Order\OrderItem;
use App\Entity\Product\ProductVariant;
use App\Repository\Order\OrderRepository;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Common\DataFixtures\DependentFixtureInterface;
use Doctrine\Persistence\ObjectManager;
use Faker\Factory as FakerFactory;

class OrderItemFixtures extends Fixture implements DependentFixtureInterface
{
    public function load(ObjectManager $manager): void
    {
        $faker = FakerFactory::create('fr_FR');

        /** @var OrderRepository $orderRepo */
        $orderRepo = $manager->getRepository(Order::class);

        // On charge toutes les commandes créées par OrderFixtures
        /** @var Order[] $orders */
        $orders = $orderRepo->createQueryBuilder('o')
            ->orderBy('o.id', 'ASC')
            ->getQuery()
            ->getResult();

        // On récupère quelques variantes si la base en contient
        $variants = $this->pickSomeVariants($manager, 60);

        foreach ($orders as $order) {
            // Nombre d’items aléatoire (1..4)
            $itemsCount = $faker->numberBetween(1, 4);

            $subtotal = 0.0;
            $taxSum   = 0.0;

            for ($i = 0; $i < $itemsCount; $i++) {
                $qty       = $faker->numberBetween(1, 3);
                $unitPrice = $faker->randomFloat(2, 5, 80); // 5.00–80.00

                $item = new OrderItem();
                $item->setOrder($order);
                $item->setQuantity($qty);
                $item->setUnitPrice($unitPrice);
                $item->setTaxRate(20.0);

                // Snapshot produit
                $variant = $variants ? $faker->randomElement($variants) : null;
                if ($variant instanceof ProductVariant) {
                    $item->setVariant($variant); // sync Product
                    $item->setProductSnapshot($this->makeProductSnapshot($variant));
                } else {
                    $item->setProductSnapshot($this->makeFallbackSnapshot($i));
                }

                // Taxe ligne
                $lineSubtotal = $item->getLineSubtotal();
                $lineTax      = round($lineSubtotal * ($item->getTaxRate() / 100), 2);
                $item->setTaxAmount($lineTax);

                $subtotal += $lineSubtotal;
                $taxSum   += $lineTax;

                $order->addItem($item);
                $manager->persist($item);
            }

            // Met à jour les totaux de la commande
            $order->setSubtotal(round($subtotal, 2));
            $order->setTaxAmount(round($taxSum, 2));
            $order->setGrandTotal(round($subtotal + $taxSum + $order->getShippingCost(), 2));

            $manager->persist($order);
        }

        $manager->flush();
    }

    private function makeProductSnapshot(ProductVariant $variant): array
    {
        $product     = $variant->getProduct();
        $productId   = $product?->getId();
        $productName = method_exists($product, 'getName') ? $product->getName() : 'Produit';
        $productSlug = method_exists($product, 'getSlug') ? $product->getSlug() : 'produit';

        $variantId   = $variant->getId();
        $variantSku  = method_exists($variant, 'getSku') ? $variant->getSku() : ('SKU-' . $variantId);
        $variantName = method_exists($variant, 'getName') ? $variant->getName()
            : (method_exists($variant, 'getTitle') ? $variant->getTitle() : 'Variante');

        $fullName    = method_exists($variant, 'getFullName')
            ? $variant->getFullName()
            : trim($productName . ' - ' . $variantName);

        $image = method_exists($variant, 'getMainImagePath') ? $variant->getMainImagePath()
            : (method_exists($product, 'getMainImagePath') ? $product->getMainImagePath() : null);

        $weight = method_exists($variant, 'getWeight') ? $variant->getWeight() : null;

        return [
            'product_id'   => $productId,
            'product_name' => $productName,
            'product_slug' => $productSlug,
            'variant_id'   => $variantId,
            'variant_sku'  => $variantSku,
            'variant_name' => $variantName,
            'full_name'    => $fullName,
            'image'        => $image,
            'weight'       => $weight,
            'attributes'   => [],
        ];
    }

    private function makeFallbackSnapshot(int $index): array
    {
        $name = 'Produit ' . ($index + 1);
        return [
            'product_id'   => null,
            'product_name' => $name,
            'product_slug' => 'produit-' . ($index + 1),
            'variant_id'   => null,
            'variant_sku'  => 'SKU-' . ($index + 1),
            'variant_name' => 'Standard',
            'full_name'    => $name . ' - Standard',
            'image'        => null,
            'weight'       => null,
            'attributes'   => [],
        ];
    }

    /**
     * Sélectionne des variantes si dispo.
     * @return ProductVariant[]
     */
    private function pickSomeVariants(ObjectManager $manager, int $limit = 60): array
    {
        $repo = $manager->getRepository(ProductVariant::class);
        if (!method_exists($repo, 'createQueryBuilder')) {
            return [];
        }
        return $repo->createQueryBuilder('v')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    public function getDependencies(): array
    {
        $deps = [
            OrderFixtures::class, // doit passer après la création des orders
        ];
        if (class_exists(\App\DataFixtures\Product\ProductVariantFixtures::class)) {
            $deps[] = \App\DataFixtures\Product\ProductVariantFixtures::class;
        }
        if (class_exists(\App\DataFixtures\Product\ProductFixtures::class)) {
            $deps[] = \App\DataFixtures\Product\ProductFixtures::class;
        }
        return $deps;
    }
}
