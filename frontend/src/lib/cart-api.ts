import type { CartItem, CartUpdate, Issue } from "./types";

/** Réponse du panier côté Symfony (même forme pour l'ajout et la suppression). */
interface BackendCart {
  cart_id: string;
  total_cents: number;
  items: CartItem[];
  compatibility: { compatible: boolean; issues: Issue[] };
}

export function toCartUpdate(cart: BackendCart): CartUpdate {
  return {
    cart_id: cart.cart_id,
    total_cents: cart.total_cents,
    items: cart.items,
    compatibility_ok: cart.compatibility.compatible,
    issues: cart.compatibility.issues,
  };
}

export class CartApiError extends Error {
  constructor(readonly status: number) {
    super(`Cart request failed (${status})`);
  }
}

/** Retire un produit du panier et renvoie le panier à jour. */
export async function removeCartItem(
  cartId: string,
  productId: string,
  fetchImpl: typeof fetch = fetch,
): Promise<CartUpdate> {
  const response = await fetchImpl(`/api/cart/${cartId}/items/${productId}`, { method: "DELETE" });
  if (!response.ok) throw new CartApiError(response.status);
  const data = (await response.json()) as Partial<BackendCart>;
  if (
    typeof data.cart_id !== "string" ||
    typeof data.total_cents !== "number" ||
    !Array.isArray(data.items) ||
    typeof data.compatibility?.compatible !== "boolean"
  ) {
    throw new CartApiError(502);
  }
  return toCartUpdate(data as BackendCart);
}
