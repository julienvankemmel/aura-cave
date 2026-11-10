<?php

declare(strict_types=1);

namespace App\Compatibility;

use App\Compatibility\Wine\AppellationRules;
use App\Compatibility\Wine\FoodFamilies;
use App\Entity\Product;
use App\Enum\IssueSeverity;

/**
 * Vérificateur de compatibilité vin : cohérence des appellations (région, cépages), du millésime et de la fenêtre de
 * dégustation, accord avec un plat, budget. 100 % déterministe, sans appel externe.
 */
final class WineCompatibilityChecker
{
    /** Mots-clés au singulier : les mots du plat sont singularisés avant comparaison. */
    private const FISH_WORDS = ['poisson', 'huitre', 'saumon', 'sole', 'turbot', 'homard', 'crustace', 'sushi', 'sashimi', 'coquillage', 'langoustine', 'saint-jacque', 'cabillaud', 'bar', 'mer'];
    private const DESSERT_WORDS = ['dessert', 'chocolat', 'tarte', 'gateau', 'creme', 'fondant', 'mousse', 'sorbet'];
    private const STOPWORDS = ['de', 'du', 'des', 'la', 'le', 'les', 'et', 'aux', 'au', 'en', 'un', 'une', 'avec', 'sur', 'sans', 'pour'];

    /**
     * @param list<Product> $items produits de la sélection
     */
    public function verify(array $items, ?VerificationContext $context = null): CompatibilityResultDTO
    {
        $wines = array_values(array_filter($items, static fn (Product $p): bool => 'wine' === $p->category));
        $year = $context->servingYear ?? (int) date('Y');
        $issues = [];

        if ([] === $wines && true === $context?->strict) {
            $issues[] = $this->issue(IssueSeverity::Error, 'EMPTY_SELECTION', 'Aucun vin dans la sélection.');
        }

        foreach ($wines as $wine) {
            array_push($issues, ...$this->checkAppellation($wine), ...$this->checkVintage($wine, $year));
        }

        $details = ['serving_year' => $year];
        $dish = null !== $context?->dish && '' !== trim($context->dish) ? trim($context->dish) : null;
        if (null !== $dish) {
            [$pairingIssues, $details['pairing']] = $this->checkPairing($wines, $dish);
            array_push($issues, ...$pairingIssues);
        }

        $total = array_sum(array_map(static fn (Product $p): int => $p->priceCents, $items));
        $budget = $context?->budgetCents;
        if (null !== $budget && $total > $budget) {
            $issues[] = $this->issue(IssueSeverity::Error, 'BUDGET_EXCEEDED', \sprintf('Total %.2f € supérieur au budget de %.2f €.', $total / 100, $budget / 100));
        }

        return new CompatibilityResultDTO($issues, $total, $budget, $details);
    }

    /**
     * Région et cépages contrôlés d'après la matrice des appellations classiques (Bordeaux, Bourgogne, Rhône,
     * Languedoc). Une appellation hors matrice ou un cépage de remplissage n'est jamais reproché au vin. Les écarts
     * (région, cépage) sont de simples avertissements informatifs, jamais bloquants : ils n'excluent aucune bouteille
     * d'une sélection (le jugement gustatif revient au sommelier, les invariants stock/budget au filtre SQL).
     *
     * @return list<CompatibilityIssue>
     */
    private function checkAppellation(Product $wine): array
    {
        $appellation = (string) $wine->attribute('appellation');
        $rule = AppellationRules::find($appellation);
        if (null === $rule) {
            return [];
        }

        $issues = [];
        $family = AppellationRules::regionFamily((string) $wine->attribute('region'));
        if (null !== $family && !\in_array($family, $rule['regions'], true)) {
            $issues[] = $this->issue(IssueSeverity::Warning, 'APPELLATION_REGION_MISMATCH', \sprintf('%s : l\'appellation %s n\'appartient pas à la région %s.', $wine->name, $appellation, (string) $wine->attribute('region')));
        }

        $grapes = $wine->attribute('cepages');
        if (null !== $rule['grapes'] && \is_array($grapes)) {
            $unexpected = [];
            foreach ($grapes as $grape) {
                $key = AppellationRules::grapeKey((string) $grape);
                if (null !== $key && !\in_array($key, $rule['grapes'], true)) {
                    $unexpected[] = (string) $grape;
                }
            }
            if ([] !== $unexpected) {
                $issues[] = $this->issue(IssueSeverity::Warning, 'CEPAGE_APPELLATION_MISMATCH', \sprintf('%s : cépage(s) %s inhabituel(s) en %s.', $wine->name, implode(', ', $unexpected), $appellation));
            }
        }

        return $issues;
    }

