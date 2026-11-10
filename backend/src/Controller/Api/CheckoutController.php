<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Checkout\CheckoutNotStartedException;
use App\Checkout\ConversationCheckoutManager;
use App\Checkout\NothingToEditException;
use App\Dto\CheckoutEditRequest;
use App\Dto\CheckoutMessageRequest;
use App\Entity\Cart;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Uuid;

/**
 * Tunnel de commande conversationnel. Les réponses ne contiennent jamais la donnée saisie par le client.
 */
final readonly class CheckoutController
{
    private const CART_ID_REQUIREMENT = '[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-4[0-9a-fA-F]{3}-[89abAB][0-9a-fA-F]{3}-[0-9a-fA-F]{12}';

    public function __construct(private EntityManagerInterface $em, private ConversationCheckoutManager $checkout)
    {
    }

    #[Route('/api/checkout/{cartId}/start', name: 'api_checkout_start', methods: ['POST'], requirements: ['cartId' => self::CART_ID_REQUIREMENT])]
    public function start(string $cartId): JsonResponse
    {
        $cart = $this->em->find(Cart::class, Uuid::fromString($cartId));
        if (null === $cart || $cart->items->isEmpty()) {
            return $this->error('empty_cart', 'The cart is empty: add a selection before ordering.', Response::HTTP_CONFLICT);
        }

        return new JsonResponse($this->checkout->start($cart)->toArray());
    }

    #[Route('/api/checkout/{cartId}/message', name: 'api_checkout_message', methods: ['POST'], requirements: ['cartId' => self::CART_ID_REQUIREMENT])]
    public function message(string $cartId, #[MapRequestPayload] CheckoutMessageRequest $request): JsonResponse
    {
        $cart = $this->em->find(Cart::class, Uuid::fromString($cartId));
        if (null === $cart) {
            return $this->error('checkout_not_started', 'No checkout is in progress.', Response::HTTP_CONFLICT);
        }

        try {
            $turn = $this->checkout->handle($cart, $request->text);
        } catch (CheckoutNotStartedException) {
            return $this->error('checkout_not_started', 'No checkout is in progress.', Response::HTTP_CONFLICT);
        }

        return new JsonResponse($turn->toArray());
    }

    /**
     * Consigne de l'étape courante pour le LLM (service-à-service). Une commande confirmée n'est annoncée qu'une fois.
     */
    #[Route('/api/checkout/{cartId}/hint', name: 'api_checkout_hint', methods: ['POST'], requirements: ['cartId' => self::CART_ID_REQUIREMENT])]
    public function hint(string $cartId): JsonResponse
    {
        $cart = $this->em->find(Cart::class, Uuid::fromString($cartId));

        return new JsonResponse(null === $cart ? ['active' => false, 'step' => null, 'instruction' => null] : $this->checkout->hint($cart));
    }

    /**
     * Corrige un champ (nom, adresse, téléphone, email) : pendant la collecte comme après la commande.
     * Le champ vient de l'intention comprise par le LLM à partir d'un message sans donnée personnelle.
     */
    #[Route('/api/checkout/{cartId}/edit', name: 'api_checkout_edit', methods: ['POST'], requirements: ['cartId' => self::CART_ID_REQUIREMENT])]
    public function edit(string $cartId, #[MapRequestPayload] CheckoutEditRequest $request): JsonResponse
    {
        $cart = $this->em->find(Cart::class, Uuid::fromString($cartId));
        if (null === $cart) {
            return $this->error('nothing_to_edit', 'There is nothing to edit yet.', Response::HTTP_CONFLICT);
        }

        try {
            return new JsonResponse($this->checkout->edit($cart, $request->field)->toArray());
        } catch (NothingToEditException) {
            return $this->error('nothing_to_edit', 'There is nothing to edit yet.', Response::HTTP_CONFLICT);
        }
    }

    #[Route('/api/checkout/{cartId}/cancel', name: 'api_checkout_cancel', methods: ['POST'], requirements: ['cartId' => self::CART_ID_REQUIREMENT])]
    public function cancel(string $cartId): JsonResponse
    {
        $cart = $this->em->find(Cart::class, Uuid::fromString($cartId));
        if (null !== $cart) {
            $this->checkout->cancel($cart);
        }

        return new JsonResponse(['active' => false]);
    }

    private function error(string $code, string $message, int $status): JsonResponse
    {
        return new JsonResponse(['error' => ['code' => $code, 'message' => $message]], $status);
    }
}
