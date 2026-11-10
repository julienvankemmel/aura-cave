<?php

declare(strict_types=1);

namespace App\Catalog;

/**
 * Aplatit les `attributes` d'un produit en texte pour le modèle d'embedding.
 */
final class AttributeText
{
    /** Synonymes ajoutés quand un attribut booléen est vrai : améliore la recherche sémantique en langage naturel. */
    private const BOOLEAN_HINTS = [
        'weather_sealed' => 'tropicalisé étanche poussière humidité weather sealed',
        'ibis' => 'stabilisation IBIS',
        'unlimited_recording' => 'enregistrement illimité',
        'wireless_transfer' => 'transfert sans fil',
    ];

    /**
     * @param array<string, mixed> $attributes
     */
    public static function flatten(array $attributes): string
    {
        $parts = [];
        self::walk($attributes, '', $parts);

        return implode(' ', $parts);
    }

    /**
     * @param array<array-key, mixed> $node
     * @param list<string>            $parts
     */
    private static function walk(array $node, string $prefix, array &$parts): void
    {
        foreach ($node as $key => $value) {
            $name = \is_string($key) ? $key : $prefix;
            if (\is_array($value)) {
                if (array_is_list($value)) {
                    $scalars = array_filter($value, 'is_scalar');
                    if ([] !== $scalars) {
                        $parts[] = str_replace('_', ' ', $name).' '.implode(' ', array_map('strval', $scalars));
                    }
                    continue;
                }
                self::walk($value, $name, $parts);
                continue;
            }
            if (true === $value) {
                $parts[] = self::BOOLEAN_HINTS[$name] ?? str_replace('_', ' ', $name);
            } elseif (\is_string($value) || \is_int($value) || \is_float($value)) {
                $parts[] = str_replace('_', ' ', $name).' '.$value;
            }
        }
    }
}
