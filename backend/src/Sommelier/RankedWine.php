<?php

declare(strict_types=1);

namespace App\Sommelier;

/**
 * Une bouteille classée par le sommelier (LLM ou repli local). Seul le SKU vient du classement : le produit
 * (prix, stock, attributs) est toujours relu en base.
 */
final readonly class RankedWine
{
    public function __construct(
        public string $sku,
        public int $matchScore,
        public string $justification,
        public bool $coupDeCoeur,
    ) {
    }
}
