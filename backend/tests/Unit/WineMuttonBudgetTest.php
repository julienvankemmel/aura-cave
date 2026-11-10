<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Catalog\Wine\WineDatasetReader;
use App\Catalog\Wine\WineImportStats;
use App\Compatibility\VerificationContext;
use App\Compatibility\WineCompatibilityChecker;
use App\Entity\Product;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Scénario sommelier : grillades de mouton ou entrecôte au barbecue, 50 € maximum. Les vins viennent de lignes
 * réelles de wine_info.csv (Saint-Joseph, Côtes du Rhône, Graves, Pomerol, Châteauneuf-du-Pape) importées par le
 * vrai lecteur, puis jugés par le vrai moteur de compatibilité.
 */
final class WineMuttonBudgetTest extends TestCase
{
    private const BUDGET_CENTS = 5000;

    /** @var list<Product> */
    private array $wines = [];

    protected function setUp(): void
    {
        foreach ((new WineDatasetReader())->rows(__DIR__.'/../Fixtures/wine_info_rhone_bordeaux.csv', 1450.0, new WineImportStats(), null, 2026) as $r) {
            /** @var array<string, mixed> $attributes */
            $attributes = $r['attributes'];
            $this->wines[] = new Product((string) $r['sku'], (string) $r['name'], (string) $r['brand'], 'wine', (int) $r['price_cents'], (string) $r['description'], $attributes, (int) $r['stock']);
        }
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function dishes(): iterable
    {
        yield 'mouton' => ['mouton'];
        yield 'mouton au barbecue' => ['mouton au barbecue'];
        yield 'entrecôte au barbecue' => ['entrecôte au barbecue'];
        yield 'côtes d\'agneau grillées' => ['côtes d\'agneau grillées'];
    }

    #[DataProvider('dishes')]
    public function testEveryRealWineUnderBudgetIsAFullPairingWithAValidAppellation(string $dish): void
    {
        self::assertCount(5, $this->wines);
        $checker = new WineCompatibilityChecker();

        foreach ($this->wines as $wine) {
            $result = $checker->verify([$wine], new VerificationContext(dish: $dish, budgetCents: self::BUDGET_CENTS, servingYear: 2026));
            $codes = array_map(static fn ($i): string => $i->code, $result->issues);
            $label = $wine->name.' / '.$dish;

            self::assertSame(['PAIRING_OK'], $codes, $label.' : aucune erreur d\'appellation ni de cépage, accord validé');
            self::assertTrue($result->isCompatible(), $label);
            self::assertTrue($result->isWithinBudget(), $label.' : '.$wine->priceEuros.' €');
            self::assertSame(1.0, $result->details['pairing']['scores'][$wine->sku], $label.' : score d\'accord');
            self::assertSame(1.0, $result->details['pairing']['score']);
            self::assertSame([$wine->sku], $result->details['pairing']['matches']);
        }
    }

    public function testSaintJosephIsNeverRejectedForItsSyrah(): void
    {
        $saintJoseph = array_values(array_filter($this->wines, static fn (Product $p): bool => 'Saint-Joseph' === $p->attribute('appellation')))[0];

        self::assertSame(['Syrah'], $saintJoseph->attribute('cepages'), 'le lecteur ramène « Syrah & Shiraz » à Syrah');
        $result = (new WineCompatibilityChecker())->verify([$saintJoseph], new VerificationContext(servingYear: 2026));
        self::assertSame([], $result->issues);
    }

    public function testASelectionOverFiftyEurosIsFlaggedEvenWhenThePairingIsPerfect(): void
    {
        $checker = new WineCompatibilityChecker();
        $result = $checker->verify($this->wines, new VerificationContext(dish: 'mouton au barbecue', budgetCents: self::BUDGET_CENTS, servingYear: 2026));

        self::assertFalse($result->isWithinBudget());
        self::assertContains('BUDGET_EXCEEDED', array_map(static fn ($i): string => $i->code, $result->issues));
        self::assertSame(1.0, $result->details['pairing']['score']);
    }
}
