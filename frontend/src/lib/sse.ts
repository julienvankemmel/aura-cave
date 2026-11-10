import { AGENT_EVENT_NAMES, type AgentEvent, type AgentEventName } from "./types";

export interface RawSSE {
  event: string;
  data: string;
}

/** Parseur SSE incrémental : tolère les chunks coupés n'importe où, CRLF/CR, data multi-lignes. */
export class SSEParser {
  private buffer = "";
  private event = "";
  private data: string[] = [];

  feed(chunk: string): RawSSE[] {
    this.buffer += chunk;
    const out: RawSSE[] = [];
    // Une ligne n'est complète que si elle est suivie d'un séparateur ; un "\r" final peut précéder un "\n".
    const lines = this.buffer.split(/\r\n|\n|\r(?!$)/);
    this.buffer = lines.pop() ?? "";
    for (const line of lines) {
      const dispatched = this.consume(line);
      if (dispatched) out.push(dispatched);
    }
    return out;
  }

  /** À appeler en fin de flux : valide une dernière ligne sans saut de ligne et l'événement en cours. */
  flush(): RawSSE[] {
    const out: RawSSE[] = [];
    if (this.buffer !== "") {
      const dispatched = this.consume(this.buffer.replace(/\r$/, ""));
      this.buffer = "";
      if (dispatched) out.push(dispatched);
    }
    const last = this.consume("");
    if (last) out.push(last);
    return out;
  }

  private consume(line: string): RawSSE | null {
    if (line === "") {
      if (this.data.length === 0) {
        this.event = "";
        return null;
      }
      const raw = { event: this.event || "message", data: this.data.join("\n") };
      this.event = "";
      this.data = [];
      return raw;
    }
    if (line.startsWith(":")) return null; // commentaire / keep-alive
    const colon = line.indexOf(":");
    const field = colon === -1 ? line : line.slice(0, colon);
    let value = colon === -1 ? "" : line.slice(colon + 1);
    if (value.startsWith(" ")) value = value.slice(1);
    if (field === "event") this.event = value;
    else if (field === "data") this.data.push(value);
    return null; // id / retry ignorés
  }
}

const KNOWN = new Set<string>(AGENT_EVENT_NAMES);

/** Valide un événement brut : nom connu et charge utile JSON de type objet, sinon ignoré. */
export function toAgentEvent(raw: RawSSE): AgentEvent | null {
  if (!KNOWN.has(raw.event)) return null;
  let data: unknown;
  try {
    data = JSON.parse(raw.data);
  } catch {
    return null;
  }
  if (typeof data !== "object" || data === null || Array.isArray(data)) return null;
  if (raw.event === "text_delta" && typeof (data as { text?: unknown }).text !== "string") return null;
  return { event: raw.event as AgentEventName, data } as AgentEvent;
}

export async function* readAgentEvents(body: ReadableStream<Uint8Array>): AsyncGenerator<AgentEvent> {
  const reader = body.getReader();
  const decoder = new TextDecoder("utf-8");
  const parser = new SSEParser();
  try {
    for (;;) {
      const { done, value } = await reader.read();
      if (done) break;
      for (const raw of parser.feed(decoder.decode(value, { stream: true }))) {
        const event = toAgentEvent(raw);
        if (event) yield event;
      }
    }
    for (const raw of [...parser.feed(decoder.decode()), ...parser.flush()]) {
      const event = toAgentEvent(raw);
      if (event) yield event;
    }
  } finally {
    reader.releaseLock();
  }
}
