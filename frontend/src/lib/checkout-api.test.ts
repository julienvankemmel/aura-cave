import { describe, expect, it, vi } from "vitest";

import { CheckoutApiError, cancelCheckout, hydratePlaceholders, isPlaceholder, postCheckoutMessage, splitPlaceholders } from "./checkout-api";

const CART = "6f1c2a54-3b8e-4f0a-9d71-0a5c8e2b7d13";
const json = (body: unknown, status = 200): Response => new Response(JSON.stringify(body), { status });

describe("postCheckoutMessage", () => {
  it("sends the raw answer to the checkout route (not the chat) and returns the anonymized turn", async () => {
    const fetchImpl = vi.fn().mockResolvedValue(json({ accepted: true, step: "AWAITING_PHONE", llm_message: "[SHIPPING_ADDRESS]", order: null }));

    const turn = await postCheckoutMessage(CART, "12 rue de la Paix 75002 Paris", fetchImpl as unknown as typeof fetch);

    expect(fetchImpl.mock.calls[0]?.[0]).toBe("/api/checkout/message");
    expect(JSON.parse(String(fetchImpl.mock.calls[0]?.[1]?.body))).toEqual({ cart_id: CART, text: "12 rue de la Paix 75002 Paris" });
    expect(turn).toEqual({ accepted: true, step: "AWAITING_PHONE", llm_message: "[SHIPPING_ADDRESS]", order: null });
    expect(JSON.stringify(turn)).not.toContain("Paix");
  });

  it("returns the order on confirmation", async () => {
    const fetchImpl = vi.fn().mockResolvedValue(
      json({ accepted: true, step: "CONFIRMED", llm_message: "[CUSTOMER_EMAIL]", order: { reference: "AC-20261110-ABC123", total_cents: 12000 } }),
    );

    const turn = await postCheckoutMessage(CART, "marie@example.org", fetchImpl as unknown as typeof fetch);

    expect(turn.order).toEqual({ reference: "AC-20261110-ABC123", total_cents: 12000 });
  });

  it("fails on HTTP errors and malformed answers", async () => {
    await expect(postCheckoutMessage(CART, "x", vi.fn().mockResolvedValue(json({}, 409)) as unknown as typeof fetch)).rejects.toMatchObject({ status: 409 });
    await expect(postCheckoutMessage(CART, "x", vi.fn().mockResolvedValue(json({ accepted: "yes" })) as unknown as typeof fetch)).rejects.toBeInstanceOf(CheckoutApiError);
  });
});

describe("cancelCheckout", () => {
  it("posts the cart id only", async () => {
    const fetchImpl = vi.fn().mockResolvedValue(json({ active: false }));
    await cancelCheckout(CART, fetchImpl as unknown as typeof fetch);
    expect(JSON.parse(String(fetchImpl.mock.calls[0]?.[1]?.body))).toEqual({ cart_id: CART });
  });
});

describe("placeholders", () => {
  it("recognizes only the four anonymization tokens", () => {
    expect(isPlaceholder("[CUSTOMER_NAME]")).toBe(true);
    expect(isPlaceholder("[INVALID_INPUT]")).toBe(false);
    expect(isPlaceholder("Marie")).toBe(false);
  });

  it("hydrates the assistant's text with what the customer typed in this browser, leaving unknown tokens", () => {
    const text = "Merci [CUSTOMER_NAME] ! Livraison à [SHIPPING_ADDRESS], appel au [CUSTOMER_PHONE] ; suivi : [CUSTOMER_EMAIL].";

    expect(hydratePlaceholders(text, { "[CUSTOMER_NAME]": "Marie Dupont", "[SHIPPING_ADDRESS]": "12 rue de la Paix 75002 Paris" })).toBe(
      "Merci Marie Dupont ! Livraison à 12 rue de la Paix 75002 Paris, appel au [CUSTOMER_PHONE] ; suivi : [CUSTOMER_EMAIL].",
    );
    expect(hydratePlaceholders("Bonjour", {})).toBe("Bonjour");
  });

  it("splits the text so substituted values can be flagged on screen", () => {
    expect(splitPlaceholders("Merci [CUSTOMER_NAME] ! Au [CUSTOMER_PHONE].", { "[CUSTOMER_NAME]": "Marie Dupont" })).toEqual([
      "Merci ",
      { token: "[CUSTOMER_NAME]", value: "Marie Dupont" },
      " ! Au [CUSTOMER_PHONE].",
    ]);
    expect(splitPlaceholders("Bonjour", {})).toEqual(["Bonjour"]);
  });
});
