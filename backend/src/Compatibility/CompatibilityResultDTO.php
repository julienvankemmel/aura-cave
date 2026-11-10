<?php

declare(strict_types=1);

namespace App\Compatibility;

use App\Enum\IssueSeverity;

/**
 * Résultat d'une vérification de compatibilité, Les informations propres à l'accord mets-vins
 * (accords mets-vins, année de service) vivent dans `details`, fusionnées au premier niveau
 * de la réponse JSON.
 */
final readonly class CompatibilityResultDTO
{
    /**
     * @param list<CompatibilityIssue> $issues
     * @param array<string, mixed>     $details Données propres à l'accord mets-vins, ex. ['pairing' => [...]]
     */
    public function __construct(
        public array $issues,
        public int $totalCents,
        public ?int $budgetCents,
        public array $details = [],
    ) {
    }

    public function isCompatible(): bool
    {
        return [] === $this->issuesOf(IssueSeverity::Error);
    }

    public function isWithinBudget(): bool
    {
        return null === $this->budgetCents || $this->totalCents <= $this->budgetCents;
    }

    /**
     * @return list<CompatibilityIssue>
     */
    public function issuesOf(IssueSeverity $severity): array
    {
        return array_values(array_filter($this->issues, static fn (CompatibilityIssue $i): bool => $i->severity === $severity));
    }

    public function hasIssue(string $code): bool
    {
        foreach ($this->issues as $issue) {
            if ($issue->code === $code) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'compatible' => $this->isCompatible(),
            'within_budget' => $this->isWithinBudget(),
            'total_cents' => $this->totalCents,
            'budget_cents' => $this->budgetCents,
            ...$this->details,
            'issues' => array_map(static fn (CompatibilityIssue $i): array => $i->toArray(), $this->issues),
        ];
    }
}
