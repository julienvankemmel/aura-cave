<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Entity\Product;

final class ProductPresenter
{
    /**
     * @return array<string, mixed>
     */
    public static function present(Product $p): array
    {
        return [
            'id' => $p->id->toRfc4122(),
            'sku' => $p->sku,
            'name' => $p->name,
            'brand' => $p->brand,
            'category' => $p->category,
            'price_cents' => $p->priceCents,
            'stock' => $p->stock,
            'description' => $p->description,
            'tags' => $p->tags,
            // `{}` et non `[]` quand vide, pour rester un objet côté clients.
            'attributes' => [] === $p->attributes ? new \stdClass() : $p->attributes,
        ];
    }
}
