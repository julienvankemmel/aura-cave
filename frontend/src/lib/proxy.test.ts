import { describe, expect, it } from "vitest";

import { isSameOrigin, sanitizeChatBody, sanitizeCheckoutBody } from "./proxy";

const CART = "6f1c2a54-3b8e-4f0a-9d71-0a5c8e2b7d13";

describe("sanitizeChatBody", () => {
  it("accepts a message and nothing else (no encrypted blob any more)", () => {
    expect(sanitizeChatBody({ cart_id: CART, message: "bonjour" })).toEqual({ cart_id: CART, message: "bonjour" });
    expect(sanitizeChatBody({ cart_id: CART, encrypted_blob: "QUJDREVGR0hJSktMTU5PUA==" })).toBeNull();
  });

  it("rejects extra fields (no way to smuggle headers or agent options)", () => {
    expect(sanitizeChatBody({ cart_id: CART, message: "x", api_key: "k" })).toBeNull();
  });

  it("rejects invalid cart ids, empty content and oversized messages", () => {
    expect(sanitizeChatBody({ cart_id: "nope", message: "x" })).toBeNull();
    expect(sanitizeChatBody({ cart_id: "6f1c2a54-3b8e-1f0a-9d71-0a5c8e2b7d13", message: "x" })).toBeNull(); // pas v4
    expect(sanitizeChatBody({ cart_id: CART })).toBeNull();
    expect(sanitizeChatBody({ cart_id: CART, message: "   " })).toBeNull();
    expect(sanitizeChatBody({ cart_id: CART, message: "x".repeat(2001) })).toBeNull();
    expect(sanitizeChatBody(null)).toBeNull();
    expect(sanitizeChatBody([])).toBeNull();
  });
});

describe("sanitizeCheckoutBody", () => {
  it("accepts the raw answer only on the message route, with the cart id", () => {
    expect(sanitizeCheckoutBody({ cart_id: CART, text: "12 rue de la Paix 75002 Paris" }, true)).toEqual({
      cart_id: CART,
      text: "12 rue de la Paix 75002 Paris",
    });
    expect(sanitizeCheckoutBody({ cart_id: CART }, false)).toEqual({ cart_id: CART });
  });

  it("rejects bad ids, empty or oversized text, extra fields and text on the cancel route", () => {
    expect(sanitizeCheckoutBody({ cart_id: "nope", text: "x" }, true)).toBeNull();
    expect(sanitizeCheckoutBody({ cart_id: CART, text: "   " }, true)).toBeNull();
    expect(sanitizeCheckoutBody({ cart_id: CART }, true)).toBeNull();
    expect(sanitizeCheckoutBody({ cart_id: CART, text: "x".repeat(501) }, true)).toBeNull();
    expect(sanitizeCheckoutBody({ cart_id: CART, text: "x", extra: "x" }, true)).toBeNull();
    expect(sanitizeCheckoutBody({ cart_id: CART, text: "x" }, false)).toBeNull();
    expect(sanitizeCheckoutBody(null, true)).toBeNull();
  });
});

describe("isSameOrigin", () => {
  it("allows matching origins and non-browser clients, blocks cross-origin", () => {
    expect(isSameOrigin("http://localhost:3100", "localhost:3100")).toBe(true);
    expect(isSameOrigin(null, "localhost:3100")).toBe(true);
    expect(isSameOrigin("https://evil.example", "localhost:3100")).toBe(false);
    expect(isSameOrigin("garbage", "localhost:3100")).toBe(false);
  });
});
