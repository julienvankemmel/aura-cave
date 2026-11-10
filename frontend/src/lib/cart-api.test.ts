import { describe, expect, it, vi } from "vitest";

import { CartApiError, removeCartItem, toCartUpdate } from "./cart-api";
import { isCartId, isProductId } from "./proxy";

const CART = "6f1c2a54-3b8e-4f0a-9d71-0a5c8e2b7d13";
const PRODUCT = "7e0b9ad9-7484-5f39-83e5-1abac1fd0017";

const backendCart = {
  cart_id: CART,
  total_cents: 154900,
  items: [{ product_id: PRODUCT, sku: "PETRUS-2014", name: "Pétrus 2014", quantity: 1, unit_price_cents: 154900, line_total_cents: 154900 }],
  compatibility: { compatible: true, within_budget: true, total_cents: 154900, issues: [] },
};

describe("removeCartItem", () => {
  it("calls DELETE on the same-origin route and maps the cart to a cart update", async () => {
    const fetchMock = vi.fn().mockResolvedValue(new Response(JSON.stringify(backendCart), { status: 200 }));

    const update = await removeCartItem(CART, PRODUCT, fetchMock as unknown as typeof fetch);

    expect(fetchMock).toHaveBeenCalledWith(`/api/cart/${CART}/items/${PRODUCT}`, { method: "DELETE" });
    expect(update).toEqual({ cart_id: CART, total_cents: 154900, items: backendCart.items, compatibility_ok: true, issues: [] });
  });

  it("throws CartApiError with the HTTP status on failure", async () => {
    const fetchMock = vi.fn().mockResolvedValue(new Response("{}", { status: 404 }));

    await expect(removeCartItem(CART, PRODUCT, fetchMock as unknown as typeof fetch)).rejects.toMatchObject({ status: 404 });
    await expect(removeCartItem(CART, PRODUCT, fetchMock as unknown as typeof fetch)).rejects.toBeInstanceOf(CartApiError);
  });

  it("rejects malformed responses", async () => {
    const fetchMock = vi.fn().mockResolvedValue(new Response(JSON.stringify({ cart_id: CART }), { status: 200 }));

    await expect(removeCartItem(CART, PRODUCT, fetchMock as unknown as typeof fetch)).rejects.toMatchObject({ status: 502 });
  });
});

describe("toCartUpdate", () => {
  it("exposes compatibility issues of the backend report", () => {
    const issues = [{ severity: "error" as const, code: "APPELLATION_REGION_MISMATCH", message: "Appellation" }];
    const update = toCartUpdate({ ...backendCart, compatibility: { compatible: false, issues } });

    expect(update.compatibility_ok).toBe(false);
    expect(update.issues).toEqual(issues);
  });
});

describe("id validation for the delete route", () => {
  it("accepts UUIDs and refuses path tricks", () => {
    expect(isCartId(CART)).toBe(true);
    expect(isCartId(PRODUCT)).toBe(false); // v5, pas un panier
    expect(isProductId(PRODUCT)).toBe(true);
    for (const bad of ["..", "../secret", `${PRODUCT}/x`, "", "not-a-uuid"]) expect(isProductId(bad)).toBe(false);
  });
});
