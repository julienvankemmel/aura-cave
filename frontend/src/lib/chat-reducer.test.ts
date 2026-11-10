import { describe, expect, it } from "vitest";

import {
  cartAggregates,
  chatReducer,
  createInitialState,
  initialState,
  validationState,
  type ChatAction,
  type ChatState,
} from "./chat-reducer";
import type { AgentEvent, BundleComparison, CartUpdate, Product } from "./types";

const product = (id: string, category: Product["category"], attributes: Product["attributes"] = {}): Product => ({
  id,
  sku: id.toUpperCase(),
  name: `Produit ${id}`,
  brand: "Test",
  category,
  price_cents: 10000,
  attributes,
});

const COMPARISON: BundleComparison = {
  recommended_index: 1,
  selections: [0, 1].map((i) => ({
    label: `Sélection ${i + 1}`,
    items: [
      { role: "wine", id: "wine" + i, sku: "W" + i, name: "Vin " + i, brand: "T", category: "wine", price_cents: 10000, attributes: { tannin: 4, region: "Bordeaux" } },
      { role: "accessory", id: "carafe" + i, sku: "C" + i, name: "Carafe " + i, brand: "T", category: "accessory", price_cents: 10000, attributes: { kind: "Carafe" } },
    ],
    total_cents: 20000 + i * 1000,
    metrics: { bottles: 1 },
    details: { serving_year: 2026 },
    compatible: true,
    within_budget: true,
    issues: i === 1 ? [{ severity: "warning" as const, code: "W", message: "Attention" }] : [],
  })),
};

const CART: CartUpdate = {
  cart_id: "c",
  total_cents: 250000,
  compatibility_ok: true,
  issues: [],
  items: [
    { product_id: "wine1", sku: "W1", name: "Vin 1", quantity: 1, unit_price_cents: 100000, line_total_cents: 100000 },
    { product_id: "bat", sku: "CARAFE", name: "Carafe", quantity: 2, unit_price_cents: 8900, line_total_cents: 17800 },
  ],
};

function run(actions: ChatAction[], from: ChatState = initialState): ChatState {
  return actions.reduce(chatReducer, from);
}
const ev = (event: AgentEvent): ChatAction => ({ type: "event", event });
const start: ChatAction[] = [
  { type: "user_message", id: "u1", text: "bonjour" },
  { type: "assistant_start", id: "a1" },
];

describe("chatReducer", () => {
  it("accumulates text deltas into the last assistant message", () => {
    const s = run([...start, ev({ event: "text_delta", data: { text: "Bon" } }), ev({ event: "text_delta", data: { text: "jour" } })]);

    expect(s.messages.map((m) => [m.role, m.text])).toEqual([["user", "bonjour"], ["assistant", "Bonjour"]]);
    expect(s.status).toBe("streaming");
  });

  it("groups consecutive product cards and feeds the catalog", () => {
    const s = run([...start, ev({ event: "ui_product_card", data: product("a", "wine") }), ev({ event: "ui_product_card", data: product("b", "wine") })]);

    const blocks = s.messages[1]?.blocks ?? [];
    expect(blocks).toHaveLength(1);
    expect(blocks[0]).toMatchObject({ type: "products" });
    expect(Object.keys(s.shop.catalog)).toEqual(["a", "b"]);
  });

  it("stores the comparison without choosing for the customer and keeps richer catalog entries", () => {
    const rich = product("wine1", "wine", { tannin: 4, body: 5 });
    const s = run([...start, ev({ event: "ui_product_card", data: rich }), ev({ event: "ui_bundle_comparison", data: COMPARISON })]);

    expect(s.shop.chosenIndex).toBeNull();
    expect(s.shop.comparison?.recommended_index).toBe(1);
    expect(s.shop.catalog["wine1"]?.attributes).toMatchObject({ tannin: 4, body: 5 });
    expect(s.shop.catalog["wine0"]?.name).toBe("Vin 0");
    expect(s.messages[1]?.blocks.some((b) => b.type === "comparison")).toBe(true);
  });

  it("lets the user choose another selection and updates the budget", () => {
    const s = run([...start, ev({ event: "ui_bundle_comparison", data: COMPARISON }), { type: "choose_selection", index: 0 }, { type: "set_budget", cents: 250000 }]);

    expect(s.shop.chosenIndex).toBe(0);
    expect(s.shop.budgetCents).toBe(250000);
  });

  it("tracks the conversational checkout step, from the agent and from the secure channel", () => {
    let s = run([...start, ev({ event: "ui_checkout_step", data: { step: "AWAITING_FULL_NAME" } })]);
    expect(s.shop.checkoutStep).toBe("AWAITING_FULL_NAME");

    s = chatReducer(s, { type: "checkout_step", step: "AWAITING_ADDRESS" });
    expect(s.shop.checkoutStep).toBe("AWAITING_ADDRESS");
    expect(s.messages[1]?.blocks).toEqual([]);

    s = chatReducer(s, { type: "checkout_step", step: null });
    expect(s.shop.checkoutStep).toBeNull();
    expect(chatReducer({ ...s, shop: { ...s.shop, checkoutStep: "AWAITING_PHONE" } }, { type: "reset" }).shop.checkoutStep).toBeNull();
  });

  it("records cart updates and the checkout", () => {
    const checkout = { cart_id: "c", total_cents: 250000, currency: "EUR", mode: "test", checkout_url: null };
    const s = run([...start, ev({ event: "ui_cart_update", data: CART }), ev({ event: "ui_stripe_checkout", data: checkout })]);

    expect(s.shop.cart?.total_cents).toBe(250000);
    expect(s.shop.checkout).toEqual(checkout);
  });

  it("surfaces agent errors and stream failures, then returns to idle", () => {
    const s = run([...start, ev({ event: "error", data: { message: "boom" } }), { type: "stream_end" }]);
    expect(s.error).toBe("boom");
    expect(s.status).toBe("idle");
    expect(run([...start, { type: "stream_error", message: "réseau" }]).error).toBe("réseau");
  });

  it("clears the previous error on a new user message and resets everything", () => {
    const failed = run([...start, { type: "stream_error", message: "x" }]);
    expect(chatReducer(failed, { type: "user_message", id: "u2", text: "encore" }).error).toBeNull();
    expect(chatReducer(failed, { type: "reset" })).toEqual(initialState);
  });
});

