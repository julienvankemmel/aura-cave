<?php

declare(strict_types=1);

namespace App\Catalog\Wine;

/**
 * Compteurs d'un import du jeu de données vin (une ligne CSV finit dans exactement une des catégories).
 */
final class WineImportStats
{
    public int $read = 0;
    public int $imported = 0;
    public int $skippedNoPrice = 0;
    public int $skippedNotWine = 0;
    public int $skippedMalformed = 0;
    public int $skippedDuplicate = 0;

    /**
     * @return array<string, int>
     */
    public function toArray(): array
    {
        return [
            'lignes lues' => $this->read,
            'importées' => $this->imported,
            'ignorées : sans prix' => $this->skippedNoPrice,
            'ignorées : pas un vin (spiritueux, saké…)' => $this->skippedNotWine,
            'ignorées : ligne mal formée' => $this->skippedMalformed,
            'ignorées : doublon' => $this->skippedDuplicate,
        ];
    }
}
