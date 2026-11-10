<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Compatibility\VerificationContext;
use App\Compatibility\WineCompatibilityChecker;
use App\Enum\IssueSeverity;
use App\Tests\Support\ProductFactory as F;
use PHPUnit\Framework\TestCase;

final class WineCompatibilityCheckerTest extends TestCase
{
    private WineCompatibilityChecker $checker;

    protected function setUp(): void
    {
        $this->checker = new WineCompatibilityChecker();
    }

    /**
     * @param list<\App\Compatibility\CompatibilityIssue> $issues
     *
     * @return list<string>
     */
    private static function codes(array $issues): array
    {
        return array_column(array_map(static fn ($i): array => $i->toArray(), $issues), 'code');
    }

    public function testCoherentWineHasNoIssue(): void
    {
        $result = $this->checker->verify([F::wine()], new VerificationContext(servingYear: 2026));

        self::assertTrue($result->isCompatible());
        self::assertSame([], $result->issues);
        self::assertSame(12000, $result->totalCents);
    }

    public function testAppellationMustBelongToTheRegion(): void
    {
        $result = $this->checker->verify([F::wine(region: 'Bourgogne', appellation: 'Pauillac')]);

        self::assertTrue($result->isCompatible(), 'incohérence d\'appellation : avertissement non bloquant');
        self::assertContains('APPELLATION_REGION_MISMATCH', self::codes($result->issues));
    }

    public function testGrapeMustBeAuthorizedInTheAppellation(): void
    {
        $result = $this->checker->verify([F::wine(cepages: ['Cabernet Sauvignon', 'Pinot Noir'])]);

        self::assertTrue($result->isCompatible(), 'cépage inhabituel : avertissement non bloquant');
        $issue = array_values(array_filter($result->issues, static fn ($i): bool => 'CEPAGE_APPELLATION_MISMATCH' === $i->code));
        self::assertCount(1, $issue);
        self::assertStringContainsString('Pinot Noir', $issue[0]->message);
    }

    public function testAccentsAndCaseAreIgnoredInAppellationChecks(): void
    {
        $wine = F::wine(type: 'rouge', region: 'Rhône', appellation: 'Côte-Rôtie', cepages: ['SYRAH', 'viognier']);

        self::assertTrue($this->checker->verify([$wine])->isCompatible());
    }

    public function testVintageInTheFutureIsAnError(): void
    {
        $result = $this->checker->verify([F::wine(vintage: 2031)], new VerificationContext(servingYear: 2026));

        self::assertContains('VINTAGE_IN_FUTURE', self::codes($result->issues));
        self::assertFalse($result->isCompatible());
    }

    public function testDrinkingWindowProducesWarningsOnly(): void
    {
        $young = F::wine(sku: 'YOUNG', extra: ['drinking_window' => ['from' => 2035, 'until' => 2060]]);
        $old = F::wine(sku: 'OLD', extra: ['drinking_window' => ['from' => 2000, 'until' => 2020]]);

        $result = $this->checker->verify([$young, $old], new VerificationContext(servingYear: 2026));

        self::assertSame(['TOO_YOUNG', 'PAST_PEAK'], self::codes($result->issues));
        self::assertTrue($result->isCompatible(), 'un millésime hors fenêtre est un avertissement, pas une erreur');
        self::assertSame([IssueSeverity::Warning, IssueSeverity::Warning], array_map(static fn ($i): IssueSeverity => $i->severity, $result->issues));
    }

    public function testFoodPairingMatchAndMismatch(): void
    {
        $wine = F::wine(pairings: ['agneau', 'fromages affinés']);

        $ok = $this->checker->verify([$wine], new VerificationContext(dish: 'Un gigot d\'agneau aux herbes', servingYear: 2026));
        self::assertSame(['PAIRING_OK'], self::codes($ok->issues));
        self::assertSame(['WINE-1'], $ok->details['pairing']['matches']);

        $ko = $this->checker->verify([$wine], new VerificationContext(dish: 'Une salade de chèvre chaud', servingYear: 2026));
        self::assertSame(['PAIRING_MISMATCH'], self::codes($ko->issues));
        self::assertSame(['WINE-1'], $ko->details['pairing']['mismatches']);
        self::assertTrue($ko->isCompatible());
    }

