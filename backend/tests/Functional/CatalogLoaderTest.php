<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Catalog\CatalogLoader;
use App\Entity\Cart;
use App\Entity\Product;
use Symfony\Component\Uid\Uuid;

final class CatalogLoaderTest extends ApiTestCase
{
    private function loader(): CatalogLoader
    {
        /** @var CatalogLoader $loader */
        $loader = static::getContainer()->get('test.catalog_loader');

        return $loader;
    }

    public function testSeedIsIdempotentAndPruneRemovesObsoleteProductsExceptThoseInACart(): void
    {
        $cart = new Cart(Uuid::v4());
        $product = $this->em->find(Product::class, Uuid::fromString($this->productId('PICHON-BARON-2016')));
        self::assertNotNull($product);
        $cart->addProduct($product, 1);
        $this->em->persist($cart);
        $this->em->flush();
        $this->em->clear();

        $file = sys_get_temp_dir().'/catalog-'.bin2hex(random_bytes(4)).'.json';
        file_put_contents($file, json_encode(['schema_version' => 3, 'products' => [[
            'sku' => 'tmp-wine', 'name' => 'Vin de test', 'brand' => 'Test', 'category' => 'wine', 'price_cents' => 123400, 'stock' => 3,
            'description' => 'Schéma v3', 'tags' => ['test'],
            'attributes' => ['type' => 'rouge', 'region' => 'Bordeaux', 'cepages' => ['Merlot'], 'drinking_window' => ['from' => 2025, 'until' => 2040]],
        ], [
            'sku' => 'tmp-accessory', 'name' => 'Accessoire de test', 'brand' => 'Test', 'category' => 'accessory', 'price_cents' => 5000,
            'description' => 'Sans stock ni attributs',
        ]]], JSON_THROW_ON_ERROR));

        $result = $this->loader()->load($file, true);
        @unlink($file);

        self::assertSame(['created' => 2, 'updated' => 0, 'pruned' => 43, 'kept_in_carts' => 1], $result);
        $db = $this->em->getConnection();
        self::assertSame(3, (int) $db->fetchOne('SELECT COUNT(*) FROM product'), '2 nouveaux + le vin du panier conservé');
        $wine = $db->fetchAssociative("SELECT stock, attributes->>'region' AS region, attributes->'drinking_window' AS window FROM product WHERE sku = 'TMP-WINE'");
        self::assertIsArray($wine);
        self::assertSame(3, $wine['stock']);
        self::assertSame('Bordeaux', $wine['region']);
        self::assertSame(['from' => 2025, 'until' => 2040], json_decode((string) $wine['window'], true));
        $accessory = $db->fetchAssociative("SELECT stock, attributes::text AS attributes FROM product WHERE sku = 'TMP-ACCESSORY'");
        self::assertSame(['stock' => 0, 'attributes' => '[]'], $accessory);
    }

    public function testCatalogFileLoadsTwiceWithoutDuplicates(): void
    {
        $result = $this->loader()->load(__DIR__.'/../Fixtures/catalog_wine_curated.json');

        self::assertSame(['created' => 0, 'updated' => 44, 'pruned' => 0, 'kept_in_carts' => 0], $result);
        self::assertSame(44, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM product'));
    }

    public function testDefaultPathPointsToTheWineCatalog(): void
    {
        self::assertStringEndsWith('/data/catalog_wine.json', $this->loader()->defaultPath());
    }
}
