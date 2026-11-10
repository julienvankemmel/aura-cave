<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity]
#[ORM\Table(name: 'cart_item')]
class CartItem
{
    public const MAX_QUANTITY = 10;

    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME)]
    public private(set) Uuid $id;

    public int $lineTotalCents {
        get => $this->product->priceCents * $this->quantity;
    }

    public function __construct(
        #[ORM\ManyToOne(targetEntity: Cart::class, inversedBy: 'items')]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        public private(set) Cart $cart,
        #[ORM\ManyToOne(targetEntity: Product::class)]
        #[ORM\JoinColumn(nullable: false)]
        public private(set) Product $product,
        #[ORM\Column]
        public private(set) int $quantity,
    ) {
        $this->id = Uuid::v4();
    }

    public function increase(int $by): void
    {
        $this->quantity = min(self::MAX_QUANTITY, $this->quantity + $by);
    }
}
