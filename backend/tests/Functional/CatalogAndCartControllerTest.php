<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Tests\Support\ProductFactory as F;

final class CatalogAndCartControllerTest extends ApiTestCase
{
    public function testSeedLoadsTheFullCatalogWithEmbeddings(): void
    {
        self::assertSame(44, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM product'));
        self::assertSame(44, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM product WHERE vector_dims(embedding) = 384'));
    }

    public function testApiRequiresInternalKey(): void
    {
        $data = $this->api('POST', '/api/products/search', ['query' => 'pauillac'], auth: false);
        self::assertResponseStatusCodeSame(401);
        self::assertSame('unauthorized', $this->path($data, 'error', 'code'));
    }

    public function testSearchAppliesStrictBudgetFilterAndCategory(): void
    {
        $data = $this->api('POST', '/api/products/search', [
            'query' => 'grand vin rouge de Bordeaux',
            'budget_cents' => 20000,
            'filters' => [['key' => 'region', 'op' => 'eq', 'value' => 'Bordeaux']],
            'category' => 'wine',
            'limit' => 5,
        ]);

        self::assertResponseIsSuccessful();
        $results = $this->results($data);
        self::assertNotEmpty($results);
        self::assertLessThanOrEqual(5, \count($results));
        foreach ($results as $r) {
            self::assertSame('wine', $r['category']);
            self::assertSame('Bordeaux', $r['attributes']['region']);
            self::assertLessThanOrEqual(20000, $r['price_cents']);
        }
        $scores = array_column($results, 'score');
        $sorted = $scores;
        rsort($sorted);
        self::assertSame($sorted, $scores, 'Résultats triés par similarité décroissante.');
    }

    public function testVectorSearchRanksRelevantProductFirst(): void
    {
        $data = $this->api('POST', '/api/products/search', ['query' => 'Pétrus Pomerol merlot', 'limit' => 3]);

        self::assertSame('vector', $data['strategy']);
        self::assertSame('PETRUS-2014', $this->results($data)[0]['sku']);
    }

    public function testSearchFallsBackToFullTextWhenVectorFindsNothing(): void
    {
        // "sommel" n'est un mot entier d'aucun document : similarité nulle, mais sous-chaîne de « Sommelier ».
        $data = $this->api('POST', '/api/products/search', ['query' => 'sommel', 'limit' => 10]);

        self::assertResponseIsSuccessful();
        self::assertSame('fulltext', $data['strategy']);
        $results = $this->results($data);
        self::assertNotEmpty($results);
        foreach ($results as $r) {
            self::assertSame('accessory', $r['category']);
        }
    }

    public function testSearchValidationErrorsAreJsonWithoutEcho(): void
    {
        $data = $this->api('POST', '/api/products/search', ['query' => '', 'budget_cents' => -5, 'limit' => 500, 'filters' => [['key' => 'Bad Key!', 'op' => 'bogus']]]);

        self::assertResponseStatusCodeSame(422);
        self::assertSame('validation_failed', $this->path($data, 'error', 'code'));
        self::assertStringNotContainsString('Bad Key!', (string) json_encode($data));
    }

    public function testVerifySelectionValidatesAppellationBudgetAndPairing(): void
    {
        $data = $this->api('POST', '/api/compatibility/verify-selection', [
            'item_ids' => [$this->productId('PICHON-BARON-2016'), $this->productId('COS-ESTOURNEL-2015')],
            'budget_cents' => 40000,
            'dish' => 'côte de boeuf',
            'serving_year' => 2026,
        ]);

        self::assertResponseIsSuccessful();
        self::assertTrue($data['compatible']);
        self::assertTrue($data['within_budget']);
        self::assertSame(13500 + 14000, $data['total_cents']);
        self::assertSame(['PICHON-BARON-2016', 'COS-ESTOURNEL-2015'], $this->path($data, 'pairing', 'matches'));
    }

    public function testVerifySelectionFlagsAnAppellationConflictWithoutBlocking(): void
    {
        $this->em->persist(F::wine('BAD-WINE', region: 'Bourgogne', appellation: 'Pauillac'));
        $this->em->flush();

        $data = $this->api('POST', '/api/compatibility/verify-selection', ['item_ids' => [$this->productId('BAD-WINE')]]);

        self::assertResponseIsSuccessful();
        self::assertTrue($data['compatible'], 'avertissement informatif, pas une exclusion');
        self::assertContains('APPELLATION_REGION_MISMATCH', array_column($this->issues($data), 'code'));
    }

    public function testVerifySelectionFlagsAnEmptySelectionAndRejectsUnknownIds(): void
    {
        $accessoryOnly = $this->api('POST', '/api/compatibility/verify-selection', ['item_ids' => [$this->productId('RIEDEL-CARAFE-ULTRA')]]);
        self::assertResponseIsSuccessful();
        self::assertFalse($accessoryOnly['compatible']);
        self::assertContains('EMPTY_SELECTION', array_column($this->issues($accessoryOnly), 'code'));

        $this->api('POST', '/api/compatibility/verify-selection', ['item_ids' => [$this->productId('PICHON-BARON-2016'), '6f1c2a54-3b8e-4f0a-9d71-0a5c8e2b7d99']]);
        self::assertResponseStatusCodeSame(404);

        $this->api('POST', '/api/compatibility/verify-selection', ['item_ids' => ['not-a-uuid', $this->productId('PICHON-BARON-2016')]]);
        self::assertResponseStatusCodeSame(422);
    }

    public function testAddingItemsBuildsCartAndMergesQuantities(): void
    {
        $uri = '/api/cart/'.self::CART_ID.'/items';
        $this->api('POST', $uri, ['product_id' => $this->productId('PICHON-BARON-2016')]);
        self::assertResponseStatusCodeSame(201);
        $this->api('POST', $uri, ['product_id' => $this->productId('RIEDEL-CARAFE-ULTRA'), 'quantity' => 2]);
        $data = $this->api('POST', $uri, ['product_id' => $this->productId('RIEDEL-CARAFE-ULTRA')]);

        self::assertSame(13500 + 3 * 14900, $data['total_cents']);
        self::assertCount(2, (array) $data['items']);
        self::assertTrue($this->path($data, 'compatibility', 'compatible'));
    }

    public function testRemovingAnItemUpdatesCartTotalAndCompatibility(): void
    {
        $this->em->persist(F::wine('BAD-WINE', region: 'Bourgogne', appellation: 'Pauillac', price: 5000));
        $this->em->flush();
        $uri = '/api/cart/'.self::CART_ID.'/items';
        $this->api('POST', $uri, ['product_id' => $this->productId('PICHON-BARON-2016')]);
        $mixed = $this->api('POST', $uri, ['product_id' => $this->productId('BAD-WINE')]);
        self::assertTrue($this->path($mixed, 'compatibility', 'compatible'), 'Pauillac déclaré en Bourgogne : simple avertissement.');
        self::assertContains('APPELLATION_REGION_MISMATCH', array_column((array) $this->path($mixed, 'compatibility', 'issues'), 'code'));

        $data = $this->api('DELETE', $uri.'/'.$this->productId('BAD-WINE'));

        self::assertResponseIsSuccessful();
        self::assertSame(13500, $data['total_cents']);
        self::assertCount(1, (array) $data['items']);
        self::assertNotContains('APPELLATION_REGION_MISMATCH', array_column((array) $this->path($data, 'compatibility', 'issues'), 'code'), 'L\'avertissement disparaît avec le vin retiré.');
        self::assertSame(1, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM cart_item'));
    }

    public function testRemovingTheLastItemEmptiesTheCart(): void
    {
        $uri = '/api/cart/'.self::CART_ID.'/items';
        $this->api('POST', $uri, ['product_id' => $this->productId('PICHON-BARON-2016')]);

        $data = $this->api('DELETE', $uri.'/'.$this->productId('PICHON-BARON-2016'));

        self::assertResponseIsSuccessful();
        self::assertSame([], $data['items']);
        self::assertSame(0, $data['total_cents']);
    }

    public function testRemoveItemErrors(): void
    {
        $uri = '/api/cart/'.self::CART_ID.'/items';
        $this->api('POST', $uri, ['product_id' => $this->productId('PICHON-BARON-2016')]);

        // produit présent au catalogue mais pas dans le panier
        $data = $this->api('DELETE', $uri.'/'.$this->productId('COS-ESTOURNEL-2015'));
        self::assertResponseStatusCodeSame(404);
        self::assertSame('item_not_in_cart', $this->path($data, 'error', 'code'));

        // panier inconnu
        $this->api('DELETE', '/api/cart/6f1c2a54-3b8e-4f0a-9d71-0a5c8e2b7d99/items/'.$this->productId('PICHON-BARON-2016'));
        self::assertResponseStatusCodeSame(404);

        // identifiants invalides (route non reconnue)
        $this->api('DELETE', $uri.'/not-a-uuid');
        self::assertResponseStatusCodeSame(404);

        // authentification requise
        $this->api('DELETE', $uri.'/'.$this->productId('PICHON-BARON-2016'), auth: false);
        self::assertResponseStatusCodeSame(401);
        self::assertSame(1, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM cart_item'));
    }

    public function testAddItemRejectsUnknownProductInvalidQuantityAndBadCartId(): void
    {
        $uri = '/api/cart/'.self::CART_ID.'/items';
        $this->api('POST', $uri, ['product_id' => '6f1c2a54-3b8e-4f0a-9d71-0a5c8e2b7d99']);
        self::assertResponseStatusCodeSame(404);

        $this->api('POST', $uri, ['product_id' => $this->productId('PICHON-BARON-2016'), 'quantity' => 0]);
        self::assertResponseStatusCodeSame(422);

        $this->api('POST', '/api/cart/not-a-uuid/items', ['product_id' => $this->productId('PICHON-BARON-2016')]);
        self::assertResponseStatusCodeSame(404);
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return list<array<string, mixed>>
     */
    private function results(array $data): array
    {
        /** @var list<array<string, mixed>> $results */
        $results = $data['results'];

        return $results;
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return list<array<string, mixed>>
     */
    private function issues(array $data): array
    {
        /** @var list<array<string, mixed>> $issues */
        $issues = $data['issues'];

        return $issues;
    }

    /**
     * @param array<string, mixed> $data
     */
    private function path(array $data, string ...$keys): mixed
    {
        $node = $data;
        foreach ($keys as $key) {
            self::assertIsArray($node);
            self::assertArrayHasKey($key, $node);
            $node = $node[$key];
        }

        return $node;
    }
}