    /**
     * @return list<CompatibilityIssue>
     */
    private function checkVintage(Product $wine, int $year): array
    {
        $vintage = $wine->attribute('vintage');
        if (!\is_int($vintage)) {
            return [];
        }
        if ($vintage > $year) {
            return [$this->issue(IssueSeverity::Error, 'VINTAGE_IN_FUTURE', \sprintf('%s : le millésime %d est postérieur à l\'année de service %d.', $wine->name, $vintage, $year))];
        }

        $from = $wine->attribute('drinking_window.from');
        $until = $wine->attribute('drinking_window.until');
        if (\is_int($from) && $year < $from) {
            return [$this->issue(IssueSeverity::Warning, 'TOO_YOUNG', \sprintf('%s (%d) est encore jeune : à partir de %d pour être à son meilleur.', $wine->name, $vintage, $from))];
        }
        if (\is_int($until) && $year > $until) {
            return [$this->issue(IssueSeverity::Warning, 'PAST_PEAK', \sprintf('%s (%d) a dépassé son apogée (jusqu\'en %d).', $wine->name, $vintage, $until))];
        }

        return [];
    }

    /**
     * Accord par famille gustatives (« mouton », « entrecôte au barbecue » = « agneau », « viande rouge »…) : un score de
     * 0 à 1 par vin, validé à partir de FoodFamilies::MATCH_THRESHOLD ; un type de vin en contresens reste signalé.
     *
     * @param list<Product> $wines
     *
     * @return array{list<CompatibilityIssue>, array{dish: string, matches: list<string>, mismatches: list<string>, scores: array<string, float>, score: float}}
     */
    private function checkPairing(array $wines, string $dish): array
    {
        $dishTokens = self::tokens($dish);
        $issues = [];
        $matches = $mismatches = [];
        $scores = [];

        foreach ($wines as $wine) {
            $raw = $wine->attribute('food_pairing');
            $pairings = array_values(array_map(static fn (mixed $p): string => (string) $p, \is_array($raw) ? $raw : []));
            $score = round(FoodFamilies::score($dish, $pairings), 2);
            $scores[$wine->sku] = $score;
            $matched = $score >= FoodFamilies::MATCH_THRESHOLD;

            $type = self::normalize((string) $wine->attribute('type'));
            $uses = $wine->attribute('use');
            $forDessert = \in_array($type, ['liquoreux', 'effervescent', 'fortifie'], true)
                || (\is_array($uses) && \in_array('dessert', $uses, true) && (int) $wine->attribute('sweetness') >= 3);
            $clash = match (true) {
                'rouge' === $type && [] !== array_intersect($dishTokens, self::FISH_WORDS) => 'un rouge tannique écrase la délicatesse du poisson et des fruits de mer',
                [] !== array_intersect($dishTokens, self::DESSERT_WORDS) && !$forDessert => 'un dessert demande un vin doux, liquoreux, fortifié ou effervescent',
                default => null,
            };

            if ($matched && null === $clash) {
                $matches[] = $wine->sku;
                $issues[] = $this->issue(IssueSeverity::Info, 'PAIRING_OK', \sprintf('%s s\'accorde avec %s.', $wine->name, $dish));
                continue;
            }
            $mismatches[] = $wine->sku;
            $issues[] = null !== $clash
                ? $this->issue(IssueSeverity::Warning, 'PAIRING_TYPE_CLASH', \sprintf('%s avec %s : %s.', $wine->name, $dish, $clash))
                : $this->issue(IssueSeverity::Warning, 'PAIRING_MISMATCH', \sprintf('%s n\'est pas un accord classique avec %s (accords : %s).', $wine->name, $dish, implode(', ', $pairings) ?: 'non renseignés'));
        }

        $average = [] === $scores ? 0.0 : round(array_sum($scores) / \count($scores), 2);

        return [$issues, ['dish' => $dish, 'matches' => $matches, 'mismatches' => $mismatches, 'scores' => $scores, 'score' => $average]];
    }

    /**
     * Minuscules sans accents ni espaces superflus.
     */
    public static function normalize(string $text): string
    {
        return AppellationRules::normalize($text);
    }

    /**
     * Mots significatifs (≥ 3 lettres, hors mots vides, singulier approximatif).
     *
     * @return list<string>
     */
    private static function tokens(string $text): array
    {
        $words = preg_split('/[^a-z-]+/', self::normalize($text), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $tokens = [];
        foreach ($words as $word) {
            if (\strlen($word) < 3 || \in_array($word, self::STOPWORDS, true)) {
                continue;
            }
            $tokens[] = \strlen($word) > 4 && str_ends_with($word, 's') ? substr($word, 0, -1) : $word;
        }

        return array_values(array_unique($tokens));
    }

    private function issue(IssueSeverity $severity, string $code, string $message): CompatibilityIssue
    {
        return new CompatibilityIssue($severity, $code, $message);
    }
}
