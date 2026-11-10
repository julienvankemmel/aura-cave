<?php

declare(strict_types=1);

namespace App\Compatibility\Wine;

/**
 * Matrice des appellations classiques (Bordeaux, Bourgogne, Rhône, Languedoc) : famille de région et cépages
 * autorisés (principaux et accessoires, blancs et rouges confondus). Les noms sont comparés après normalisation :
 * « Nuits-St-Georges » et « Nuits Saint Georges » désignent la même appellation, « Saint-Emilion Grand Cru » se
 * rattache à « Saint-Emilion ».
 *
 * Une appellation absente de la matrice n'est jamais contrôlée : mieux vaut ne rien dire que rejeter à tort.
 */
final class AppellationRules
{
    public const BORDEAUX = 'bordeaux';
    public const BOURGOGNE = 'bourgogne';
    public const RHONE = 'rhone';
    public const LANGUEDOC = 'languedoc';

    /** Valeurs de `region` du jeu de données rattachées à une famille (le reste est inconnu : pas de contrôle). */
    private const REGION_FAMILIES = [
        'bordeaux' => self::BORDEAUX,
        'bourgogne' => self::BOURGOGNE,
        'burgundy' => self::BOURGOGNE,
        'rhone' => self::RHONE,
        'rhone valley' => self::RHONE,
        'vallee du rhone' => self::RHONE,
        'southern france' => self::LANGUEDOC,
        'languedoc' => self::LANGUEDOC,
        'languedoc roussillon' => self::LANGUEDOC,
        'roussillon' => self::LANGUEDOC,
        'provence' => self::LANGUEDOC,
    ];

    /** Variantes et fautes du jeu de données ramenées au cépage de référence. */
    private const GRAPE_SYNONYMS = [
        'syrah shiraz' => 'syrah', 'shiraz' => 'syrah', 'syrah' => 'syrah',
        'grenache noir' => 'grenache', 'grenache blanc' => 'grenache', 'grenache gris' => 'grenache',
        'garnacha' => 'grenache', 'garnacha tinta' => 'grenache',
        'mourvedre' => 'mourvedre', 'monastrell' => 'mourvedre', 'mataro' => 'mourvedre',
        'rousaanne' => 'roussanne', 'roussanne' => 'roussanne',
        'cinsaut' => 'cinsault',
        'clairette blanche' => 'clairette', 'clairette rose' => 'clairette',
        'picpoul blanc' => 'picpoul', 'picpoul noir' => 'picpoul', 'piquepoul' => 'picpoul',
        'terret noir' => 'terret', 'terret blanc' => 'terret', 'terret gris' => 'terret',
        'rolle' => 'vermentino',
        'cot' => 'malbec',
        'sauvignon' => 'sauvignon blanc', 'sauvignon gris' => 'sauvignon blanc',
        'trebbiano' => 'ugni blanc',
        // Le jeu de données écrit « Muscadet » pour la muscadelle des liquoreux bordelais.
        'muscadet' => 'muscadelle',
        'carignane' => 'carignan',
    ];

    /** Valeurs de remplissage du jeu de données : ce ne sont pas des cépages. */
    private const NOISE = ['etc', 'other', 'others', 'blend', 'autre', 'autres'];

    private const BORDEAUX_GRAPES = ['cabernet sauvignon', 'merlot', 'cabernet franc', 'petit verdot', 'malbec', 'carmenere', 'sauvignon blanc', 'semillon', 'muscadelle', 'ugni blanc', 'colombard'];
    private const BURGUNDY_COMMUNAL = ['pinot noir', 'chardonnay', 'pinot blanc', 'pinot gris', 'aligote'];
    private const BURGUNDY_REGIONAL = ['pinot noir', 'chardonnay', 'pinot blanc', 'pinot gris', 'aligote', 'gamay', 'melon de bourgogne'];
    private const SOUTHERN_RHONE = ['grenache', 'syrah', 'mourvedre', 'cinsault', 'carignan', 'counoise', 'muscardin', 'vaccarese', 'terret', 'camarese', 'picpoul', 'clairette', 'bourboulenc', 'roussanne', 'marsanne', 'viognier', 'picardan', 'ugni blanc', 'vermentino'];
    private const LANGUEDOC_GRAPES = ['grenache', 'syrah', 'mourvedre', 'carignan', 'cinsault', 'terret', 'picpoul', 'bourboulenc', 'clairette', 'marsanne', 'roussanne', 'viognier', 'vermentino', 'macabeu', 'muscat', 'ugni blanc', 'mauzac', 'chenin blanc', 'chardonnay', 'sauvignon blanc', 'cabernet sauvignon', 'cabernet franc', 'merlot', 'pinot noir', 'malbec', 'tannat', 'marselan', 'petit manseng', 'rolle', 'colombard', 'semillon', 'tibouren', 'braquet', 'folle noire', 'alicante'];

