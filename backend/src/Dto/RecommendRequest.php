<?php

declare(strict_types=1);

namespace App\Dto;

use App\Catalog\AttributeFilter;
use Symfony\Component\Validator\Constraints as Assert;

final readonly class RecommendRequest
{
    /**
     * @param list<array{key?: string, op?: string, value?: mixed, include_missing?: bool}> $filters filtres sur `attributes`
     */
    public function __construct(
        /** Description libre de la recherche (sert à la similarité vectorielle). */
        #[Assert\NotBlank]
        #[Assert\Length(max: 300)]
        public string $query,
        /** Plat à accompagner ; à défaut, la requête. */
        #[Assert\Length(max: 200)]
        public ?string $dish = null,
        #[Assert\Positive]
        public ?int $budgetCents = null,
        #[Assert\Range(min: 1, max: 8)]
        public int $limit = 3,
        #[Assert\Count(max: 12)]
        #[Assert\All(new Assert\Collection(
            fields: [
                'key' => [new Assert\NotBlank(), new Assert\Regex('/^[a-z0-9_]+(\.[a-z0-9_]+)*$/')],
                'op' => new Assert\Choice(choices: AttributeFilter::OPERATORS),
                'value' => new Assert\Optional(),
                'include_missing' => new Assert\Optional(new Assert\Type('bool')),
            ],
        ))]
        public array $filters = [],
    ) {
    }

    /**
     * @return list<AttributeFilter>
     */
    public function attributeFilters(): array
    {
        return array_map(AttributeFilter::fromArray(...), $this->filters);
    }
}
