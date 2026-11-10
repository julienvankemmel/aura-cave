import { readAgentEvents } from "./sse";
import type { AgentEvent } from "./types";

export interface ChatRequest {
  cart_id: string;
  message: string;
}

export class ChatHttpError extends Error {
  constructor(readonly status: number) {
    super(`Chat request failed (${status})`);
  }
}

export async function* streamChat(
  request: ChatRequest,
  signal?: AbortSignal,
  fetchImpl: typeof fetch = fetch,
): AsyncGenerator<AgentEvent> {
  const response = await fetchImpl("/api/chat", {
    method: "POST",
    headers: { "Content-Type": "application/json", Accept: "text/event-stream" },
    body: JSON.stringify(request),
    signal,
  });
  if (!response.ok || !response.body) throw new ChatHttpError(response.status);
  yield* readAgentEvents(response.body);
}

const STORAGE_KEY = "aura.cart_id";

/** UUID v4 de session (persisté dans sessionStorage) : identifie le panier de l'univers côté Symfony. */
export function getCartId(): string {
  try {
    const stored = window.sessionStorage.getItem(STORAGE_KEY);
    if (stored) return stored;
    const id = crypto.randomUUID();
    window.sessionStorage.setItem(STORAGE_KEY, id);
    return id;
  } catch {
    return crypto.randomUUID();
  }
}

export function resetCartId(): string {
  try {
    window.sessionStorage.removeItem(STORAGE_KEY);
  } catch {
    /* sessionStorage indisponible */
  }
  return getCartId();
}
