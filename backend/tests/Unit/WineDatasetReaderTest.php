<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Catalog\Wine\WineDatasetReader;
use App\Catalog\Wine\WineImportStats;
use App\Catalog\Wine\WineProfile;
use PHPUnit\Framework\TestCase;

final class WineDatasetReaderTest extends TestCase
{
    private const SAMPLE = __DIR__.'/../Fixtures/wine_info_sample.csv';

    /**
     * @return array{array<string, array<string, mixed>>, WineImportStats}
     */
    private function import(float $rate = 1450.0, ?int $limit = null): array
    {
        $stats = new WineImportStats();
        $rows = [];
        foreach ((new WineDatasetReader())->rows(self::SAMPLE, $rate, $stats, $limit, 2026) as $row) {
            $rows[(string) $row['sku']] = $row;
        }

        return [$rows, $stats];
    }

    public function testEveryCsvLineEndsUpInExactlyOneCounter(): void
    {
        [$rows, $stats] = $this->import();

        self::assertSame(10, $stats->read);
        self::assertSame(5, $stats->imported);
        self::assertSame(1, $stats->skippedNoPrice);
        self::assertSame(1, $stats->skippedNotWine);
        self::assertSame(1, $stats->skippedMalformed);
        self::assertSame(2, $stats->skippedDuplicate);
        self::assertCount(5, $rows);
        self::assertSame($stats->read, $stats->imported + $stats->skippedNoPrice + $stats->skippedNotWine + $stats->skippedMalformed + $stats->skippedDuplicate);
    }

    public function testMapsCommonCoreAndConvertsKoreanWonToEuros(): void
    {
        [$rows] = $this->import(1450.0);
        $clos = $rows['WINE-137197'];

        self::assertSame('Clos Fictif 2014', $clos['name'], 'le millésime est ajouté au nom pour distinguer les années');
        self::assertSame('Clos Fictif', $clos['brand'], 'le producteur coréen + anglais est ramené à sa partie latine');
        self::assertSame('wine', $clos['category']);
        self::assertSame((int) round(220000 / 1450 * 100), $clos['price_cents']);
        self::assertGreaterThan(0, $clos['stock']);
        self::assertContains('rouge', $clos['tags']);
    }

    public function testConversionRateDrivesThePrice(): void
    {
        [$cheap] = $this->import(2900.0);
        [$dear] = $this->import(725.0);

        self::assertSame((int) round(220000 / 2900 * 100), $cheap['WINE-137197']['price_cents']);
        self::assertSame((int) round(220000 / 725 * 100), $dear['WINE-137197']['price_cents']);
    }

    public function testBuildsTheDynamicAttributesFromTheCsv(): void
    {
        [$rows] = $this->import();
        $a = $rows['WINE-137197']['attributes'];

        self::assertSame('rouge', $a['type']);
        self::assertSame('Rapel Valley', $a['region']);
        self::assertSame('Chili', $a['country']);
        self::assertSame(['Cabernet Sauvignon', 'Carmenere', 'Merlot'], $a['cepages']);
        self::assertSame(2014, $a['vintage']);
        self::assertSame(75, $a['volume_cl']);
        self::assertSame(14.5, $a['alcohol_degree'], '« 14~15 » devient la moyenne de la plage');
        self::assertSame(['min' => 17.0, 'max' => 19.0], $a['serving_temp_c']);
        self::assertSame([1, 4, 5, 4], [$a['sweetness'], $a['acidity'], $a['body'], $a['tannin']]);
        self::assertSame(['repas'], $a['use']);
        self::assertSame(137197, $a['source']['id']);
        self::assertSame(220000, $a['source']['price_krw']);
        self::assertContains('boeuf', $a['food_pairing'], 'rouge corsé : accord avec le bœuf');
        self::assertNotSame('', $a['tasting_notes']);
    }

    public function testVeryYoungSweetWhiteBecomesLiquoreux(): void
    {
        [$rows] = $this->import();
        $sauternes = $rows['WINE-137322'];

        self::assertSame('liquoreux', $sauternes['attributes']['type']);
        self::assertContains('foie gras', $sauternes['attributes']['food_pairing']);
        self::assertContains('type (blanc très sucré classé liquoreux)', $sauternes['attributes']['source']['derived']);
        self::assertArrayNotHasKey('tannin', $sauternes['attributes'], 'les tanins n\'ont de sens que pour un rouge');
    }

    public function testCleansInvisibleCharactersAndKoreanLeftovers(): void
    {
        [$rows] = $this->import();

        foreach ($rows as $row) {
            self::assertSame(0, preg_match('/[\x{AC00}-\x{D7AF}\x{200B}]/u', json_encode($row, JSON_UNESCAPED_UNICODE) ?: ''), 'ni coréen ni caractère invisible');
            self::assertSame(1, preg_match('/^WINE-\d+$/', (string) $row['sku']));
            self::assertLessThanOrEqual(160, mb_strlen((string) $row['name']));
            self::assertLessThanOrEqual(64, mb_strlen((string) $row['brand']));
        }
        self::assertSame('Mosel-Saar-Ruwer', WineDatasetReader::clean('모젤-자르-루버 Mosel-Saar-Ruwer'));
        self::assertSame('Saint-Emilion', WineDatasetReader::clean('- 생-떼밀리옹 Saint-Emilion'));
        self::assertSame('Ugni blanc', WineDatasetReader::clean("Ugni\u{200B} blanc"));
    }

    public function testStockIsSimulatedButStableAcrossImports(): void
    {
        [$first] = $this->import();
        [$second] = $this->import();

        self::assertSame(array_column($first, 'stock', 'sku'), array_column($second, 'stock', 'sku'));
    }

    public function testLimitStopsTheImport(): void
    {
        [$rows] = $this->import(limit: 2);

        self::assertCount(2, $rows);
    }

    public function testRejectsAnInvalidRateAndAMissingFile(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        iterator_to_array((new WineDatasetReader())->rows(self::SAMPLE, 0.0, new WineImportStats()));
    }

    public function testMissingFileIsReported(): void
    {
        $this->expectException(\RuntimeException::class);
        iterator_to_array((new WineDatasetReader())->rows('/nonexistent/wine.csv', 1450.0, new WineImportStats()));
    }

    public function testPairingHeuristicsFollowTheWineStructure(): void
    {
        self::assertContains('côte de boeuf', WineProfile::foodPairing('rouge', 5, 4, 3, 1));
        self::assertContains('huîtres', WineProfile::foodPairing('blanc', 2, null, 5, 1));
        self::assertContains('dessert', WineProfile::foodPairing('effervescent', 3, null, 3, 4));
        self::assertNotContains('poisson', WineProfile::foodPairing('rouge', 5, 4, 3, 1));
        self::assertSame([], WineProfile::foodPairing('autre', 3, 3, 3, 1));
        self::assertSame('Corsé, tanins fermes, acidité vive, sec.', WineProfile::tastingNotes('rouge', 4, 4, 4, 1));
        self::assertSame('', WineProfile::tastingNotes('rouge', null, null, null, null));
    }
}
