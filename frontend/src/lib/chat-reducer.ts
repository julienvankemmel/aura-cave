import { itemToProduct } from "./attributes";
import { WINE } from "./wine";
import type {
  AccessoryUpsell,
  AgentEvent,
  BundleComparison,
  CartUpdate,
  SelectionItem,
  Product,
  StripeCheckout,
} from "./types";

export type Block =
  | { type: "products"; products: Product[] }
  | { type: "comparison"; data: BundleComparison }
  | { type: "upsell"; data: AccessoryUpsell }
  | { type: "checkout"; data: StripeCheckout };

export interface ChatMessage {
  id: string;
  role: "user" | "assistant";
  text: string;
  blocks: Block[];
}

export interface ShopState {
  budgetCents: number;
  catalog: Record<string, Product>;
  comparison: BundleComparison | null;
  chosenIndex: number | null;
  cart: CartUpdate | null;
  checkout: StripeCheckout | null;
  /** Étape de collecte des coordonnées dans le chat (AWAITING_FULL_NAME…) ; null hors tunnel de commande. */
  checkoutStep: string | null;
}

export interface ChatState {
  messages: ChatMessage[];
  status: "idle" | "streaming";
  error: string | null;
  shop: ShopState;
}

export type ChatAction =
  | { type: "user_message"; id: string; text: string }
  | { type: "assistant_start"; id: string }
  | { type: "event"; event: AgentEvent }
  | { type: "choose_selection"; index: number }
  | { type: "checkout_step"; step: string | null }
  | { type: "set_budget"; cents: number }
  | { type: "stream_end" }
  | { type: "stream_error"; message: string }
  | { type: "reset" };

/** État vierge d'une conversation. */
export function createInitialState(): ChatState {
  return {
    messages: [],
    status: "idle",
    error: null,
    shop: {
      budgetCents: WINE.defaultBudgetCents,
      catalog: {},
      comparison: null,
      chosenIndex: null,
      cart: null,
      checkout: null,
      checkoutStep: null,
    },
  };
}

export const initialState: ChatState = createInitialState();

export const itemToCatalogProduct = itemToProduct;

function addToCatalog(catalog: Record<string, Product>, products: Product[]): Record<string, Product> {
  const next = { ...catalog };
  for (const p of products) next[p.id] = { ...next[p.id], ...p };
  return next;
}

/** Les produits d'un comparatif ne doivent pas écraser une fiche plus complète déjà connue. */
function addItems(catalog: Record<string, Product>, items: SelectionItem[]): Record<string, Product> {
  const next = { ...catalog };
  for (const item of items) next[item.id] = { ...itemToProduct(item), ...next[item.id] };
  return next;
}

function updateLastAssistant(messages: ChatMessage[], fn: (m: ChatMessage) => ChatMessage): ChatMessage[] {
  for (let i = messages.length - 1; i >= 0; i--) {
    const message = messages[i];
    if (message?.role === "assistant") {
      const copy = messages.slice();
      copy[i] = fn(message);
      return copy;
    }
  }
  return messages;
}

function appendBlock(message: ChatMessage, block: Block): ChatMessage {
  return { ...message, blocks: [...message.blocks, block] };
}

