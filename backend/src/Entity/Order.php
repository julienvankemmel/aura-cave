<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * Commande finale. Seul le backend détient les coordonnées en clair, saisies dans le canal de checkout dédié.
 */
#[ORM\Entity]
#[ORM\Table(name: 'customer_order')]
class Order
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME)]
    public private(set) Uuid $id;

    #[ORM\Column(length: 32, unique: true)]
    public private(set) string $reference;

    #[ORM\Column]
    public private(set) \DateTimeImmutable $createdAt;

    public function __construct(
        #[ORM\OneToOne(targetEntity: Cart::class)]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        public private(set) Cart $cart,
        #[ORM\Column]
        public private(set) int $totalCents,
        #[ORM\Column(length: 160)]
        public private(set) string $fullName,
        #[ORM\Column(length: 255)]
        public private(set) string $shippingAddress,
        #[ORM\Column(length: 20)]
        public private(set) string $phone,
        #[ORM\Column(length: 180)]
        public private(set) string $email,
        #[ORM\Column(length: 16)]
        public private(set) string $status = 'confirmed',
    ) {
        $this->id = Uuid::v4();
        $this->reference = 'AC-'.date('Ymd').'-'.strtoupper(substr(bin2hex(random_bytes(3)), 0, 6));
        $this->createdAt = new \DateTimeImmutable();
    }

    /**
     * Corrige une coordonnée de la commande (le client s'est trompé).
     *
     * @param 'full_name'|'address'|'phone'|'email' $field
     */
    public function correct(string $field, string $value): void
    {
        match ($field) {
            'full_name' => $this->fullName = $value,
            'address' => $this->shippingAddress = $value,
            'phone' => $this->phone = $value,
            'email' => $this->email = $value,
        };
    }
}