describe("cart selectors", () => {
  it("aggregates the cart totals (wine: bottles only, accessories excluded)", () => {
    const s = run([
      ...start,
      ev({ event: "ui_product_card", data: product("wine1", "wine", { vintage: 2015 }) }),
      ev({ event: "ui_product_card", data: product("bat", "accessory", {}) }),
      ev({ event: "ui_cart_update", data: CART }),
    ]);

    expect(cartAggregates(s.shop)).toEqual([{ label: "Bouteilles", value: "1" }]);
  });

  it("validation: idle, then preview of the chosen selection, then the cart", () => {
    expect(validationState(initialState.shop)).toEqual({ status: "idle" });

    const preview = run([...start, ev({ event: "ui_bundle_comparison", data: COMPARISON }), { type: "choose_selection", index: 1 }]);
    expect(validationState(preview.shop)).toEqual({ status: "warning", messages: ["Attention"], source: "preview" });

    const broken = run([...start, ev({ event: "ui_cart_update", data: { ...CART, compatibility_ok: false, issues: [{ severity: "error", code: "M", message: "Appellation" }] } })]);
    expect(validationState(broken.shop)).toEqual({ status: "error", messages: ["Appellation"], source: "cart" });

    expect(validationState(run([...start, ev({ event: "ui_cart_update", data: CART })]).shop)).toMatchObject({ status: "ok", source: "cart" });
  });
});

describe("wine defaults", () => {
  it("the conversation starts with its default budget, and reset keeps it", () => {
    expect(createInitialState().shop.budgetCents).toBe(60_000);
    expect(chatReducer(initialState, { type: "reset" }).shop.budgetCents).toBe(60_000);
  });

  it("feeds the catalog with the items and accessory groups", () => {
    const wineSelection: BundleComparison = {
      recommended_index: 0,
      selections: [
        {
          label: "Sélection 1",
          items: [{ role: "wine", id: "w1", sku: "W1", name: "Pauillac", brand: "Château", category: "wine", price_cents: 12000, attributes: { appellation: "Pauillac" } }],
          total_cents: 12000,
          metrics: { bottles: 1 },
          details: { pairing: { dish: "agneau", matches: ["W1"], mismatches: [] } },
          compatible: true,
          within_budget: true,
          issues: [],
        },
      ],
    };
    const s = run([
      ...start,
      ev({ event: "ui_bundle_comparison", data: wineSelection }),
      ev({ event: "ui_accessory_upsell", data: { item_ids: ["w1"], groups: [{ key: "accessories", label: "Service", items: [product("carafe", "accessory", { kind: "Carafe" })] }] } }),
    ]);

    expect(s.shop.catalog["w1"]?.attributes).toEqual({ appellation: "Pauillac" });
    expect(s.shop.catalog["carafe"]?.category).toBe("accessory");
    expect(s.messages[1]?.blocks.map((b) => b.type)).toEqual(["comparison", "upsell"]);
  });
});
