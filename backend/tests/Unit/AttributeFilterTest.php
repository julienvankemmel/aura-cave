<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Catalog\AttributeFilter;
use App\Tests\Support\ProductFactory as F;
use PHPUnit\Framework\TestCase;

final class AttributeFilterTest extends TestCase
{
    public function testAttributeFilterOperators(): void
    {
        $wine = F::wine(extra: ['drinking_window' => ['from' => 2025, 'until' => 2050]]);
        $match = static fn (string $key, string $op, mixed $value, bool $missing = false): bool => (new AttributeFilter($key, $op, $value, $missing))->matches($wine);

        self::assertTrue($match('region', 'eq', 'bordeaux'), 'insensible à la casse');
        self::assertFalse($match('region', 'eq', 'Bourgogne'));
        self::assertTrue($match('type', 'in', ['rouge', 'blanc']));
        self::assertTrue($match('vintage', 'gte', 2015));
        self::assertFalse($match('vintage', 'gte', 2016));
        self::assertTrue($match('vintage', 'lte', 2015));
        self::assertTrue($match('drinking_window.until', 'gte', 2040), 'chemin pointé');
        self::assertTrue($match('cepages', 'contains', 'merlot'), 'élément de liste');
        self::assertTrue($match('appellation', 'contains', 'paui'), 'sous-chaîne de texte');
        self::assertTrue($match('food_pairing', 'any', ['poisson', 'agneau']));
        self::assertFalse($match('food_pairing', 'any', ['poisson', 'sushi']));
        self::assertFalse($match('classification', 'eq', 'Grand Cru'), 'attribut absent exclu');
        self::assertTrue($match('classification', 'eq', 'Grand Cru', true), 'attribut absent toléré avec include_missing');
        self::assertFalse($match('vintage', 'bogus', 1));
    }
}