    /**
     * @var array<string, array{regions: list<string>, grapes: list<string>|null, strict: bool}>|null
     */
    private static ?array $matrix = null;

    /**
     * Minuscules sans accents ni ponctuation, « st » développé en « saint » (« Nuits-St-Georges » -> « nuits saint georges »).
     */
    public static function normalize(string $text): string
    {
        $text = mb_strtolower(trim($text));
        $text = strtr($text, ['é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e', 'à' => 'a', 'â' => 'a', 'ä' => 'a', 'î' => 'i', 'ï' => 'i', 'ô' => 'o', 'ö' => 'o', 'ù' => 'u', 'û' => 'u', 'ü' => 'u', 'ç' => 'c', 'œ' => 'oe', '’' => ' ']);
        $text = trim((string) preg_replace('/[^a-z0-9]+/', ' ', $text));

        return (string) preg_replace('/\bst\b/', 'saint', $text);
    }

    /**
     * Cépage de référence, ou null pour une valeur de remplissage (« Etc », « Blend »).
     */
    public static function grapeKey(string $grape): ?string
    {
        $key = self::normalize($grape);
        if ('' === $key || \in_array($key, self::NOISE, true) || str_contains($key, 'blend')) {
            return null;
        }

        return self::GRAPE_SYNONYMS[$key] ?? $key;
    }

    /**
     * Famille de région d'un vin (`region` du jeu de données), null si elle n'est pas couverte par la matrice.
     */
    public static function regionFamily(string $region): ?string
    {
        return self::REGION_FAMILIES[self::normalize($region)] ?? null;
    }

    /**
     * Règle de l'appellation (correspondance exacte, sinon plus long préfixe : « Chablis Premier Cru » -> chablis).
     *
     * @return array{regions: list<string>, grapes: list<string>|null, strict: bool}|null
     */
    public static function find(string $appellation): ?array
    {
        $key = self::normalize($appellation);
        if ('' === $key) {
            return null;
        }
        $matrix = self::matrix();
        if (isset($matrix[$key])) {
            return $matrix[$key];
        }
        $best = null;
        foreach ($matrix as $name => $rule) {
            if (str_starts_with($key.' ', $name.' ') && (null === $best || \strlen($name) > \strlen($best))) {
                $best = $name;
            }
        }

        return null === $best ? null : $matrix[$best];
    }

