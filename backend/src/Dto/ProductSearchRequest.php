<?php

declare(strict_types=1);

namespace App\Dto;

use App\Catalog\AttributeFilter;
use Symfony\Component\Validator\Constraints as Assert;

final readonly class ProductSearchRequest
{
    /**
     * @param list<array{key?: string, op?: string, value?: mixed, include_missing?: bool}> $filters filtres sur `attributes`
     */
    public function __construct(
        #[Assert\NotBlank]
        #[Assert\Length(max: 300)]
        public string $query,
        #[Assert\Positive]
        public ?int $budgetCents = null,
        #[Assert\Length(max: 32)]
        public ?string $category = null,
        #[Assert\Range(min: 1, max: 20)]
        public int $limit = 5,
        /** Similarité cosinus minimale ; -1 = ne jamais exclure (la sémantique ne fait alors que classer). */
        #[Assert\Range(min: -1, max: 1)]
        public ?float $minSimilarity = null,
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
     * À appeler après validation.
     *
     * @return list<AttributeFilter>
     */
    public function attributeFilters(): array
    {
        return array_map(AttributeFilter::fromArray(...), $this->filters);
    }
}
