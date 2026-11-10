<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Compatibility\VerificationContext;
use App\Compatibility\WineCompatibilityChecker;
use App\Dto\VerifySelectionRequest;
use App\Repository\ProductSearch;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Uuid;

final readonly class CompatibilityController
{
    public function __construct(
        private ProductSearch $search,
        private WineCompatibilityChecker $checker,
    ) {
    }

    #[Route('/api/compatibility/verify-selection', name: 'api_compat_verify_selection', methods: ['POST'])]
    public function verify(#[MapRequestPayload] VerifySelectionRequest $request): JsonResponse
    {
        $ids = array_values(array_unique($request->itemIds));
        $found = $this->search->findByIds(array_map(Uuid::fromString(...), $ids));

        $products = [];
        foreach ($ids as $id) {
            $product = $found[Uuid::fromString($id)->toRfc4122()] ?? null;
            if (null === $product) {
                return $this->error('product_not_found', 'Unknown product id in item_ids.', Response::HTTP_NOT_FOUND);
            }
            $products[] = $product;
        }

        $context = new VerificationContext(
            $request->budgetCents,
            $request->dish,
            $request->servingYear,
            $request->strict,
        );

        return new JsonResponse($this->checker->verify($products, $context)->toArray());
    }

    private function error(string $code, string $message, int $status): JsonResponse
    {
        return new JsonResponse(['error' => ['code' => $code, 'message' => $message]], $status);
    }
}
