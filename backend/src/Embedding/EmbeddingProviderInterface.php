<?php

declare(strict_types=1);

namespace App\Embedding;

interface EmbeddingProviderInterface
{
    /**
     * @return list<float> vecteur de 384 dimensions, normalisé L2
     */
    public function embed(string $text): array;

    /**
     * Calcul par lot (import de catalogue) : un appel réseau pour plusieurs textes.
     *
     * @param list<string> $texts
     *
     * @return list<list<float>> un vecteur par texte, dans le même ordre
     */
    public function embedMany(array $texts): array;
}
