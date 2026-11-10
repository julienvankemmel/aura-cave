<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Compatibility\WineCompatibilityChecker;
use App\Dto\AddCartItemRequest;
use App\Entity\Cart;
use App\Entity\CartItem;
use App\Entity\Product;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Uuid;

final readonly class CartController
{
    private const PRODUCT_ID_REQUIREMENT = '[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}';
    private const CART_ID_REQUIREMENT = '[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-4[0-9a-fA-F]{3}-[89abAB][0-9a-fA-F]{3}-[0-9a-fA-F]{12}';

    public function __construct(
        private EntityManagerInterface $em,
        private WineCompatibilityChecker $checker,
    ) {
    }

    #[Route('/api/cart/{cartId}/items', name: 'api_cart_add_item', methods: ['POST'], requirements: ['cartId' => self::CART_ID_REQUIREMENT])]
    public function addItem(string $cartId, #[MapRequestPayload] AddCartItemRequest $request): JsonResponse
    {
        $product = $this->em->find(Product::class, Uuid::fromString($request->productId));
        if (null === $product) {
            return $this->error('product_not_found', 'Unknown product.', Response::HTTP_NOT_FOUND);
        }

        $uuid = Uuid::fromString($cartId);
        $cart = $this->em->find(Cart::class, $uuid) ?? new Cart($uuid);
        $this->em->persist($cart);
        $inCart = 0;
        foreach ($cart->items as $item) {
            if ($item->product->id->equals($product->id)) {
                $inCart = $item->quantity;
            }
        }
        if (!$product->inStock($inCart + $request->quantity)) {
            return new JsonResponse(['error' => ['code' => 'insufficient_stock', 'message' => 'Not enough stock.', 'available' => $product->stock, 'in_cart' => $inCart]], Response::HTTP_CONFLICT);
        }
        $cart->addProduct($product, $request->quantity);
        $this->em->flush();

        return new JsonResponse($this->summary($cart), Response::HTTP_CREATED);
    }

    /**
     * Retire la ligne d'un produit du panier et renvoie le panier à jour.
     */
    #[Route('/api/cart/{cartId}/items/{productId}', name: 'api_cart_remove_item', methods: ['DELETE'], requirements: ['cartId' => self::CART_ID_REQUIREMENT, 'productId' => self::PRODUCT_ID_REQUIREMENT])]
    public function removeItem(string $cartId, string $productId): JsonResponse
    {
        $cart = $this->em->find(Cart::class, Uuid::fromString($cartId));
        if (null === $cart || !$cart->removeProduct(Uuid::fromString($productId))) {
            return $this->error('item_not_in_cart', 'This product is not in the cart.', Response::HTTP_NOT_FOUND);
        }
        $this->em->flush();

        return new JsonResponse($this->summary($cart));
    }

    /**
     * @return array<string, mixed>
     */
    private function summary(Cart $cart): array
    {
        return [
            'cart_id' => $cart->id->toRfc4122(),
            'items' => array_values($cart->items->map(static fn (CartItem $i): array => [
                'product_id' => $i->product->id->toRfc4122(),
                'sku' => $i->product->sku,
                'name' => $i->product->name,
                'quantity' => $i->quantity,
                'unit_price_cents' => $i->product->priceCents,
                'line_total_cents' => $i->lineTotalCents,
            ])->toArray()),
            'total_cents' => $cart->totalCents,
            'compatibility' => $this->compatibility($cart),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function compatibility(Cart $cart): array
    {
        $products = $cart->products();
        if ([] === $products) {
            return (new \App\Compatibility\CompatibilityResultDTO([], 0, null))->toArray();
        }

        return $this->checker->verify($products)->toArray();
    }

    private function error(string $code, string $message, int $status): JsonResponse
    {
        return new JsonResponse(['error' => ['code' => $code, 'message' => $message]], $status);
    }
}
