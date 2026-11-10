<?php

declare(strict_types=1);

namespace App\Catalog;

use App\Entity\Product;

/**
 * Filtre sur un attribut produit (chemin pointé).
 * Opérateurs : eq, in (valeur ∈ liste), gte, lte, contains (texte ou élément de liste), any (une des valeurs présente).
 * Avec `includeMissing`, un produit sans cet attribut reste éligible (ex. accessoires sans millésime).
 */
final readonly class AttributeFilter
{
    public const OPERATORS = ['eq', 'in', 'gte', 'lte', 'contains', 'any'];

    public function __construct(
        public string $key,
        public string $op,
        public mixed $value,
        public bool $includeMissing = false,
    ) {
    }

    /**
     * @param array<array-key, mixed> $row
     */
    public static function fromArray(array $row): self
    {
        return new self(
            (string) ($row['key'] ?? ''),
            (string) ($row['op'] ?? 'eq'),
            $row['value'] ?? null,
            true === ($row['include_missing'] ?? false),
        );
    }

    public function matches(Product $product): bool
    {
        $actual = $product->attribute($this->key);
        if (null === $actual) {
            return $this->includeMissing;
        }

        return match ($this->op) {
            'eq' => self::same($actual, $this->value),
            'in' => \is_array($this->value) && $this->anyOf([$actual], $this->value),
            'gte' => is_numeric($actual) && is_numeric($this->value) && $actual >= $this->value,
            'lte' => is_numeric($actual) && is_numeric($this->value) && $actual <= $this->value,
            'contains' => $this->contains($actual),
            'any' => \is_array($actual) && $this->anyOf($actual, \is_array($this->value) ? $this->value : [$this->value]),
            default => false,
        };
    }

    private function contains(mixed $actual): bool
    {
        if (\is_array($actual)) {
            return $this->anyOf($actual, [$this->value]);
        }

        return \is_string($actual) && \is_scalar($this->value) && str_contains(self::fold($actual), self::fold((string) $this->value));
    }

    /**
     * @param array<array-key, mixed> $haystack
     * @param array<array-key, mixed> $needles
     */
    private function anyOf(array $haystack, array $needles): bool
    {
        foreach ($needles as $needle) {
            foreach ($haystack as $item) {
                if (self::same($item, $needle)) {
                    return true;
                }
            }
        }

        return false;
    }

    private static function same(mixed $a, mixed $b): bool
    {
        if (\is_string($a) && \is_string($b)) {
            return self::fold($a) === self::fold($b);
        }
        if (is_numeric($a) && is_numeric($b)) {
            return (float) $a === (float) $b;
        }

        return $a === $b;
    }

    private static function fold(string $text): string
    {
        return mb_strtolower(trim($text));
    }
}
