/** Tunnel de commande conversationnel : la saisie brute va à Symfony via `/api/checkout/*`, jamais à l'agent. */

export const CHECKOUT_PLACEHOLDERS = ["[CUSTOMER_NAME]", "[SHIPPING_ADDRESS]", "[CUSTOMER_PHONE]", "[CUSTOMER_EMAIL]"] as const;
export type CheckoutPlaceholder = (typeof CHECKOUT_PLACEHOLDERS)[number];

export interface CheckoutTurn {
  accepted: boolean;
  /** Étape après traitement (AWAITING_ADDRESS… CONFIRMED). */
  step: string;
  /** Seul texte transmis ensuite à l'agent : un jeton d'anonymisation ou `[INVALID_INPUT]`. */
  llm_message: string;
  order: { reference: string; total_cents: number } | null;
}

export class CheckoutApiError extends Error {
  constructor(readonly status: number) {
    super(`Checkout request failed (${status})`);
  }
}

export const isPlaceholder = (value: string): value is CheckoutPlaceholder =>
  (CHECKOUT_PLACEHOLDERS as readonly string[]).includes(value);

export async function postCheckoutMessage(cartId: string, text: string, fetchImpl: typeof fetch = fetch): Promise<CheckoutTurn> {
  const response = await fetchImpl("/api/checkout/message", {
    method: "POST",
    headers: { "Content-Type": "application/json" },
    body: JSON.stringify({ cart_id: cartId, text }),
  });
  if (!response.ok) throw new CheckoutApiError(response.status);
  const data = (await response.json()) as Partial<CheckoutTurn>;
  if (typeof data.accepted !== "boolean" || typeof data.step !== "string" || typeof data.llm_message !== "string") {
    throw new CheckoutApiError(502);
  }
  return { accepted: data.accepted, step: data.step, llm_message: data.llm_message, order: data.order ?? null };
}

export async function cancelCheckout(cartId: string, fetchImpl: typeof fetch = fetch): Promise<void> {
  const response = await fetchImpl("/api/checkout/cancel", {
    method: "POST",
    headers: { "Content-Type": "application/json" },
    body: JSON.stringify({ cart_id: cartId }),
  });
  if (!response.ok) throw new CheckoutApiError(response.status);
}

/**
 * Affichage local uniquement : remplace les jetons du texte de l'assistant par ce que le client a lui-même saisi dans ce
 * navigateur. Les valeurs ne sont ni persistées ni renvoyées à un service.
 */
export function hydratePlaceholders(text: string, values: Partial<Record<CheckoutPlaceholder, string>>): string {
  return CHECKOUT_PLACEHOLDERS.reduce((out, token) => {
    const value = values[token];
    return value ? out.split(token).join(value) : out;
  }, text);
}

export type TokenSegment = string | { token: CheckoutPlaceholder; value: string };

/** Comme `hydratePlaceholders`, mais garde la trace des valeurs substituées (pour les signaler à l'écran). */
export function splitPlaceholders(text: string, values: Partial<Record<CheckoutPlaceholder, string>>): TokenSegment[] {
  const known = CHECKOUT_PLACEHOLDERS.filter((token) => values[token]);
  if (known.length === 0) return [text];
  const pattern = new RegExp(`(${known.map((t) => t.replace(/[[\]]/g, "\\$&")).join("|")})`);
  return text
    .split(pattern)
    .filter((part) => part !== "")
    .map((part) => (isPlaceholder(part) && values[part] ? { token: part, value: values[part] as string } : part));
}
