<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Compatibility\WineCompatibilityChecker;
use App\Entity\Product;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Catalogue vin : recherche sémantique, filtres d'attributs, vérification des accords, stock du panier, commande de seed.
 */
final class WineCatalogTest extends ApiTestCase
{
    public function testSearchResponseCarriesNoThemeField(): void
    {
        self::assertSame(44, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM product'));

        $data = $this->api('POST', '/api/products/search', ['query' => 'grand vin rouge de Pauillac', 'limit' => 20]);
        self::assertNotEmpty($data['results']);
        self::assertArrayNotHasKey('theme', $data);
        foreach ($data['results'] as $r) {
            self::assertArrayNotHasKey('theme', $r);
        }
    }

    public function testSemanticSearchFindsTheRightWine(): void
    {
        $data = $this->api('POST', '/api/products/search', ['query' => 'Pétrus Pomerol merlot', 'limit' => 3]);

        self::assertSame('PETRUS-2014', $data['results'][0]['sku']);
        self::assertSame('Pomerol', $data['results'][0]['attributes']['appellation']);
    }

    public function testAttributeFiltersOnWineAttributes(): void
    {
        $data = $this->api('POST', '/api/products/search', [
            'query' => 'grand vin blanc', 'category' => 'wine', 'limit' => 20,
            'filters' => [
                ['key' => 'region', 'op' => 'eq', 'value' => 'Bourgogne'],
                ['key' => 'type', 'op' => 'eq', 'value' => 'blanc'],
                ['key' => 'food_pairing', 'op' => 'contains', 'value' => 'homard'],
            ],
        ]);

        self::assertResponseIsSuccessful();
        $skus = array_column($data['results'], 'sku');
        sort($skus);
        self::assertSame(['JADOT-CORTON-CHARLEMAGNE-2018', 'LEFLAIVE-PULIGNY-2018', 'ROULOT-MEURSAULT-2017'], $skus);
        foreach ($data['results'] as $r) {
            self::assertSame('Bourgogne', $r['attributes']['region']);
        }
    }

    public function testBudgetAndVintageRangeFilters(): void
    {
        $data = $this->api('POST', '/api/products/search', [
            'query' => 'vin rouge', 'category' => 'wine', 'budget_cents' => 10000, 'limit' => 20,
            'filters' => [['key' => 'vintage', 'op' => 'gte', 'value' => 2018], ['key' => 'type', 'op' => 'eq', 'value' => 'rouge']],
        ]);

        $skus = array_column($data['results'], 'sku');
        self::assertContains('FAIVELEY-NSG-2019', $skus);
        self::assertNotContains('DUCRU-BEAUCAILLOU-2014', $skus);
        foreach ($data['results'] as $r) {
            self::assertLessThanOrEqual(10000, $r['price_cents']);
            self::assertGreaterThanOrEqual(2018, $r['attributes']['vintage']);
        }
    }

    public function testVerifyUsesTheWineStrategyAndTheDish(): void
    {
        $ids = [$this->productId('PICHON-BARON-2016'), $this->productId('COS-ESTOURNEL-2015')];

        $ok = $this->api('POST', '/api/compatibility/verify-selection', ['item_ids' => $ids, 'dish' => 'côte de boeuf grillée', 'budget_cents' => 40000, 'serving_year' => 2026]);
        self::assertResponseIsSuccessful();
        self::assertTrue($ok['compatible']);
        self::assertTrue($ok['within_budget']);
        self::assertSame(13500 + 14000, $ok['total_cents']);
        self::assertSame(['PICHON-BARON-2016', 'COS-ESTOURNEL-2015'], $ok['pairing']['matches']);

        $ko = $this->api('POST', '/api/compatibility/verify-selection', ['item_ids' => $ids, 'dish' => 'plateau de fruits de mer', 'serving_year' => 2026]);
        self::assertContains('PAIRING_TYPE_CLASH', array_column($ko['issues'], 'code'));
        self::assertTrue($ko['compatible'], 'un mauvais accord est un avertissement');
    }

    public function testCartRespectsStock(): void
    {
        $uri = '/api/cart/'.self::CART_ID.'/items';
        $rare = $this->productId('ROMANEE-CONTI-2015'); // 1 bouteille en stock

        $data = $this->api('POST', $uri, ['product_id' => $rare, 'quantity' => 2]);
        self::assertResponseStatusCodeSame(409);
        self::assertSame('insufficient_stock', $data['error']['code']);
        self::assertSame(1, $data['error']['available']);

        $this->api('POST', $uri, ['product_id' => $rare]);
        self::assertResponseStatusCodeSame(201);
        $this->api('POST', $uri, ['product_id' => $rare]);
        self::assertResponseStatusCodeSame(409, 'le stock tient compte de ce qui est déjà au panier');
    }

    public function testCartSummaryUsesTheWineStrategy(): void
    {
        $uri = '/api/cart/'.self::CART_ID.'/items';
        $this->api('POST', $uri, ['product_id' => $this->productId('YQUEM-2011')]);
        $data = $this->api('POST', $uri, ['product_id' => $this->productId('LAGUIOLE-SOMMELIER')]);

        self::assertResponseStatusCodeSame(201);
        self::assertTrue($data['compatibility']['compatible']);
        self::assertArrayHasKey('serving_year', $data['compatibility']);
    }

    public function testEveryWineOfTheCatalogIsCoherent(): void
    {
        /** @var list<Product> $wines */
        $wines = $this->em->getRepository(Product::class)->findBy(['category' => 'wine']);
        self::assertCount(38, $wines);
        $checker = new WineCompatibilityChecker();

        foreach ($wines as $wine) {
            $result = $checker->verify([$wine]);
            self::assertTrue($result->isCompatible(), $wine->sku.' : '.json_encode($result->toArray()['issues']));
            foreach (['type', 'region', 'appellation', 'cepages', 'vintage', 'food_pairing', 'tasting_notes', 'alcohol_degree'] as $key) {
                self::assertNotNull($wine->attribute($key), $wine->sku.' : '.$key);
            }
            self::assertContains($wine->attribute('region'), ['Bordeaux', 'Bourgogne', 'Rhône']);
            self::assertGreaterThan(0, $wine->stock);
        }
    }

    public function testSeedCommandLoadsTheAccessoryCatalogAndPrunesOnlyItsScope(): void
    {
        $application = new Application(self::$kernel ?? static::bootKernel());
        $command = new CommandTester($application->find('app:seed-catalog'));

        self::assertSame(0, $command->execute(['--prune' => true]));
        self::assertStringContainsString('Catalogue chargé : 0 créés, 6 mis à jour', preg_replace('/\s+/', ' ', $command->getDisplay()) ?? '');
    }
}
