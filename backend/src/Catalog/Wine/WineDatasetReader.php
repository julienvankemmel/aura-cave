<?php

declare(strict_types=1);

namespace App\Catalog\Wine;

/**
 * Lit `wine_info.csv` (Kaggle `dev7halo/wine-information`), nettoie les valeurs et produit des lignes au format du
 * catalogue (colonnes communes + `attributes`), prêtes pour CatalogLoader.
 *
 * Particularités du jeu de données : champs bilingues coréen + anglais (« 칠레 Chile »), prix en wons coréens (KRW),
 * 42 % de lignes sans prix, aucune description ni note de dégustation, aucun stock.
 */
final class WineDatasetReader
{
    public const DATASET = 'kaggle:dev7halo/wine-information';

    private const TYPES = ['Red' => 'rouge', 'White' => 'blanc', 'Rose' => 'rosé', 'Sparkling' => 'effervescent', 'Fortified' => 'fortifié'];

    private const COUNTRIES = [
        'France' => 'France', 'Italy' => 'Italie', 'U.S.A' => 'États-Unis', 'USA' => 'États-Unis', 'Chile' => 'Chili',
        'Spain' => 'Espagne', 'Australia' => 'Australie', 'Argentina' => 'Argentine', 'Germany' => 'Allemagne',
        'the Republic of South Africa' => 'Afrique du Sud', 'New Zealand' => 'Nouvelle-Zélande', 'Portugal' => 'Portugal',
        'Austria' => 'Autriche', 'Greece' => 'Grèce', 'Canada' => 'Canada', 'Slovenia' => 'Slovénie', 'Korea' => 'Corée',
        'Hungary' => 'Hongrie', 'Romania' => 'Roumanie', 'Moldova' => 'Moldavie', 'Croatia' => 'Croatie', 'Georgia' => 'Géorgie',
        'Bulgaria' => 'Bulgarie', 'Japan' => 'Japon', 'U.K' => 'Royaume-Uni', 'UK' => 'Royaume-Uni', 'Uruguay' => 'Uruguay',
        'Israel' => 'Israël', 'Azerbaijan' => 'Azerbaïdjan', 'China' => 'Chine', 'Sweden' => 'Suède', 'Lebanon' => 'Liban',
    ];

    /** Cépages : variantes du jeu de données ramenées au nom d'usage (les autres sont gardés tels quels). */
    private const GRAPE_ALIASES = [
        'syrah shiraz' => 'Syrah',
        'shiraz' => 'Syrah',
        'grenache noir' => 'Grenache',
        'garnacha' => 'Grenache',
        'rousaanne' => 'Roussanne',
    ];

    private const USES = ['Appetizer' => 'apéritif', 'Table' => 'repas', 'Dessert' => 'dessert'];

    /**
     * @return \Generator<int, array<string, mixed>> lignes de catalogue (schéma v3)
     */
    public function rows(string $path, float $krwPerEur, WineImportStats $stats, ?int $limit = null, ?int $year = null): \Generator
    {
        if ($krwPerEur <= 0.0) {
            throw new \InvalidArgumentException('Le taux KRW par EUR doit être strictement positif.');
        }
        $handle = @fopen($path, 'rb');
        if (false === $handle) {
            throw new \RuntimeException(\sprintf('Jeu de données illisible : %s', $path));
        }

        try {
            $header = fgetcsv($handle, null, ',', '"', '');
            if (!\is_array($header)) {
                throw new \UnexpectedValueException('En-tête CSV manquant.');
            }
            $header = array_map(static fn (?string $h): string => ltrim(trim((string) $h), "\u{FEFF}"), $header);
            foreach (['id', 'name', 'producer', 'nation', 'type', 'price'] as $required) {
                if (!\in_array($required, $header, true)) {
                    throw new \UnexpectedValueException(\sprintf('Colonne "%s" absente du CSV.', $required));
                }
            }

            $year ??= (int) date('Y');
            $seen = [];
            $emitted = 0;
            while (false !== ($cells = fgetcsv($handle, null, ',', '"', ''))) {
                if ([null] === $cells || \count($cells) !== \count($header)) {
                    continue;
                }
                ++$stats->read;
                /** @var array<string, string> $record */
                $record = array_combine($header, array_map(static fn (?string $c): string => (string) $c, $cells));

                $row = $this->map($record, $krwPerEur, $year, $stats);
                if (null === $row) {
                    continue;
                }
                $key = self::fold($row['name'].'|'.$row['brand'].'|'.($row['attributes']['volume_cl'] ?? ''));
                if (isset($seen[$key])) {
                    ++$stats->skippedDuplicate;
                    continue;
                }
                $seen[$key] = true;
                ++$stats->imported;
                yield $row;

                if (null !== $limit && ++$emitted >= $limit) {
                    return;
                }
            }
        } finally {
            fclose($handle);
        }
    }

