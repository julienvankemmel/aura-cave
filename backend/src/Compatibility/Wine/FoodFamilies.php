<?php

declare(strict_types=1);

namespace App\Compatibility\Wine;

/**
 * Familles gustatives pour l'accord mets-vins : « mouton » et « entrecôte au barbecue » appartiennent à la même
 * famille que « agneau » ou « viande rouge », donc un vin taillé pour l'un convient à l'autre. Deux familles voisines
 * (viande rouge et gibier, poisson et crustacés…) donnent un accord partiel. Aucun appel externe : dictionnaire pur.
 */
final class FoodFamilies
{
    /** Score minimal pour considérer l'accord comme validé. */
    public const MATCH_THRESHOLD = 0.7;

    /** @var array<string, list<string>> famille => expressions (avant normalisation) */
    private const FAMILIES = [
        'viande_rouge' => ['viande rouge', 'boeuf', 'bœuf', 'entrecôte', 'côte de boeuf', 'côte de bœuf', 'steak', 'bavette', 'onglet', 'rumsteck', 'faux-filet', 'filet de boeuf', 'rosbif', 'rôti de boeuf', 'chateaubriand', 'tournedos', 'hamburger',
            'agneau', 'mouton', 'gigot', 'côtes d\'agneau', 'côtelette', 'carré d\'agneau', 'épaule d\'agneau', 'selle d\'agneau', 'souris d\'agneau', 'méchoui', 'merguez', 'grillade', 'barbecue', 'bbq'],
        'gibier' => ['gibier', 'chevreuil', 'cerf', 'sanglier', 'lièvre', 'venaison', 'faisan', 'perdreau', 'biche', 'civet'],
        'mijote' => ['plat mijoté', 'daube', 'bourguignon', 'pot-au-feu', 'ragoût', 'cassoulet', 'osso buco', 'osso bucco', 'navarin', 'viande en sauce'],
        'canard' => ['canard', 'magret', 'confit de canard', 'confit'],
        'viande_blanche' => ['volaille', 'poulet', 'dinde', 'pintade', 'veau', 'porc', 'lapin', 'chapon', 'escalope', 'blanquette', 'côte de porc', 'rôti de porc', 'rôti de veau'],
        'charcuterie' => ['charcuterie', 'jambon', 'saucisson', 'terrine', 'pâté', 'rillettes', 'coppa', 'bresaola', 'saucisse'],
        'poisson' => ['poisson', 'saumon', 'sole', 'turbot', 'cabillaud', 'bar', 'loup de mer', 'daurade', 'thon', 'truite', 'lotte', 'sushi', 'sashimi', 'maquereau', 'sardine', 'morue'],
        'fruits_de_mer' => ['fruits de mer', 'huître', 'crustacé', 'homard', 'langoustine', 'crevette', 'gambas', 'coquillage', 'saint-jacques', 'coquille saint-jacques', 'moule', 'crabe', 'oursin', 'tourteau'],
        'fromage_affine' => ['fromage affiné', 'fromages affinés', 'comté', 'parmesan', 'cantal', 'beaufort', 'gruyère', 'manchego', 'pecorino', 'mimolette'],
        'fromage_chevre' => ['fromage de chèvre', 'chèvre', 'crottin', 'picodon'],
        'fromage_bleu' => ['roquefort', 'fromage bleu', 'fromages bleus', 'bleu', 'fourme d\'ambert', 'gorgonzola', 'stilton'],
        'fromage' => ['fromage', 'plateau de fromages', 'camembert', 'brie', 'munster', 'reblochon'],
        'foie_gras' => ['foie gras'],
        'dessert' => ['dessert', 'chocolat', 'tarte', 'gâteau', 'crème', 'fondant', 'mousse', 'sorbet', 'glace', 'fruit rouge', 'salade de fruits', 'panna cotta', 'crumble', 'pâtisserie', 'brownie', 'noix'],
        'pates' => ['pâtes', 'pizza', 'risotto', 'lasagne', 'spaghetti', 'carbonara', 'bolognaise', 'gnocchi', 'ravioli'],
        'leger' => ['salade', 'légume', 'crudité', 'apéritif', 'tapas', 'quiche', 'omelette', 'cuisine méditerranéenne'],
        'epice' => ['curry', 'épicé', 'asiatique', 'thaï', 'indien', 'couscous', 'tajine'],
    ];

    /** Mots grammaticaux ignorés pour les mots en commun. */
    private const STOPWORDS = ['les', 'des', 'aux', 'une', 'avec', 'pour', 'sans', 'sur', 'ses', 'son', 'the'];