    public function testPairingToleratesASingleCharacterTypo(): void
    {
        $wine = F::wine(pairings: ['boeuf', 'agneau']);

        $typo = $this->checker->verify([$wine], new VerificationContext(dish: 'côte de bœff', servingYear: 2026));
        self::assertSame(['PAIRING_OK'], self::codes($typo->issues));

        $other = $this->checker->verify([$wine], new VerificationContext(dish: 'brochette de poulet', servingYear: 2026));
        self::assertSame(['PAIRING_MISMATCH'], self::codes($other->issues), 'pas de faux positif sur un mot différent');
    }

    public function testRedWineClashesWithFishAndDessertNeedsSweetWine(): void
    {
        $red = F::wine(pairings: ['poisson', 'dessert']);
        $sweet = F::wine(sku: 'SWEET', type: 'liquoreux', region: 'Bordeaux', appellation: 'Sauternes', cepages: ['Sémillon'], pairings: ['dessert']);

        $fish = $this->checker->verify([$red], new VerificationContext(dish: 'poisson grillé', servingYear: 2026));
        self::assertContains('PAIRING_TYPE_CLASH', self::codes($fish->issues));

        $dessert = $this->checker->verify([$red, $sweet], new VerificationContext(dish: 'tarte aux fruits et dessert', servingYear: 2026));
        self::assertSame(['WINE-1'], $dessert->details['pairing']['mismatches']);
        self::assertSame(['SWEET'], $dessert->details['pairing']['matches']);
    }

    public function testBudgetIsEnforcedOnTheWholeSelection(): void
    {
        $result = $this->checker->verify([F::wine(price: 30000), F::wine(sku: 'WINE-2', price: 25000)], new VerificationContext(budgetCents: 50000));

        self::assertFalse($result->isWithinBudget());
        self::assertContains('BUDGET_EXCEEDED', self::codes($result->issues));
        self::assertSame(55000, $result->totalCents);
    }

    public function testStrictModeRequiresAtLeastOneWine(): void
    {
        $result = $this->checker->verify([], new VerificationContext(strict: true));

        self::assertContains('EMPTY_SELECTION', self::codes($result->issues));
    }

    public function testDessertDishAcceptsASweetWineFlaggedForDessertInTheDataset(): void
    {
        $sweet = F::wine(type: 'rouge', pairings: ['tarte'], extra: ['use' => ['repas', 'dessert'], 'sweetness' => 4]);
        $dry = F::wine(sku: 'WINE-DRY', type: 'rouge', pairings: ['tarte'], extra: ['use' => ['repas', 'dessert'], 'sweetness' => 1]);

        $ok = $this->checker->verify([$sweet], new VerificationContext(dish: 'tarte aux fruits', servingYear: 2026));
        $clash = $this->checker->verify([$dry], new VerificationContext(dish: 'tarte aux fruits', servingYear: 2026));

        self::assertNotContains('PAIRING_TYPE_CLASH', self::codes($ok->issues));
        self::assertContains('PAIRING_OK', self::codes($ok->issues));
        self::assertContains('PAIRING_TYPE_CLASH', self::codes($clash->issues), 'un vin sec n\'accompagne pas un dessert, même annoncé « dessert »');
    }

    public function testWineWithoutAppellationIsNotRejectedByAppellationRules(): void
    {
        $wine = F::wine(region: 'Toscana', appellation: '', cepages: ['Sangiovese']);

        $result = $this->checker->verify([$wine], new VerificationContext(servingYear: 2026));

        self::assertSame([], self::codes($result->issues));
    }
}