    /**
     * @param array<string, string> $r
     *
     * @return array<string, mixed>|null
     */
    private function map(array $r, float $krwPerEur, int $currentYear, WineImportStats $stats): ?array
    {
        $id = self::clean($r['id']);
        $name = self::clean($r['name']);
        $country = self::COUNTRIES[self::clean($r['nation'])] ?? null;
        // Lignes décalées du CSV (la nation contient un cépage) ou sans nom : inexploitables.
        if (!ctype_digit($id) || '' === $name || (null === $country && 'Others' !== self::clean($r['nation']))) {
            ++$stats->skippedMalformed;

            return null;
        }

        $type = self::TYPES[self::clean($r['type'])] ?? null;
        if (null === $type) {
            ++$stats->skippedNotWine;

            return null;
        }

        $krw = ctype_digit(trim($r['price'])) ? (int) trim($r['price']) : 0;
        $priceCents = (int) round($krw / $krwPerEur * 100);
        if ($priceCents <= 0) {
            ++$stats->skippedNoPrice;

            return null;
        }

        $grapes = $this->grapes($r);
        $sweetness = self::scale($r['sweet'], 'SWEET');
        $body = self::scale($r['body'], 'BODY');
        $acidity = self::scale($r['acidity'], 'ACIDITY');
        $tannin = self::scale($r['tannin'], 'TANNIN');
        // Un blanc très sucré est un vin liquoreux : le type sert au filtrage et aux règles d'accord.
        $derived = ['food_pairing', 'tasting_notes'];
        if ('blanc' === $type && null !== $sweetness && $sweetness >= 4) {
            $type = 'liquoreux';
            $derived[] = 'type (blanc très sucré classé liquoreux)';
        }

        $local = array_values(array_filter(
            array_map(fn (string $k): string => self::clean($r[$k] ?? ''), ['local1', 'local2', 'local3', 'local4']),
            static fn (string $v): bool => '' !== $v,
        ));
        $region = $local[0] ?? ($country ?? 'Autre');
        $grapeKeys = array_map(self::fold(...), $grapes);
        $appellation = null;
        foreach (array_reverse(\array_slice($local, 1)) as $candidate) {
            if (!\in_array(self::fold($candidate), $grapeKeys, true)) {
                $appellation = $candidate;
                break;
            }
        }

        $producer = self::clean($r['producer']);
        $producer = '' === $producer ? 'Producteur inconnu' : $producer;
        $vintageRaw = ctype_digit(trim($r['year'] ?? '')) ? (int) trim($r['year']) : 0;
        $vintage = $vintageRaw >= 1900 && $vintageRaw <= $currentYear ? $vintageRaw : null;
        $ml = ctype_digit(trim($r['ml'] ?? '')) && (int) trim($r['ml']) > 0 ? (int) trim($r['ml']) : 750;

        $displayName = $name;
        if (null !== $vintage && !str_contains($name, (string) $vintage)) {
            $displayName .= ' '.$vintage;
        }
        $displayName = mb_substr($displayName, 0, 160);

        $notes = WineProfile::tastingNotes($type, $body, $tannin, $acidity, $sweetness);
        $pairings = WineProfile::foodPairing($type, $body, $tannin, $acidity, $sweetness);
        $uses = $this->uses($r['use'] ?? '');
        if (\in_array('dessert', $uses, true) && null !== $sweetness && $sweetness >= 3 && !\in_array('dessert', $pairings, true)) {
            $pairings[] = 'dessert';
        }

        $attributes = array_filter([
            'type' => $type,
            'region' => $region,
            'appellation' => $appellation,
            'country' => $country,
            'producer' => $producer,
            'cepages' => $grapes,
            'vintage' => $vintage,
            'volume_cl' => (int) round($ml / 10),
            'alcohol_degree' => self::average($r['abv'] ?? ''),
            'serving_temp_c' => self::range($r['degree'] ?? ''),
            'sweetness' => $sweetness,
            'acidity' => $acidity,
            'body' => $body,
            'tannin' => 'rouge' === $type ? $tannin : null,
            'use' => $uses,
            'food_pairing' => $pairings,
            'tasting_notes' => $notes,
            'source' => [
                'dataset' => self::DATASET,
                'id' => (int) $id,
                'price_krw' => $krw,
                'derived' => $derived,
            ],
        ], static fn (mixed $v): bool => null !== $v && [] !== $v && '' !== $v);

        return [
            'sku' => 'WINE-'.$id,
            'name' => $displayName,
            'brand' => mb_substr($producer, 0, 64),
            'category' => 'wine',
            'price_cents' => $priceCents,
            'stock' => self::stockFor($id, $priceCents),
            'description' => WineProfile::description($displayName, $type, $region, $country, $producer, $grapes, $vintage, $notes, $pairings),
            'tags' => array_values(array_unique(array_filter([$type, mb_strtolower($region), null !== $country ? mb_strtolower($country) : null, 'vin']))),
            'attributes' => $attributes,
        ];
    }

