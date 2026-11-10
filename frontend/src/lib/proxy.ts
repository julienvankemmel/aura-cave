/** Helpers purs du proxy `/api/chat` (testés) : la clé interne n'existe que côté serveur. */

export const MAX_BODY_BYTES = 16 * 1024;
const UUID_V4 = /^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i;

export interface ChatBody {
  cart_id: string;
  message?: string;
}

/** Ne laisse passer que les champs connus, correctement typés ; sinon `null`. */
export function sanitizeChatBody(raw: unknown): ChatBody | null {
  if (typeof raw !== "object" || raw === null || Array.isArray(raw)) return null;
  const input = raw as Record<string, unknown>;
  const { cart_id, message } = input;
  if (Object.keys(input).some((k) => !["cart_id", "message"].includes(k))) return null;
  if (typeof cart_id !== "string" || !UUID_V4.test(cart_id)) return null;
  const body: ChatBody = { cart_id };
  if (typeof message !== "string" || message.length > 2000 || !message.trim()) return null;
  body.message = message;
  return body;
}

export interface CheckoutBody {
  cart_id: string;
  text?: string;
}

/**
 * Corps des routes `/api/checkout/*` : `text` est la saisie brute du client (donnée personnelle) ; elle ne va qu'à
 * Symfony, jamais à l'agent.
 */
export function sanitizeCheckoutBody(raw: unknown, requireText: boolean): CheckoutBody | null {
  if (typeof raw !== "object" || raw === null || Array.isArray(raw)) return null;
  const input = raw as Record<string, unknown>;
  if (Object.keys(input).some((k) => !["cart_id", "text"].includes(k))) return null;
  if (typeof input.cart_id !== "string" || !UUID_V4.test(input.cart_id)) return null;
  if (!requireText) return input.text === undefined ? { cart_id: input.cart_id } : null;
  if (typeof input.text !== "string" || !input.text.trim() || input.text.length > 500) return null;
  return { cart_id: input.cart_id, text: input.text };
}

/** Protection CSRF basique : si un en-tête Origin est présent, il doit correspondre à l'hôte. */
export function isSameOrigin(origin: string | null, host: string | null): boolean {
  if (origin === null) return true; // requêtes non-navigateur (curl) ; l'auth reste côté agent
  try {
    return host !== null && new URL(origin).host === host;
  } catch {
    return false;
  }
}

const UUID_ANY = /^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i;

export function isCartId(value: string): boolean {
  return UUID_V4.test(value);
}

export function isProductId(value: string): boolean {
  return UUID_ANY.test(value);
}
