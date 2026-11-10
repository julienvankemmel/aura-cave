<?php

declare(strict_types=1);

namespace App\Dto;

use App\Entity\CartItem;
use Symfony\Component\Validator\Constraints as Assert;

final readonly class AddCartItemRequest
{
    public function __construct(
        #[Assert\NotBlank]
        #[Assert\Uuid]
        public string $productId,
        #[Assert\Range(min: 1, max: CartItem::MAX_QUANTITY)]
        public int $quantity = 1,
    ) {
    }
}