    /** Proximité entre familles distinctes (symétrique). */
    private const NEIGHBOURS = [
        'viande_rouge|gibier' => 0.8,
        'viande_rouge|mijote' => 0.9,
        'viande_rouge|canard' => 0.7,
        'gibier|mijote' => 0.7,
        'gibier|canard' => 0.7,
        'canard|viande_blanche' => 0.7,
        'poisson|fruits_de_mer' => 0.8,
        'fromage|fromage_affine' => 0.9,
        'fromage|fromage_chevre' => 0.9,
        'fromage|fromage_bleu' => 0.9,
        'fromage_affine|fromage_bleu' => 0.5,
        'dessert|fromage_bleu' => 0.5,
        'charcuterie|viande_blanche' => 0.5,
    ];

    /** @var array<string, list<list<string>>>|null */
    private static ?array $compiled = null;

    /**
     * Mots normalisés d'un texte : minuscules, sans accents, singuliers (« côtes d'agneau » -> côte, d, agneau).
     *
     * @return list<string>
     */
    public static function words(string $text): array
    {
        $normalized = AppellationRules::normalize($text);
        // « pâtes » (pluriel) et « pâté » deviendraient le même mot une fois singularisés.
        $normalized = (string) preg_replace('/\bpates\b/', 'pasta', $normalized);
        $words = [];
        foreach (explode(' ', $normalized) as $word) {
            if ('' === $word) {
                continue;
            }
            $words[] = \strlen($word) > 4 && (str_ends_with($word, 's') || str_ends_with($word, 'x')) ? substr($word, 0, -1) : $word;
        }

        return $words;
    }

    /**
     * Familles dont au moins une expression figure dans le texte (tolère une faute de frappe sur les mots de 5 lettres et plus).
     *
     * @return list<string>
     */
    public static function families(string $text): array
    {
        $words = self::words($text);
        if ([] === $words) {
            return [];
        }
        $found = [];
        foreach (self::compiled() as $family => $phrases) {
            foreach ($phrases as $phrase) {
                if (self::contains($words, $phrase)) {
                    $found[] = $family;
                    break;
                }
            }
        }

        // « fromages affinés » est plus précis que « fromage » : la famille générique ne s'ajoute pas au cas précis.
        if ([] !== array_intersect($found, ['fromage_affine', 'fromage_chevre', 'fromage_bleu'])) {
            $found = array_values(array_diff($found, ['fromage']));
        }

        return $found;
    }

    /**
     * Score d'accord entre un plat et les accords d'un vin : 1,0 (même famille ou mot en commun), 0,5–0,9 (familles
     * voisines), 0,0 (aucun rapport).
     *
     * @param list<string> $pairings
     */
    public static function score(string $dish, array $pairings): float
    {
        $dishWords = self::words($dish);
        $dishFamilies = self::families($dish);
        $best = 0.0;

        foreach ($pairings as $pairing) {
            $pairingWords = self::words($pairing);
            foreach ($dishWords as $a) {
                foreach ($pairingWords as $b) {
                    if (\strlen($a) >= 3 && \strlen($b) >= 3 && !\in_array($a, self::STOPWORDS, true) && !\in_array($b, self::STOPWORDS, true) && ($a === $b || (\strlen($a) >= 5 && \strlen($b) >= 5 && levenshtein($a, $b) <= 1))) {
                        $best = 1.0;
                    }
                }
            }
            foreach (self::families($pairing) as $wineFamily) {
                foreach ($dishFamilies as $dishFamily) {
                    $best = max($best, self::similarity($dishFamily, $wineFamily));
                }
            }
            if ($best >= 1.0) {
                return 1.0;
            }
        }

        return $best;
    }

    private static function similarity(string $a, string $b): float
    {
        if ($a === $b) {
            return 1.0;
        }

        return self::NEIGHBOURS[$a.'|'.$b] ?? self::NEIGHBOURS[$b.'|'.$a] ?? 0.0;
    }

    /**
     * @param list<string> $words
     * @param list<string> $phrase
     */
    private static function contains(array $words, array $phrase): bool
    {
        $n = \count($phrase);
        if (1 === $n) {
            $needle = $phrase[0];
            foreach ($words as $word) {
                if ($word === $needle || (\strlen($needle) >= 5 && \strlen($word) >= 5 && levenshtein($word, $needle) <= 1)) {
                    return true;
                }
            }

            return false;
        }
        for ($i = 0, $max = \count($words) - $n; $i <= $max; ++$i) {
            if (\array_slice($words, $i, $n) === $phrase) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<string, list<list<string>>>
     */
    private static function compiled(): array
    {
        if (null === self::$compiled) {
            self::$compiled = [];
            foreach (self::FAMILIES as $family => $phrases) {
                self::$compiled[$family] = array_values(array_unique(array_map(static fn (string $p): array => self::words($p), $phrases), SORT_REGULAR));
            }
        }

        return self::$compiled;
    }
}
