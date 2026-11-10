<?php

declare(strict_types=1);

namespace App\Sommelier;

final readonly class RerankResult
{
    /**
     * @param list<RankedWine> $ranking du meilleur au moins bon
     */
    public function __construct(public array $ranking, public string $model)
    {
    }
}
