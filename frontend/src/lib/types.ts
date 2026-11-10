/** Catégorie de produit : « wine » ou « accessory ». */
export type Category = string;

/** Valeur d'attribut : scalaire, liste ou bloc imbriqué (ex. drinking_window, serving_temp_c). */
export type Attributes = Record<string, unknown>;

/** Produit du catalogue : champs communs + `attributes` propres au vin (cépage, millésime, notes de dégustation…). */
export interface Product {
  id: string;
  sku: string;
  name: string;
  brand: string;
  category: Category;
  price_cents: number;
  stock?: number;
  description?: string;
  tags?: string[];
  attributes: Attributes;
  score?: number;
}

export interface Issue {
  severity: "error" | "warning" | "info";
  code: string;
  message: string;
}

/** Produit d'une sélection : le rôle (vin, accessoire) est défini côté agent. */
export interface SelectionItem {
  role: string;
  id: string;
  sku: string;
  name: string;
  brand: string;
  category: Category;
  price_cents: number;
  stock?: number;
  attributes: Attributes;
  /** Avis du sommelier (reranking LLM) : note d'accord 0-100, explication gustative, coup de cœur. */
  match_score?: number;
  sommelier_justification?: string;
  is_coup_de_coeur?: boolean;
}

export interface SelectionRow {
  label: string;
  items: SelectionItem[];
  total_cents: number;
  /** Indicateurs chiffrés de la sélection (nombre de bouteilles, budget…). */
  metrics: Record<string, unknown>;
  /** Détails propres au vin (accord mets-vins…). */
  details: Record<string, unknown>;
  compatible: boolean;
  within_budget: boolean;
  requirements_met?: boolean;
  issues: Issue[];
}

export interface BundleComparison {
  selections: SelectionRow[];
  recommended_index: number | null;
}

export interface AccessoryGroup {
  key: string;
  label: string;
  items: Product[];
}

export interface AccessoryUpsell {
  item_ids: string[];
  groups: AccessoryGroup[];
}

export interface CartItem {
  product_id: string;
  sku: string;
  name: string;
  quantity: number;
  unit_price_cents: number;
  line_total_cents: number;
}

export interface CartUpdate {
  cart_id: string;
  total_cents: number;
  items: CartItem[];
  compatibility_ok: boolean;
  issues: Issue[];
}

export interface StripeCheckout {
  cart_id: string;
  total_cents: number;
  currency: string;
  mode: string;
  checkout_url: string | null;
}

export type AgentEvent =
  | { event: "text_delta"; data: { text: string } }
  | { event: "ui_product_card"; data: Product }
  | { event: "ui_bundle_comparison"; data: BundleComparison }
  | { event: "ui_accessory_upsell"; data: AccessoryUpsell }
  | { event: "ui_cart_update"; data: CartUpdate }
  | { event: "ui_checkout_step"; data: { step: string | null } }
  | { event: "ui_stripe_checkout"; data: StripeCheckout }
  | { event: "done"; data: { blocked?: string } }
  | { event: "error"; data: { code?: string; message?: string } };

export type AgentEventName = AgentEvent["event"];

export const AGENT_EVENT_NAMES: readonly AgentEventName[] = [
  "text_delta",
  "ui_product_card",
  "ui_bundle_comparison",
  "ui_accessory_upsell",
  "ui_cart_update",
  "ui_checkout_step",
  "ui_stripe_checkout",
  "done",
  "error",
];
