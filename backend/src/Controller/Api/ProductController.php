<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Dto\ProductSearchRequest;
use App\Embedding\EmbeddingProviderInterface;
use App\Repository\ProductSearch;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;

final readonly class ProductController
{
    public function __construct(
        private ProductSearch $search,
        private EmbeddingProviderInterface $embeddings,
    ) {
    }

    #[Route('/api/products/search', name: 'api_products_search', methods: ['POST'])]
    public function search(#[MapRequestPayload] ProductSearchRequest $request): JsonResponse
    {
        $filters = $request->attributeFilters();

        $rows = $this->search->byVector(
            $this->embeddings->embed($request->query),
            $request->budgetCents,
            $request->category,
            $request->limit,
            $filters,
            $request->minSimilarity,
        );
        $strategy = 'vector';

        if ([] === $rows) {
            $rows = $this->search->byFullText($request->query, $request->budgetCents, $request->category, $request->limit, $filters);
            $strategy = 'fulltext';
        }

        return new JsonResponse([
            'strategy' => $strategy,
            'results' => array_map(
                static fn (array $r): array => ['score' => $r['score']] + ProductPresenter::present($r['product']),
                $rows,
            ),
        ]);
    }
}
