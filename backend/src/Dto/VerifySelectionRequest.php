<?php

declare(strict_types=1);

namespace App\Dto;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * Vérification d'une sélection de produits : plat à accorder, année de service, budget.
 */
final readonly class VerifySelectionRequest
{
    /**
     * @param list<string> $itemIds produits de la sélection
     */
    public function __construct(
        #[Assert\Count(min: 1, max: 25)]
        #[Assert\All(new Assert\Uuid())]
        public array $itemIds,
        #[Assert\Positive]
        public ?int $budgetCents = null,
        #[Assert\Length(max: 200)]
        public ?string $dish = null,
        #[Assert\Range(min: 1900, max: 2200)]
        public ?int $servingYear = null,
        /** Une sélection complète est attendue (au moins un vin). */
        public bool $strict = true,
    ) {
    }
}
