import { describe, expect, it } from "vitest";

import { readAgentEvents, SSEParser, toAgentEvent } from "./sse";
import type { AgentEvent } from "./types";

const encoder = new TextEncoder();

function streamOf(...chunks: (string | Uint8Array)[]): ReadableStream<Uint8Array> {
  return new ReadableStream({
    start(controller) {
      for (const c of chunks) controller.enqueue(typeof c === "string" ? encoder.encode(c) : c);
      controller.close();
    },
  });
}

async function collect(stream: ReadableStream<Uint8Array>): Promise<AgentEvent[]> {
  const events: AgentEvent[] = [];
  for await (const e of readAgentEvents(stream)) events.push(e);
  return events;
}

describe("SSEParser", () => {
  it("parses a complete event", () => {
    const parser = new SSEParser();
    expect(parser.feed('event: text_delta\ndata: {"text":"Bonjour"}\n\n')).toEqual([
      { event: "text_delta", data: '{"text":"Bonjour"}' },
    ]);
  });

  it("handles chunks split at arbitrary positions", () => {
    const payload = 'event: text_delta\ndata: {"text":"é"}\n\nevent: done\ndata: {}\n\n';
    for (let cut = 1; cut < payload.length; cut++) {
      const parser = new SSEParser();
      const events = [...parser.feed(payload.slice(0, cut)), ...parser.feed(payload.slice(cut))];
      expect(events.map((e) => e.event)).toEqual(["text_delta", "done"]);
    }
  });

  it("supports CRLF and CR line endings, even split between chunks", () => {
    const parser = new SSEParser();
    const out = [...parser.feed("event: done\r"), ...parser.feed("\ndata: {}\r\n\r"), ...parser.feed("\n")];
    expect(out).toEqual([{ event: "done", data: "{}" }]);
    // un "\r" en toute fin de flux peut précéder un "\n" : il est retenu jusqu'au flush
    const lone = new SSEParser();
    expect([...lone.feed("event: done\rdata: {}\r\r"), ...lone.flush()]).toEqual([{ event: "done", data: "{}" }]);
  });

  it("joins multi-line data with newlines and defaults the event name", () => {
    const parser = new SSEParser();
    expect(parser.feed("data: a\ndata: b\n\n")).toEqual([{ event: "message", data: "a\nb" }]);
  });

  it("ignores comments, id and retry fields", () => {
    const parser = new SSEParser();
    expect(parser.feed(": keep-alive\nid: 7\nretry: 1000\nevent: done\ndata: {}\n\n")).toEqual([
      { event: "done", data: "{}" },
    ]);
  });

  it("strips only one leading space of the value", () => {
    expect(new SSEParser().feed("data:  x\n\n")).toEqual([{ event: "message", data: " x" }]);
  });

  it("does not emit events without data and resets the event name", () => {
    const parser = new SSEParser();
    expect(parser.feed("event: done\n\n")).toEqual([]);
    expect(parser.feed("data: {}\n\n")).toEqual([{ event: "message", data: "{}" }]);
  });

  it("flush dispatches a trailing event without final blank line", () => {
    const parser = new SSEParser();
    expect(parser.feed("event: done\ndata: {}")).toEqual([]);
    expect(parser.flush()).toEqual([{ event: "done", data: "{}" }]);
  });
});

describe("toAgentEvent", () => {
  it("accepts the typed events emitted by the agent", () => {
    const names = [
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
    for (const name of names) {
      const data = name === "text_delta" ? { text: "x" } : { a: 1 };
      expect(toAgentEvent({ event: name, data: JSON.stringify(data) })?.event).toBe(name);
    }
  });

  it("rejects unknown events, invalid JSON, non-object payloads and bad text_delta", () => {
    expect(toAgentEvent({ event: "unknown", data: "{}" })).toBeNull();
    expect(toAgentEvent({ event: "done", data: "{oops" })).toBeNull();
    expect(toAgentEvent({ event: "done", data: "[1]" })).toBeNull();
    expect(toAgentEvent({ event: "done", data: "null" })).toBeNull();
    expect(toAgentEvent({ event: "text_delta", data: '{"text":3}' })).toBeNull();
  });
});

describe("readAgentEvents", () => {
  it("yields typed events in order from a byte stream", async () => {
    const events = await collect(
      streamOf(
        'event: ui_checkout_step\ndata: {"step":"AWAITING_FULL_NAME"}\n\n',
        'event: text_delta\ndata: {"text":"Salut "}\n\n',
        "event: done\ndata: {}\n\n",
      ),
    );
    expect(events.map((e) => e.event)).toEqual(["ui_checkout_step", "text_delta", "done"]);
  });

  it("decodes multi-byte UTF-8 characters split across chunks", async () => {
    const bytes = encoder.encode('event: text_delta\ndata: {"text":"grand cru 🍷"}\n\n');
    const middle = bytes.indexOf(0xa9) + 0; // milieu de "é" (0xC3 0xA9)
    const events = await collect(streamOf(bytes.slice(0, middle), bytes.slice(middle)));
    expect(events).toEqual([{ event: "text_delta", data: { text: "grand cru 🍷" } }]);
  });

  it("skips malformed events and keeps going", async () => {
    const events = await collect(streamOf("event: done\ndata: {broken\n\n", 'event: text_delta\ndata: {"text":"ok"}\n\n'));
    expect(events).toEqual([{ event: "text_delta", data: { text: "ok" } }]);
  });

  it("emits a final event that lacks the trailing blank line", async () => {
    const events = await collect(streamOf("event: done\ndata: {}"));
    expect(events).toEqual([{ event: "done", data: {} }]);
  });
});
