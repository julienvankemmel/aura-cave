<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Import du jeu de données réel (extrait de wine_info.csv) : mapping, embeddings pgvector, idempotence, recherche.
 */
final class WineDatasetImportTest extends ApiTestCase
{
    private const SAMPLE = __DIR__.'/../Fixtures/wine_info_sample.csv';

    private function runImport(string ...$extra): CommandTester
    {
        $application = new Application(self::$kernel ?? static::bootKernel());
        $tester = new CommandTester($application->find('app:import-wine-dataset'));
        $tester->execute(['--file' => self::SAMPLE, '--krw-per-eur' => '1450', ...array_fill_keys($extra, true)]);

        return $tester;
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->em->getConnection()->executeStatement('TRUNCATE cart_item, cart, product CASCADE');
        self::assertSame(0, $this->runImport()->getStatusCode());
        $this->em->clear();
    }

    public function testImportsWinesWithCoreColumnsAndJsonAttributes(): void
    {
        $db = $this->em->getConnection();

        self::assertSame(5, (int) $db->fetchOne("SELECT COUNT(*) FROM product WHERE category = 'wine'"));
        $row = $db->fetchAssociative("SELECT name, brand, price_cents, stock, attributes->>'type' AS type, attributes->>'country' AS country, attributes->'cepages' AS grapes FROM product WHERE sku = 'WINE-137197'");
        self::assertIsArray($row);
        self::assertSame('Clos Fictif 2014', $row['name']);
        self::assertSame('rouge', $row['type']);
        self::assertSame('Chili', $row['country']);
        self::assertSame(['Cabernet Sauvignon', 'Carmenere', 'Merlot'], json_decode((string) $row['grapes'], true));
        self::assertSame(15172, $row['price_cents']);
        self::assertSame(5, (int) $db->fetchOne('SELECT COUNT(*) FROM product WHERE vector_dims(embedding) = 384'), 'chaque vin a un embedding 384d');
    }

    public function testReimportIsIdempotentAndKeepsOtherCategories(): void
    {
        $db = $this->em->getConnection();
        $db->executeStatement("INSERT INTO product (id, sku, name, brand, category, price_cents, description, attributes, stock, tags) VALUES (gen_random_uuid(), 'ACC-1', 'Carafe', 'X', 'accessory', 5000, '', '{}', 3, '[]')");

        $tester = $this->runImport('--prune');

        self::assertSame(0, $tester->getStatusCode());
        self::assertStringContainsString('0 créés, 5 mis à jour', $tester->getDisplay());
        self::assertSame(5, (int) $db->fetchOne("SELECT COUNT(*) FROM product WHERE category = 'wine'"));
        self::assertSame(1, (int) $db->fetchOne("SELECT COUNT(*) FROM product WHERE sku = 'ACC-1'"), 'le prune du CSV ne touche que les vins');
    }

    public function testAccessoryCatalogPruneDoesNotDeleteImportedWines(): void
    {
        $application = new Application(self::$kernel ?? static::bootKernel());
        $seed = new CommandTester($application->find('app:seed-catalog'));

        self::assertSame(0, $seed->execute(['--prune' => true]));

        $db = $this->em->getConnection();
        self::assertSame(5, (int) $db->fetchOne("SELECT COUNT(*) FROM product WHERE category = 'wine'"), 'le catalogue des accessoires déclare scope_categories');
        self::assertSame(6, (int) $db->fetchOne("SELECT COUNT(*) FROM product WHERE category = 'accessory'"));
    }

    public function testPruneRemovesWinesAbsentFromTheCsv(): void
    {
        $db = $this->em->getConnection();
        $db->executeStatement("INSERT INTO product (id, sku, name, brand, category, price_cents, description, attributes, stock, tags) VALUES (gen_random_uuid(), 'OLD-WINE', 'Ancien', 'X', 'wine', 5000, '', '{}', 3, '[]')");

        $tester = $this->runImport('--prune');

        self::assertStringContainsString('1 vin(s) retiré(s)', $tester->getDisplay());
        self::assertSame(0, (int) $db->fetchOne("SELECT COUNT(*) FROM product WHERE sku = 'OLD-WINE'"));
    }

    public function testSemanticSearchRunsOnImportedWinesAndAttributeFilters(): void
    {
        $data = $this->api('POST', '/api/products/search', ['query' => 'Chablis chardonnay Maison Delorme', 'category' => 'wine', 'limit' => 5]);
        self::assertSame('WINE-137340', $data['results'][0]['sku']);

        $filtered = $this->api('POST', '/api/products/search', [
            'query' => 'vin rouge puissant', 'category' => 'wine', 'limit' => 10,
            'filters' => [['key' => 'country', 'op' => 'eq', 'value' => 'Italie'], ['key' => 'body', 'op' => 'gte', 'value' => 4]],
        ]);
        self::assertSame(['WINE-137401'], array_column($filtered['results'], 'sku'));
        self::assertSame('Italie', $filtered['results'][0]['attributes']['country']);
    }

    public function testCompatibilityEngineValidatesAPairingOnImportedData(): void
    {
        $ids = array_column($this->api('POST', '/api/products/search', ['query' => 'Tenuta Bellavista Riserva Ducale', 'category' => 'wine', 'limit' => 1])['results'], 'id');
        $sauternes = array_column($this->api('POST', '/api/products/search', ['query' => 'Verdelac Sauternes', 'category' => 'wine', 'limit' => 1])['results'], 'id');

        $beef = $this->api('POST', '/api/compatibility/verify-selection', ['item_ids' => $ids, 'dish' => 'côte de boeuf', 'serving_year' => 2026]);
        $clash = $this->api('POST', '/api/compatibility/verify-selection', ['item_ids' => $sauternes, 'dish' => 'côte de boeuf', 'serving_year' => 2026]);

        self::assertContains('PAIRING_OK', array_column($beef['issues'], 'code'));
        self::assertContains('PAIRING_MISMATCH', array_column($clash['issues'], 'code'));
    }

    public function testDryRunWritesNothing(): void
    {
        $this->em->getConnection()->executeStatement('DELETE FROM product');

        $tester = $this->runImport('--dry-run');

        self::assertSame(0, $tester->getStatusCode());
        self::assertSame(0, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM product'));
        self::assertStringContainsString('"sku": "WINE-137197"', $tester->getDisplay());
    }

    public function testMissingFileFailsCleanly(): void
    {
        $application = new Application(self::$kernel ?? static::bootKernel());
        $tester = new CommandTester($application->find('app:import-wine-dataset'));

        $tester->execute(['--file' => '/nonexistent/wine_info.csv']);

        self::assertSame(1, $tester->getStatusCode());
        self::assertStringContainsString('illisible', $tester->getDisplay());
    }
}