    /**
     * @return array<string, array{regions: list<string>, grapes: list<string>|null, strict: bool}>
     */
    private static function matrix(): array
    {
        if (null !== self::$matrix) {
            return self::$matrix;
        }

        /** @var array<string, array{regions: list<string>, grapes: list<string>|null, strict: bool}> $rules */
        $rules = [];
        /**
         * @param list<string>        $names
         * @param string|list<string> $regions
         * @param list<string>|null   $grapes
         */
        $add = static function (array $names, string|array $regions, ?array $grapes, bool $strict = true) use (&$rules): void {
            foreach ($names as $name) {
                $rules[self::normalize($name)] = [
                    'regions' => array_values(array_map('strval', \is_array($regions) ? $regions : [$regions])),
                    'grapes' => null === $grapes ? null : array_values(array_unique(array_map(static fn (string $g): string => self::grapeKey($g) ?? $g, $grapes))),
                    'strict' => $strict,
                ];
            }
        };

        // Bordeaux : tous les cépages bordelais ; les liquoreux sont plus restreints.
        $add(['bordeaux', 'bordeaux superieur', 'medoc', 'haut medoc', 'pauillac', 'margaux', 'saint julien', 'saint estephe', 'listrac medoc', 'moulis', 'moulis en medoc',
            'saint emilion', 'saint emilion grand cru', 'lussac saint emilion', 'montagne saint emilion', 'puisseguin saint emilion', 'saint georges saint emilion',
            'pomerol', 'lalande de pomerol', 'fronsac', 'canon fronsac', 'graves', 'pessac leognan', 'cotes de bordeaux', 'premieres cotes de bordeaux',
            'castillon cotes de bordeaux', 'francs cotes de bordeaux', 'blaye cotes de bordeaux', 'cotes de bourg', 'entre deux mers', 'graves de vayres'], self::BORDEAUX, self::BORDEAUX_GRAPES);
        $add(['sauternes', 'barsac', 'cerons', 'sainte croix du mont', 'loupiac', 'cadillac'], self::BORDEAUX, ['semillon', 'sauvignon blanc', 'muscadelle']);

        // Bourgogne : appellations communales (pinot noir / chardonnay), puis régionales et Beaujolais.
        $add(['gevrey chambertin', 'chambertin', 'chambertin clos de beze', 'charmes chambertin', 'mazis chambertin', 'vosne romanee', 'romanee conti', 'la tache', 'richebourg',
            'echezeaux', 'grands echezeaux', 'volnay', 'pommard'], self::BOURGOGNE, ['pinot noir', 'pinot blanc', 'pinot gris']);
        $add(['chambolle musigny', 'musigny', 'morey saint denis', 'clos de tart', 'clos saint denis', 'clos de la roche', 'vougeot', 'clos de vougeot', 'fixin', 'marsannay',
            'nuits saint georges', 'aloxe corton', 'corton', 'beaune', 'savigny les beaune', 'chorey les beaune', 'ladoix', 'pernand vergelesses', 'santenay', 'monthelie', 'auxey duresses',
            'saint romain', 'saint aubin', 'maranges', 'mercurey', 'givry', 'rully', 'montagny', 'chassagne montrachet', 'cote de nuits villages', 'cote de beaune villages',
            'bourgogne hautes cotes de nuits', 'bourgogne hautes cotes de beaune', 'hautes cotes de nuits', 'hautes cotes de beaune'], self::BOURGOGNE, self::BURGUNDY_COMMUNAL);
        $add(['meursault', 'puligny montrachet', 'montrachet', 'batard montrachet', 'corton charlemagne', 'bienvenues batard montrachet', 'criots batard montrachet'], self::BOURGOGNE, ['chardonnay', 'pinot noir', 'aligote', 'pinot blanc']);
        $add(['chablis', 'petit chablis', 'pouilly fuisse', 'pouilly loche', 'pouilly vinzelles', 'saint veran'], self::BOURGOGNE, ['chardonnay']);
        $add(['cote de nuits', 'cote de beaune', 'bourgogne', 'macon', 'macon villages', 'bourgogne aligote', 'bourgogne passetoutgrain', 'cremant de bourgogne', 'coteaux bourguignons'], self::BOURGOGNE, self::BURGUNDY_REGIONAL);
        $add(['beaujolais', 'beaujolais villages', 'brouilly', 'cote de brouilly', 'morgon', 'fleurie', 'moulin a vent', 'julienas', 'chenas', 'chiroubles', 'regnie', 'saint amour'], self::BOURGOGNE, ['gamay', 'chardonnay', 'aligote', 'pinot noir']);

        // Vallée du Rhône septentrionale : la syrah est le cépage des rouges (Saint-Joseph, Hermitage, Cornas, Côte-Rôtie) ;
        // les blancs d'Hermitage, Saint-Joseph et Crozes-Hermitage sont des marsanne et roussanne.
        $add(['saint joseph', 'hermitage', 'crozes hermitage'], self::RHONE, ['syrah', 'marsanne', 'roussanne']);
        $add(['cornas'], self::RHONE, ['syrah']);
        $add(['cote rotie'], self::RHONE, ['syrah', 'viognier']);
        $add(['condrieu', 'chateau grillet'], self::RHONE, ['viognier']);
        $add(['saint peray'], self::RHONE, ['marsanne', 'roussanne']);
        // Rhône méridional.
        $add(['chateauneuf du pape'], self::RHONE, ['grenache', 'syrah', 'mourvedre', 'cinsault', 'counoise', 'muscardin', 'vaccarese', 'terret', 'clairette', 'bourboulenc', 'roussanne', 'picpoul', 'picardan']);
        $add(['gigondas', 'vacqueyras', 'vinsobres', 'rasteau', 'cairanne', 'beaumes de venise', 'lirac', 'tavel', 'cotes du rhone', 'cotes du rhone villages', 'cotes du ventoux', 'grignan les adhemar',
            'costieres de nimes', 'clairette de die', 'cotes du vivarais'], self::RHONE, self::SOUTHERN_RHONE);
        $add(['ventoux', 'luberon', 'cotes du luberon'], [self::RHONE, self::LANGUEDOC], self::SOUTHERN_RHONE);

        // Languedoc et Provence : règlements plus ouverts (cépages internationaux fréquents) : écart signalé, jamais bloquant.
        $add(['coteaux du languedoc', 'languedoc', 'corbieres', 'corbieres boutenac', 'minervois', 'minervois la liviniere', 'faugeres', 'saint chinian', 'fitou', 'pic saint loup', 'la clape', 'picpoul de pinet',
            'terrasses du larzac', 'cabardes', 'malepere', 'limoux', 'blanquette de limoux', 'cotes du roussillon', 'cotes du roussillon villages', 'collioure', 'banyuls', 'maury', 'rivesaltes', 'muscat de rivesaltes',
            'provence', 'cotes de provence', 'coteaux d aix en provence', 'coteaux varois en provence', 'bandol', 'cassis', 'bellet', 'palette'], [self::LANGUEDOC, self::RHONE], self::LANGUEDOC_GRAPES, false);
        $add(['languedoc roussillon'], self::LANGUEDOC, null, false);

        return self::$matrix = $rules;
    }
}
