<?php

declare(strict_types=1);

namespace App\Catalog\Wine;

/**
 * Profil sensoriel et accords mets-vins déduits des indices structurés du jeu de données (corps, tanins, acidité,
 * sucre, usage). Le jeu de données ne contient ni notes de dégustation ni accords rédigés : ce sont ici des
 * heuristiques de sommelier, déterministes et signalées comme dérivées dans `attributes.source.derived`.
 */
final class WineProfile
{
    private const BODY = [1 => 'très léger', 2 => 'léger', 3 => 'de corps moyen', 4 => 'corsé', 5 => 'puissant'];
    private const TANNIN = [1 => 'tanins très souples', 2 => 'tanins souples', 3 => 'tanins présents', 4 => 'tanins fermes', 5 => 'tanins puissants'];
    private const ACIDITY = [1 => 'acidité faible', 2 => 'acidité douce', 3 => 'acidité équilibrée', 4 => 'acidité vive', 5 => 'acidité très vive'];
    private const SWEETNESS = [1 => 'sec', 2 => 'demi-sec', 3 => 'moelleux', 4 => 'doux', 5 => 'très doux'];

    /**
     * Texte court de profil ; les composantes absentes du jeu de données sont omises.
     */
    public static function tastingNotes(string $type, ?int $body, ?int $tannin, ?int $acidity, ?int $sweetness): string
    {
        $parts = [];
        if (null !== $body) {
            $parts[] = self::BODY[$body] ?? null;
        }
        if (null !== $tannin && 'rouge' === $type) {
            $parts[] = self::TANNIN[$tannin] ?? null;
        }
        if (null !== $acidity) {
            $parts[] = self::ACIDITY[$acidity] ?? null;
        }
        if (null !== $sweetness) {
            $parts[] = self::SWEETNESS[$sweetness] ?? null;
        }
        $parts = array_values(array_filter($parts));

        return [] === $parts ? '' : ucfirst(implode(', ', $parts)).'.';
    }

    /**
     * @return list<string> accords classiques, mots simples au singulier ou au pluriel naturel
     */
    public static function foodPairing(string $type, ?int $body, ?int $tannin, ?int $acidity, ?int $sweetness): array
    {
        $body ??= 3;
        $tannin ??= 2;
        $acidity ??= 3;
        $sweetness ??= 1;

        $pairings = match ($type) {
            'rouge' => match (true) {
                $body >= 4 => ['boeuf', 'côte de boeuf', 'steak', 'viande rouge', 'agneau', 'gibier', 'fromages affinés'],
                3 === $body && $tannin >= 3 => ['boeuf', 'viande rouge', 'canard', 'porc', 'fromages'],
                3 === $body => ['volaille', 'canard', 'porc', 'charcuterie', 'pâtes', 'pizza', 'fromages'],
                default => ['charcuterie', 'volaille', 'grillades', 'plat mijoté', 'pizza'],
            },
            'blanc' => match (true) {
                $body >= 4 => ['volaille', 'poulet', 'poisson', 'saumon', 'homard', 'fromages', 'risotto'],
                $acidity >= 4 => ['poisson', 'fruits de mer', 'huîtres', 'sushi', 'crustacés', 'fromage de chèvre', 'salade'],
                default => ['poisson', 'volaille', 'fromages', 'apéritif', 'salade'],
            },
            'rosé' => ['grillades', 'salade', 'charcuterie', 'poisson', 'cuisine méditerranéenne', 'sushi'],
            'effervescent' => $sweetness >= 3
                ? ['dessert', 'gâteau', 'fruits', 'apéritif']
                : ['apéritif', 'huîtres', 'fruits de mer', 'poisson', 'sushi', 'fromages'],
            'liquoreux' => ['foie gras', 'roquefort', 'fromages bleus', 'dessert', 'tarte', 'fruits'],
            'fortifié' => ['dessert', 'chocolat', 'fromages bleus', 'noix', 'apéritif'],
            default => [],
        };

        return $pairings;
    }

    /**
     * @param list<string> $grapes
     * @param list<string> $pairings
     */
    public static function description(
        string $name,
        string $type,
        string $region,
        ?string $country,
        string $producer,
        array $grapes,
        ?int $vintage,
        string $tastingNotes,
        array $pairings,
    ): string {
        $origin = $region;
        if (null !== $country && $country !== $region) {
            $origin .= ', '.$country;
        }
        $sentences = [\sprintf('%s : vin %s de %s, produit par %s.', $name, $type, $origin, $producer)];
        if ([] !== $grapes) {
            $sentences[] = \sprintf('Cépages : %s.', implode(', ', $grapes));
        }
        if (null !== $vintage) {
            $sentences[] = \sprintf('Millésime %d.', $vintage);
        }
        if ('' !== $tastingNotes) {
            $sentences[] = 'Profil : '.lcfirst($tastingNotes);
        }
        if ([] !== $pairings) {
            $sentences[] = \sprintf('Accords : %s.', implode(', ', \array_slice($pairings, 0, 5)));
        }

        return implode(' ', $sentences);
    }
}
