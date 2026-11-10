<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Compatibility\VerificationContext;
use App\Compatibility\Wine\AppellationRules;
use App\Compatibility\WineCompatibilityChecker;
use App\Enum\IssueSeverity;
use App\Tests\Support\ProductFactory as F;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class AppellationRulesTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string, list<string>}>
     */
    public static function classicWines(): iterable
    {
        yield 'Saint-Joseph, syrah du jeu de données' => ['Rhone', 'Saint-Joseph', ['Syrah & Shiraz']];
        yield 'Saint-Joseph, syrah seule' => ['Rhone', 'Saint-Joseph', ['Syrah']];
        yield 'Saint-Joseph blanc' => ['Rhone', 'Saint-Joseph', ['Marsanne', 'Rousaanne']];
        yield 'Hermitage' => ['Rhone', 'Hermitage', ['Syrah & Shiraz']];
        yield 'Crozes-Hermitage' => ['Rhone', 'Crozes-Hermitage', ['Syrah & Shiraz', 'Marsanne']];
        yield 'Cornas' => ['Rhone', 'Cornas', ['Syrah & Shiraz']];
        yield 'Côte-Rôtie' => ['Rhone', 'Cote-Rotie', ['Syrah & Shiraz', 'Viognier']];
        yield 'Châteauneuf-du-Pape' => ['Rhone', 'Chateauneuf-du-Pape', ['Grenache', 'Syrah & Shiraz', 'mourvedre', 'Cinsault', 'Etc']];
        yield 'Côtes du Rhône' => ['Rhone', 'Cotes du Rhone', ['Grenache', 'Syrah & Shiraz', 'mourvedre', 'Grenache Blanc', 'Rousaanne']];
        yield 'Gigondas' => ['Rhone', 'Gigondas', ['Grenache', 'Syrah & Shiraz', 'mourvedre', 'Carignan']];
        yield 'Pauillac' => ['Bordeaux', 'Pauillac', ['Cabernet Sauvignon', 'Merlot', 'Petit Verdot']];
        yield 'Pomerol' => ['Bordeaux', 'Pomerol', ['Merlot', 'Cabernet Franc']];
        yield 'Graves' => ['Bordeaux', 'Graves', ['Merlot', 'Cabernet Sauvignon', 'Sauvignon blanc', 'Semillon']];
        yield 'Sauternes avec « Muscadet » du jeu de données' => ['Bordeaux', 'Sauternes', ['Semillon', 'Sauvignon blanc', 'Muscadet']];
        yield 'Saint-Émilion Grand Cru' => ['Bordeaux', 'Saint Emilion Grand Cru', ['Merlot', 'Cabernet Franc', 'Etc']];
        yield 'Gevrey-Chambertin' => ['Bourgogne', 'Gevrey-Chambertin', ['Pinot Noir']];
        yield 'Nuits-St-Georges' => ['Bourgogne', 'Nuits-St-Georges', ['Pinot Noir', 'Chardonnay']];
        yield 'Chablis Premier Cru' => ['Bourgogne', 'Chablis Premier Cru', ['Chardonnay']];
        yield 'Meursault' => ['Bourgogne', 'Meursault', ['Chardonnay']];
        yield 'Beaujolais' => ['Bourgogne', 'Beaujolais', ['Gamay']];
        yield 'Corbières (Languedoc)' => ['Southern France', 'Corbieres', ['Syrah & Shiraz', 'Grenache', 'Carignan', 'mourvedre', 'Cinsault']];
        yield 'Languedoc-Roussillon' => ['Southern France', 'Languedoc-Roussillon', ['Cabernet Sauvignon', 'Merlot', 'Chardonnay']];
        yield 'Appellation hors matrice' => ['Loire', 'Sancerre', ['Sauvignon blanc', 'Pinot Noir']];
    }

    /**
     * @param list<string> $grapes
     */
    #[DataProvider('classicWines')]
    public function testClassicsAreNeverRejected(string $region, string $appellation, array $grapes): void
    {
        $wine = F::wine(region: $region, appellation: $appellation, cepages: $grapes);

        $result = (new WineCompatibilityChecker())->verify([$wine], new VerificationContext(servingYear: 2026));

        self::assertSame([], array_map(static fn ($i): string => $i->code.' '.$i->message, $result->issues));
        self::assertTrue($result->isCompatible());
    }

    public function testSyrahIsTheGrapeOfTheNorthernRhoneAndOtherGrapesAreOnlyFlagged(): void
    {
        $checker = new WineCompatibilityChecker();

        $wrongGrape = $checker->verify([F::wine(region: 'Rhone', appellation: 'Saint-Joseph', cepages: ['Pinot Noir'])]);
        $wrongRegion = $checker->verify([F::wine(region: 'Bourgogne', appellation: 'Hermitage', cepages: ['Syrah'])]);
        $wrongRegion2 = $checker->verify([F::wine(region: 'Bordeaux', appellation: 'Cornas', cepages: ['Syrah'])]);

        // Incohérences d'appellation : avertissements informatifs, jamais bloquants.
        self::assertTrue($wrongGrape->isCompatible());
        self::assertSame('CEPAGE_APPELLATION_MISMATCH', $wrongGrape->issues[0]->code);
        self::assertSame(IssueSeverity::Warning, $wrongGrape->issues[0]->severity);
        self::assertTrue($wrongRegion->isCompatible());
        self::assertSame('APPELLATION_REGION_MISMATCH', $wrongRegion->issues[0]->code);
        self::assertSame(IssueSeverity::Warning, $wrongRegion->issues[0]->severity);
        self::assertTrue($wrongRegion2->isCompatible());
    }

    public function testLooseLanguedocRulesOnlyWarn(): void
    {
        $result = (new WineCompatibilityChecker())->verify([F::wine(region: 'Southern France', appellation: 'Provence', cepages: ['Pinot Gris', 'Cortese'])]);

        self::assertTrue($result->isCompatible(), 'un écart sur une appellation à règlement ouvert est un avertissement');
        self::assertSame('CEPAGE_APPELLATION_MISMATCH', $result->issues[0]->code);
    }

    public function testNormalizationAndLookup(): void
    {
        self::assertSame('nuits saint georges', AppellationRules::normalize('Nuits-St-Georges'));
        self::assertSame('syrah', AppellationRules::grapeKey('Syrah & Shiraz'));
        self::assertSame('grenache', AppellationRules::grapeKey('Grenache Blanc'));
        self::assertSame('roussanne', AppellationRules::grapeKey('Rousaanne'));
        self::assertNull(AppellationRules::grapeKey('Etc'));
        self::assertNull(AppellationRules::grapeKey('Bordeaux Blend Red'));
        self::assertSame(AppellationRules::find('Saint Emilion'), AppellationRules::find('Saint-Emilion Grand Cru'));
        self::assertNull(AppellationRules::find('Appellation inventée'));
        self::assertNull(AppellationRules::regionFamily('Toscana'));
    }
}
