<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Catalog\CatalogLoader;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

abstract class ApiTestCase extends WebTestCase
{
    protected const API_KEY = 'phpunit-internal-key';
    protected const CART_ID = '6f1c2a54-3b8e-4f0a-9d71-0a5c8e2b7d13';

    private static bool $schemaReady = false;

    protected KernelBrowser $client;
    protected EntityManagerInterface $em;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $container = static::getContainer();
        /** @var EntityManagerInterface $em */
        $em = $container->get('test.entity_manager');
        $this->em = $em;

        if (!self::$schemaReady) {
            $tool = new SchemaTool($em);
            $metadata = $em->getMetadataFactory()->getAllMetadata();
            $tool->dropSchema($metadata);
            $tool->createSchema($metadata);
            self::$schemaReady = true;
        }

        $em->getConnection()->executeStatement('TRUNCATE customer_order, cart_item, cart, product CASCADE');
        /** @var CatalogLoader $loader */
        $loader = $container->get('test.catalog_loader');
        // Catalogue de référence des tests (appellations réelles) : le catalogue livré vient du CSV Kaggle.
        $loader->load(__DIR__.'/../Fixtures/catalog_wine_curated.json');
        $em->clear();
    }

    /**
     * @param array<string, mixed>|null $json
     *
     * @return array<string, mixed>
     */
    protected function api(string $method, string $uri, ?array $json = null, bool $auth = true): array
    {
        $server = ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'];
        if ($auth) {
            $server['HTTP_X_INTERNAL_API_KEY'] = self::API_KEY;
        }
        $this->client->request($method, $uri, server: $server, content: null === $json ? null : json_encode($json, JSON_THROW_ON_ERROR));

        /** @var array<string, mixed> $data */
        $data = json_decode((string) $this->client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);

        return $data;
    }

    protected function productId(string $sku): string
    {
        $id = $this->em->getConnection()->fetchOne('SELECT id FROM product WHERE sku = ?', [$sku]);
        self::assertIsString($id, "SKU inconnu : $sku");

        return $id;
    }
}
