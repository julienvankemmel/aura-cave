<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Tests\Support\ProductFactory as F;

/**
 * Filtre déterministe SQL (stock, budget) puis classement : sans clé Gemini (cas des tests), le tri local prend le relais.
 */
final class SommelierRecommendTest extends ApiTestCase
{
    public function testReturnsTheTopThreeEnrichedWithDatabaseData(): void
    {
        $data = $this->api('POST', '/api/sommelier/recommend', ['query' => 'vin rouge pour du mouton au barbecue', 'dish' => 'mouton au barbecue', 'budget_cents' => 5000]);

        self::assertResponseIsSuccessful();
        self::assertSame('ok', $data['status']);
        self::assertSame('fallback', $data['source']);
        self::assertNull($data['model']);
        $results = (array) $data['results'];
        self::assertGreaterThan(0, \count($results));
        self::assertLessThanOrEqual(3, \count($results));
        $favourites = 0;
        foreach ($results as $r) {
            self::assertLessThanOrEqual(5000, $r['price_cents'], 'budget strict');
            self::assertGreaterThan(0, $r['stock'], 'stock réel');
            self::assertNotSame('', $r['sommelier_justification']);
            self::assertIsInt($r['match_score']);
            self::assertArrayHasKey('attributes', $r);
            $favourites += $r['is_coup_de_coeur'] ? 1 : 0;
        }
        self::assertSame(1, $favourites);
    }

    public function testNeverProposesOutOfStockWines(): void
    {
        $this->em->persist(F::wine('SOLD-OUT', price: 3000, stock: 0));
        $this->em->flush();

        $data = $this->api('POST', '/api/sommelier/recommend', ['query' => 'vin rouge de test', 'dish' => 'agneau', 'limit' => 8]);

        self::assertResponseIsSuccessful();
        self::assertNotContains('SOLD-OUT', array_column((array) $data['results'], 'sku'));
    }

    public function testAnswersClearlyWithoutLlmWhenNothingMatchesTheStrictCriteria(): void
    {
        $data = $this->api('POST', '/api/sommelier/recommend', ['query' => 'vin rouge pour un mouton', 'budget_cents' => 1]);

        self::assertResponseIsSuccessful();
        self::assertSame('no_match', $data['status']);
        self::assertSame('no_wine_in_stock_within_budget', $data['reason']);
        self::assertNull($data['source']);
        self::assertSame([], $data['results']);
    }

    public function testValidatesTheRequestAndRequiresTheInternalKey(): void
    {
        $this->api('POST', '/api/sommelier/recommend', ['query' => '']);
        self::assertResponseStatusCodeSame(422);

        $this->api('POST', '/api/sommelier/recommend', ['query' => 'agneau', 'limit' => 50]);
        self::assertResponseStatusCodeSame(422);

        $this->api('POST', '/api/sommelier/recommend', ['query' => 'agneau'], auth: false);
        self::assertResponseStatusCodeSame(401);
    }
}
