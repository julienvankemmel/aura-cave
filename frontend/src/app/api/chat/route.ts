import { type NextRequest, NextResponse } from "next/server";

import { isSameOrigin, MAX_BODY_BYTES, sanitizeChatBody } from "@/lib/proxy";

export const runtime = "nodejs";
export const dynamic = "force-dynamic";

/**
 * Relais SSE vers l'agent Python. La clé `X-Internal-Api-Key` est lue ici (variable serveur,
 * jamais préfixée NEXT_PUBLIC_) et n'atteint jamais le navigateur.
 */
export async function POST(request: NextRequest): Promise<Response> {
  if (!isSameOrigin(request.headers.get("origin"), request.headers.get("host"))) {
    return NextResponse.json({ error: "forbidden_origin" }, { status: 403 });
  }

  const apiKey = process.env.INTERNAL_API_KEY;
  if (!apiKey) return NextResponse.json({ error: "proxy_not_configured" }, { status: 503 });

  const raw = await request.text();
  if (raw.length > MAX_BODY_BYTES) return NextResponse.json({ error: "payload_too_large" }, { status: 413 });

  let parsed: unknown;
  try {
    parsed = JSON.parse(raw);
  } catch {
    return NextResponse.json({ error: "invalid_json" }, { status: 400 });
  }
  const body = sanitizeChatBody(parsed);
  if (!body) return NextResponse.json({ error: "invalid_request" }, { status: 422 });

  const agentUrl = (process.env.AGENT_URL ?? "http://localhost:8001").replace(/\/$/, "");
  let upstream: Response;
  try {
    upstream = await fetch(`${agentUrl}/api/chat/stream`, {
      method: "POST",
      headers: { "Content-Type": "application/json", "X-Internal-Api-Key": apiKey },
      body: JSON.stringify(body),
      signal: request.signal,
      cache: "no-store",
    });
  } catch {
    return NextResponse.json({ error: "agent_unreachable" }, { status: 502 });
  }

  if (!upstream.ok || !upstream.body) {
    // Le détail de l'agent (dont un éventuel 401) n'est jamais relayé tel quel.
    return NextResponse.json({ error: "agent_error" }, { status: upstream.status === 422 ? 422 : 502 });
  }

  return new Response(upstream.body, {
    headers: {
      "Content-Type": "text/event-stream; charset=utf-8",
      "Cache-Control": "no-cache, no-transform",
      "X-Accel-Buffering": "no",
    },
  });
}
