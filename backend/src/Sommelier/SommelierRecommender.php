<?php

declare(strict_types=1);

namespace App\Sommelier;

use App\Catalog\AttributeFilter;
use App\Compatibility\Wine\FoodFamilies;
use App\Embedding\EmbeddingProviderInterface;
use App\Entity\Product;
use App\Repository\ProductSearch;

/**
 * « Filtre déterministe SQL + reranking sémantique LLM ».
 *
 *  1. SQL : catégorie vin, stock > 0, budget ; pool de quelques candidats réels classés par similarité pgvector.
 *     Aucun vin → réponse claire, le LLM n'est pas sollicité.
 *  2. Reranking gustatif du pool par le sommelier LLM (cascade de modèles). Si tous échouent : tri local.
 *  3. Les produits renvoyés sont relus en base (prix, stock, attributs) ; seul le classement vient du LLM.
 */
final readonly class SommelierRecommender
{
    /** Taille du pool de candidats transmis au LLM (10 à 15). */
    public const POOL_SIZE = 15;

    public function __construct(
        private ProductSearch $search,
        private EmbeddingProviderInterface $embeddings,
        private WineSommelierRerankerService $reranker,
    ) {
    }

    /**
     * @param list<AttributeFilter> $filters
     * @param int<1, 8>             $limit
     */
    public function recommend(string $query, ?string $dish, ?int $budgetCents, int $limit = 3, array $filters = []): Recommendation
    {
        $pool = $this->pool($query, $budgetCents, $filters);
        if ([] === $pool) {
            return new Recommendation(Recommendation::NO_MATCH, [], reason: null !== $budgetCents ? 'no_wine_in_stock_within_budget' : 'no_wine_in_stock');
        }

        $dishText = null !== $dish && '' !== trim($dish) ? trim($dish) : $query;
        $products = array_map(static fn (array $r): Product => $r['product'], $pool);
        $reranked = $this->reranker->rerank($dishText, $products, $limit);

        if (null !== $reranked) {
            $bySku = [];
            foreach ($products as $product) {
                $bySku[$product->sku] = $product;
            }
            $results = [];
            foreach ($reranked->ranking as $wine) {
                $results[] = [
                    'product' => $bySku[$wine->sku],
                    'match_score' => $wine->matchScore,
                    'justification' => '' !== $wine->justification ? $wine->justification : 'Sélectionné par le sommelier pour cet accord.',
                    'coup_de_coeur' => $wine->coupDeCoeur,
                ];
            }

            return new Recommendation(Recommendation::OK, $results, 'llm', $reranked->model);
        }

        return new Recommendation(Recommendation::OK, $this->localRanking($pool, $dishText, $limit), 'fallback');
    }

    /**
     * @param list<AttributeFilter> $filters
     *
     * @return list<array{product: Product, score: float}>
     */
    private function pool(string $query, ?int $budgetCents, array $filters): array
    {
        $rows = $this->search->byVector($this->embeddings->embed($query), $budgetCents, 'wine', self::POOL_SIZE, $filters, -1.0, true);
        if ([] !== $rows) {
            return $rows;
        }

        $rows = $this->search->byFullText($query, $budgetCents, 'wine', self::POOL_SIZE, $filters);

        return array_values(array_filter($rows, static fn (array $r): bool => $r['product']->stock > 0));
    }

    /**
     * Repli sans LLM : accord déclaré dans le catalogue (familles gustatives) puis similarité vectorielle.
     *
     * @param list<array{product: Product, score: float}> $pool
     *
     * @return list<array{product: Product, match_score: int, justification: string, coup_de_coeur: bool}>
     */
    private function localRanking(array $pool, string $dish, int $limit): array
    {
        $scored = [];
        foreach ($pool as $row) {
            $raw = $row['product']->attribute('food_pairing');
            $pairings = array_values(array_map(static fn (mixed $p): string => (string) $p, \is_array($raw) ? $raw : []));
            $pairing = FoodFamilies::score($dish, $pairings);
            $scored[] = ['row' => $row, 'pairing' => $pairing, 'rank' => 0.6 * $pairing + 0.4 * max(0.0, $row['score'])];
        }
        usort($scored, static fn (array $a, array $b): int => [$b['rank'], $a['row']['product']->sku] <=> [$a['rank'], $b['row']['product']->sku]);

        $results = [];
        foreach (\array_slice($scored, 0, $limit) as $i => $entry) {
            $product = $entry['row']['product'];
            $results[] = [
                'product' => $product,
                'match_score' => (int) round(100 * $entry['rank']),
                'justification' => \sprintf(
                    'Sélection établie d\'après les accords et le profil du catalogue (%s%s) : le sommelier n\'a pas pu être consulté.',
                    (string) ($product->attribute('type') ?? 'vin'),
                    null !== $product->attribute('appellation') ? ', '.(string) $product->attribute('appellation') : '',
                ),
                'coup_de_coeur' => 0 === $i,
            ];
        }

        return $results;
    }
}
