<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Compatibility\Wine\FoodFamilies;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class FoodFamiliesTest extends TestCase
{
    /**
     * @return iterable<string, array{string, list<string>}>
     */
    public static function redMeatDishes(): iterable
    {
        foreach (['mouton', 'du mouton au barbecue', 'entrecôte au barbecue', 'entrecôte grillée', 'côtes d\'agneau', 'gigot d\'agneau aux herbes', 'côte de bœuf', 'grillades', 'bavette', 'brochettes de mouton', 'méchoui'] as $dish) {
            yield $dish.' / agneau' => [$dish, ['agneau']];
            yield $dish.' / viande rouge' => [$dish, ['viande rouge']];
            yield $dish.' / boeuf, steak' => [$dish, ['fromages', 'boeuf', 'steak']];
        }
    }

    /**
     * @param list<string> $pairings
     */
    #[DataProvider('redMeatDishes')]
    public function testRedMeatSynonymsAreOneFamily(string $dish, array $pairings): void
    {
        self::assertSame(1.0, FoodFamilies::score($dish, $pairings));
    }

    public function testNeighbouringFamiliesGivePartialAgreement(): void
    {
        $game = FoodFamilies::score('sanglier', ['boeuf']);
        $shellfish = FoodFamilies::score('huîtres', ['poisson']);

        self::assertSame(0.8, $game);
        self::assertSame(0.8, $shellfish);
        self::assertGreaterThanOrEqual(FoodFamilies::MATCH_THRESHOLD, $game);
    }

    public function testUnrelatedDishesScoreZero(): void
    {
        self::assertSame(0.0, FoodFamilies::score('plateau de fruits de mer', ['boeuf', 'agneau', 'viande rouge']));
        self::assertSame(0.0, FoodFamilies::score('tarte aux fruits', ['agneau']));
        self::assertSame(0.0, FoodFamilies::score('brochette de poulet', ['agneau', 'viande rouge']));
        self::assertSame(0.0, FoodFamilies::score('salade de chèvre chaud', ['agneau', 'fromages affinés']));
    }

    public function testFamiliesOfAFreeTextDish(): void
    {
        self::assertSame(['viande_rouge'], FoodFamilies::families('Un bon mouton au barbecue'));
        self::assertContains('viande_rouge', FoodFamilies::families('côtes d\'agneau'));
        self::assertContains('fruits_de_mer', FoodFamilies::families('plateau de fruits de mer'));
        self::assertNotContains('dessert', FoodFamilies::families('plateau de fruits de mer'));
        self::assertContains('pates', FoodFamilies::families('pâtes à la carbonara'));
        self::assertNotContains('pates', FoodFamilies::families('terrine de pâté'));
        self::assertContains('viande_rouge', FoodFamilies::families('côte de bœff'), 'une faute de frappe est tolérée');
    }
}