function applyEvent(state: ChatState, event: AgentEvent): ChatState {
  const { shop } = state;
  switch (event.event) {
    case "text_delta":
      return { ...state, messages: updateLastAssistant(state.messages, (m) => ({ ...m, text: m.text + event.data.text })) };

    case "ui_product_card":
      return {
        ...state,
        shop: { ...shop, catalog: addToCatalog(shop.catalog, [event.data]) },
        messages: updateLastAssistant(state.messages, (m) => {
          const last = m.blocks.at(-1);
          if (last?.type === "products") {
            const blocks = m.blocks.slice(0, -1);
            return { ...m, blocks: [...blocks, { type: "products", products: [...last.products, event.data] }] };
          }
          return appendBlock(m, { type: "products", products: [event.data] });
        }),
      };

    case "ui_bundle_comparison": {
      const items = event.data.selections.flatMap((c) => c.items);
      return {
        ...state,
        shop: {
          ...shop,
          catalog: addItems(shop.catalog, items),
          comparison: event.data,
          chosenIndex: null,
        },
        messages: updateLastAssistant(state.messages, (m) => appendBlock(m, { type: "comparison", data: event.data })),
      };
    }

    case "ui_accessory_upsell": {
      const products = event.data.groups.flatMap((g) => g.items);
      return {
        ...state,
        shop: { ...shop, catalog: addToCatalog(shop.catalog, products) },
        messages: updateLastAssistant(state.messages, (m) => appendBlock(m, { type: "upsell", data: event.data })),
      };
    }

    case "ui_cart_update":
      return { ...state, shop: { ...shop, cart: event.data } };

    case "ui_checkout_step":
      return { ...state, shop: { ...shop, checkoutStep: event.data.step } };

    case "ui_stripe_checkout":
      return {
        ...state,
        shop: { ...shop, checkout: event.data },
        messages: updateLastAssistant(state.messages, (m) => appendBlock(m, { type: "checkout", data: event.data })),
      };

    case "error":
      return { ...state, error: event.data.message ?? "Le service est momentanément indisponible." };

    case "done":
      return state;
  }
}

export function chatReducer(state: ChatState, action: ChatAction): ChatState {
  switch (action.type) {
    case "user_message":
      return {
        ...state,
        error: null,
        status: "streaming",
        messages: [...state.messages, { id: action.id, role: "user", text: action.text, blocks: [] }],
      };
    case "assistant_start":
      return { ...state, messages: [...state.messages, { id: action.id, role: "assistant", text: "", blocks: [] }] };
    case "event":
      return applyEvent(state, action.event);
    case "choose_selection":
      return { ...state, shop: { ...state.shop, chosenIndex: action.index } };
    case "checkout_step":
      return { ...state, shop: { ...state.shop, checkoutStep: action.step } };
    case "set_budget":
      return { ...state, shop: { ...state.shop, budgetCents: Math.max(0, Math.round(action.cents)) } };
    case "stream_end":
      return { ...state, status: "idle" };
    case "stream_error":
      return { ...state, status: "idle", error: action.message };
    case "reset":
      return createInitialState();
  }
}

// --- Sélecteurs du panier ---------------------------------------------------------

export function chosenSelection(shop: ShopState) {
  const { comparison, chosenIndex } = shop;
  if (!comparison || chosenIndex === null) return null;
  return comparison.selections[chosenIndex] ?? null;
}

/** Totaux du panier (nombre de bouteilles…) calculés depuis le catalogue et le panier. */
export function cartAggregates(shop: ShopState): { label: string; value: string }[] {
  const items = shop.cart?.items ?? [];
  const rows: { label: string; value: string }[] = [];
  for (const aggregate of WINE.aggregates) {
    let total = 0;
    for (const item of items) {
      const product = shop.catalog[item.product_id];
      if (!product) continue;
      if (aggregate.categories && !aggregate.categories.includes(product.category)) continue;
      total += aggregate.kind === "count" ? item.quantity : Number(product.attributes[aggregate.attribute ?? ""] ?? 0) * item.quantity;
    }
    if (total > 0) rows.push({ label: aggregate.label, value: aggregate.format(total) });
  }
  return rows;
}

export type ValidationState =
  | { status: "idle" }
  | { status: "ok" | "warning" | "error"; messages: string[]; source: "cart" | "preview" };

/** Validation de cohérence (appellation, cépages, accord…) : le panier si présent, sinon la sélection choisie. */
export function validationState(shop: ShopState): ValidationState {
  const selection = chosenSelection(shop);
  const source = shop.cart && shop.cart.items.length > 0 ? "cart" : "preview";
  const issues = source === "cart" ? (shop.cart?.issues ?? []) : (selection?.issues ?? []);
  const compatible = source === "cart" ? (shop.cart?.compatibility_ok ?? true) : (selection?.compatible ?? true);
  if (source === "preview" && !selection) return { status: "idle" };
  const errors = issues.filter((i) => i.severity === "error").map((i) => i.message);
  const warnings = issues.filter((i) => i.severity === "warning").map((i) => i.message);
  if (!compatible || errors.length > 0) return { status: "error", messages: errors, source };
  if (warnings.length > 0) return { status: "warning", messages: warnings, source };
  return { status: "ok", messages: [], source };
}
