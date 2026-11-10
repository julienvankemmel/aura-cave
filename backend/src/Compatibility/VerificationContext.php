<?php

declare(strict_types=1);

namespace App\Compatibility;

/**
 * Contexte optionnel d'une vérification de sélection.
 */
final readonly class VerificationContext
{
    public function __construct(
        public ?int $budgetCents = null,
        /** Plat à accorder. */
        public ?string $dish = null,
        /** Année de service (défaut : année courante). */
        public ?int $servingYear = null,
        /** true : vérification d'une sélection demandée explicitement (au moins un vin est attendu). */
        public bool $strict = false,
    ) {
    }
}
