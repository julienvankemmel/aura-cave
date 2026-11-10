<?php

declare(strict_types=1);

namespace App\Embedding;

use App\Entity\Product;

/**
 * Repli hors-ligne : feature hashing signé (mots + bigrammes) puis normalisation L2.
 * Reproductible bit à bit, sans modèle ni réseau ; capture la similarité lexicale uniquement.
 */
final class DeterministicHashEmbedder implements EmbeddingProviderInterface
{
    public function embedMany(array $texts): array
    {
        return array_map($this->embed(...), $texts);
    }

    public function embed(string $text): array
    {
        $vector = array_fill(0, Product::EMBEDDING_DIMENSIONS, 0.0);
        $tokens = preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($text), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        $features = $tokens;
        for ($i = 0, $n = \count($tokens) - 1; $i < $n; ++$i) {
            $features[] = $tokens[$i].' '.$tokens[$i + 1];
        }

        foreach ($features as $feature) {
            $hash = hash('sha256', $feature, true);
            /** @var array{1: int} $unpacked */
            $unpacked = unpack('N', $hash);
            $index = $unpacked[1] % Product::EMBEDDING_DIMENSIONS;
            $vector[$index] += (\ord($hash[4]) & 1) ? 1.0 : -1.0;
        }

        $norm = sqrt(array_sum(array_map(static fn (float $v): float => $v * $v, $vector)));
        if ($norm > 0.0) {
            $vector = array_values(array_map(static fn (float $v): float => $v / $norm, $vector));
        }

        return array_values($vector);
    }
}
