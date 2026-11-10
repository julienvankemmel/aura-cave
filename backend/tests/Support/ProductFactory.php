<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Entity\Product;

final class ProductFactory
{
    /**
     * @param list<string>         $cepages
     * @param list<string>         $pairings
     * @param array<string, mixed> $extra
     */
    public static function wine(
        string $sku = 'WINE-1',
        string $type = 'rouge',
        string $region = 'Bordeaux',
        string $appellation = 'Pauillac',
        array $cepages = ['Cabernet Sauvignon', 'Merlot'],
        int $vintage = 2015,
        array $pairings = ['agneau', 'boeuf', 'fromages affinés'],
        int $price = 12000,
        array $extra = [],
        int $stock = 6,
    ): Product {
        return new Product($sku, 'Vin '.$sku, 'Château Test', 'wine', $price, 'Vin de test', [
            'type' => $type,
            'region' => $region,
            'appellation' => $appellation,
            'cepages' => $cepages,
            'vintage' => $vintage,
            'food_pairing' => $pairings,
            'alcohol_degree' => 13.5,
        ] + $extra, $stock);
    }
}
