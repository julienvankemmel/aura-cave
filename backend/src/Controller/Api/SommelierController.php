<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Dto\RecommendRequest;
use App\Sommelier\SommelierRecommender;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;

final readonly class SommelierController
{
    public function __construct(private SommelierRecommender $recommender)
    {
    }

    #[Route('/api/sommelier/recommend', name: 'api_sommelier_recommend', methods: ['POST'])]
    public function recommend(#[MapRequestPayload] RecommendRequest $request): JsonResponse
    {
        /** @var int<1, 8> $limit */
        $limit = $request->limit;
        $result = $this->recommender->recommend($request->query, $request->dish, $request->budgetCents, $limit, $request->attributeFilters());

        return new JsonResponse([
            'status' => $result->status,
            'source' => $result->source,
            'model' => $result->model,
            'reason' => $result->reason,
            'results' => array_map(
                static fn (array $r): array => ProductPresenter::present($r['product']) + [
                    'match_score' => $r['match_score'],
                    'sommelier_justification' => $r['justification'],
                    'is_coup_de_coeur' => $r['coup_de_coeur'],
                ],
                $result->results,
            ),
        ]);
    }
}
