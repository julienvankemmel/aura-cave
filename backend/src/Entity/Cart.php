<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\CheckoutStepEnum;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity]
#[ORM\Table(name: 'cart')]
class Cart
{
    /** @var Collection<int, CartItem> */
    #[ORM\OneToMany(targetEntity: CartItem::class, mappedBy: 'cart', cascade: ['persist'], orphanRemoval: true)]
    public private(set) Collection $items;

    /** Étape courante du tunnel de commande conversationnel ; null hors checkout. */
    #[ORM\Column(length: 24, nullable: true, enumType: CheckoutStepEnum::class)]
    public private(set) ?CheckoutStepEnum $checkoutStep = null;

    /**
     * Coordonnées collectées jusqu'ici, chiffrées au repos (crypto_box_seal) : jamais en clair avant la commande finale.
     *
     * @var array<string, string>
     */
    #[ORM\Column(type: 'json')]
    public private(set) array $checkoutData = [];

    /** Étape à reprendre une fois le champ corrigé ; `Confirmed` = corriger une commande déjà créée. */
    #[ORM\Column(length: 24, nullable: true, enumType: CheckoutStepEnum::class)]
    public private(set) ?CheckoutStepEnum $checkoutResumeStep = null;

    /** Le client veut corriger une information sans avoir dit laquelle : le prochain message désigne le champ. */
    #[ORM\Column(options: ['default' => false])]
    public private(set) bool $checkoutEditing = false;

    /** Consigne destinée au LLM pour le prochain message (sans aucune donnée personnelle). */
    #[ORM\Column(type: 'text', nullable: true)]
    public private(set) ?string $checkoutInstruction = null;

    #[ORM\Column]
    public private(set) \DateTimeImmutable $createdAt;

    public int $totalCents {
        get => array_sum($this->items->map(static fn (CartItem $i): int => $i->lineTotalCents)->toArray());
    }

    public function __construct(
        #[ORM\Id]
        #[ORM\Column(type: UuidType::NAME)]
        public private(set) Uuid $id,
    ) {
        $this->items = new ArrayCollection();
        $this->createdAt = new \DateTimeImmutable();
    }

    public function addProduct(Product $product, int $quantity): void
    {
        foreach ($this->items as $item) {
            if ($item->product->id->equals($product->id)) {
                $item->increase($quantity);

                return;
            }
        }

        $this->items->add(new CartItem($this, $product, $quantity));
    }

    /**
     * Retire entièrement la ligne du produit ; false si le produit n'est pas dans le panier.
     */
    public function removeProduct(Uuid $productId): bool
    {
        foreach ($this->items as $item) {
            if ($item->product->id->equals($productId)) {
                $this->items->removeElement($item);

                return true;
            }
        }

        return false;
    }

    /**
     * @return list<Product>
     */
    public function products(): array
    {
        return array_values($this->items->map(static fn (CartItem $i): Product => $i->product)->toArray());
    }

    public function startCheckout(string $instruction): void
    {
        $this->checkoutStep = CheckoutStepEnum::AwaitingFullName;
        $this->checkoutData = [];
        $this->checkoutInstruction = $instruction;
    }

    /**
     * @param string $sealed valeur déjà chiffrée
     */
    public function recordCheckoutField(string $field, string $sealed, CheckoutStepEnum $next, string $instruction): void
    {
        $this->checkoutData = [$field => $sealed] + $this->checkoutData;
        $this->checkoutStep = $next;
        $this->checkoutResumeStep = null;
        $this->checkoutEditing = false;
        $this->checkoutInstruction = $instruction;
    }

    /** Revient sur un champ déjà collecté (ou sur une commande créée) ; `$resume` est l'étape à reprendre ensuite. */
    public function reopenCheckout(CheckoutStepEnum $target, CheckoutStepEnum $resume, string $instruction): void
    {
        $this->checkoutStep = $target;
        $this->checkoutResumeStep ??= $resume;
        $this->checkoutEditing = false;
        $this->checkoutInstruction = $instruction;
    }

    public function awaitFieldChoice(string $instruction): void
    {
        $this->checkoutEditing = true;
        $this->checkoutInstruction = $instruction;
    }

    public function dismissEdit(string $instruction): void
    {
        $this->checkoutEditing = false;
        $this->checkoutResumeStep = null;
        $this->checkoutInstruction = $instruction;
    }

    public function setCheckoutInstruction(string $instruction): void
    {
        $this->checkoutInstruction = $instruction;
    }

    /** Commande créée : les coordonnées en clair sont désormais dans `Order`, plus rien n'est gardé ici. */
    public function confirmCheckout(string $instruction): void
    {
        $this->checkoutStep = CheckoutStepEnum::Confirmed;
        $this->checkoutResumeStep = null;
        $this->checkoutEditing = false;
        $this->checkoutData = [];
        $this->checkoutInstruction = $instruction;
    }

    public function endCheckout(): void
    {
        $this->checkoutStep = null;
        $this->checkoutResumeStep = null;
        $this->checkoutEditing = false;
        $this->checkoutData = [];
        $this->checkoutInstruction = null;
    }
}