    /**
     * @param array<string, string> $r
     *
     * @return list<string>
     */
    private function grapes(array $r): array
    {
        $grapes = [];
        for ($i = 1; $i <= 12; ++$i) {
            $grape = self::clean($r['varieties'.$i] ?? '');
            if ('' === $grape || false !== stripos($grape, 'blend')) {
                continue;
            }
            $grapes[] = self::GRAPE_ALIASES[self::fold($grape)] ?? $grape;
        }

        return array_values(array_unique($grapes));
    }

    /**
     * @return list<string>
     */
    private function uses(string $raw): array
    {
        $uses = [];
        foreach (explode(',', $raw) as $part) {
            $use = self::USES[trim($part)] ?? null;
            if (null !== $use) {
                $uses[] = $use;
            }
        }

        return $uses;
    }

    /**
     * Le jeu de données n'a pas de stock : valeur simulée, stable d'un import à l'autre (dérivée de l'id), plus rare
     * pour les vins chers ; environ 4 % de ruptures pour exercer la gestion de stock.
     */
    private static function stockFor(string $id, int $priceCents): int
    {
        $h = crc32('stock:'.$id);
        if (0 === $h % 25) {
            return 0;
        }

        return match (true) {
            $priceCents >= 100_000 => 1 + $h % 3,
            $priceCents >= 30_000 => 2 + $h % 9,
            default => 4 + $h % 37,
        };
    }

    /**
     * « SWEET3 » -> 3 ; valeur absente ou hors de 1..5 -> null.
     */
    private static function scale(string $raw, string $prefix): ?int
    {
        if (1 !== preg_match('/^'.$prefix.'([1-5])$/', trim($raw), $m)) {
            return null;
        }

        return (int) $m[1];
    }

    /**
     * « 13~14 » -> 13,5 ; « 12 » -> 12,0 ; hors de 0..25 % -> null.
     */
    private static function average(string $raw): ?float
    {
        $range = self::range($raw);
        if (null === $range) {
            return null;
        }
        $mean = ($range['min'] + $range['max']) / 2;

        return $mean > 0.0 && $mean <= 25.0 ? $mean : null;
    }

    /**
     * @return array{min: float, max: float}|null
     */
    private static function range(string $raw): ?array
    {
        if (1 !== preg_match('/^\s*(\d+(?:\.\d+)?)\s*(?:~\s*(\d+(?:\.\d+)?))?\s*$/', $raw, $m)) {
            return null;
        }
        $min = (float) $m[1];
        $max = (float) ($m[2] ?? $m[1]);

        return $min <= $max ? ['min' => $min, 'max' => $max] : null;
    }

    /**
     * Retire le coréen des champs bilingues, les caractères invisibles, les tirets orphelins laissés par le
     * retrait du coréen et les espaces superflus.
     */
    public static function clean(string $value): string
    {
        $value = (string) preg_replace('/[\x{1100}-\x{11FF}\x{3130}-\x{318F}\x{AC00}-\x{D7AF}]+/u', '', $value);
        $value = (string) preg_replace('/[\x{200B}-\x{200D}\x{FEFF}]/u', '', $value);
        $value = (string) preg_replace('/\s+/u', ' ', $value);

        return trim((string) preg_replace('/^[\s\-–,.]+/u', '', trim($value)));
    }

    /**
     * Clé de comparaison : minuscules, sans accents ni ponctuation.
     */
    private static function fold(string $text): string
    {
        $text = mb_strtolower(trim($text));
        $text = strtr($text, ['é' => 'e', 'è' => 'e', 'ê' => 'e', 'à' => 'a', 'â' => 'a', 'î' => 'i', 'ô' => 'o', 'û' => 'u', 'ç' => 'c']);

        return trim((string) preg_replace('/[^a-z0-9|]+/', ' ', $text));
    }
}
