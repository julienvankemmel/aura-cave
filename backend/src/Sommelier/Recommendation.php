<?php

declare(strict_types=1);

namespace App\Sommelier;

use App\Entity\Product;

final readonly class Recommendation
{
    public const NO_MATCH = 'no_match';
    public const OK = 'ok';

    /**
     * @param list<array{product: Product, match_score: int, justification: string, coup_de_coeur: bool}> $results
     * @param 'llm'|'fallback'|null                                                                       $source  null si aucun vin ne satisfait les critères stricts
     */
    public function __construct(
        public string $status,
        public array $results,
        public ?string $source = null,
        public ?string $model = null,
        public ?string $reason = null,
    ) {
    }
}
