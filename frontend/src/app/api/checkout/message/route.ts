import { type NextRequest, NextResponse } from "next/server";

import { isSameOrigin, MAX_BODY_BYTES, sanitizeCheckoutBody } from "@/lib/proxy";

export const runtime = "nodejs";
export const dynamic = "force-dynamic";

interface BackendTurn {
  accepted?: unknown;
  step?: unknown;
  llm_message?: unknown;
  order?: { reference?: unknown; total_cents?: unknown } | null;
}

/**
 * Saisie d'une coordonnée de livraison dans le chat. La valeur brute va directement à Symfony (clé interne côté serveur) :
 * elle ne passe jamais par l'agent ni par le LLM, qui ne reçoivent que le jeton d'anonymisation renvoyé ici.
 * La consigne destinée au LLM reste côté serveur (l'agent la relit lui-même auprès de Symfony).
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
  const body = sanitizeCheckoutBody(parsed, true);
  if (!body?.text) return NextResponse.json({ error: "invalid_request" }, { status: 422 });

  const backendUrl = (process.env.BACKEND_URL ?? "http://localhost:8088").replace(/\/$/, "");
  try {
    const upstream = await fetch(`${backendUrl}/api/checkout/${body.cart_id}/message`, {
      method: "POST",
      headers: { "Content-Type": "application/json", "X-Internal-Api-Key": apiKey },
      body: JSON.stringify({ text: body.text }),
      cache: "no-store",
    });
    if (upstream.status === 409) return NextResponse.json({ error: "checkout_not_started" }, { status: 409 });
    if (!upstream.ok) return NextResponse.json({ error: "backend_error" }, { status: 502 });
    const turn = (await upstream.json()) as BackendTurn;
    if (typeof turn.accepted !== "boolean" || typeof turn.step !== "string" || typeof turn.llm_message !== "string") {
      return NextResponse.json({ error: "backend_error" }, { status: 502 });
    }
    return NextResponse.json(
      {
        accepted: turn.accepted,
        step: turn.step,
        llm_message: turn.llm_message,
        order:
          turn.order && typeof turn.order.reference === "string" && typeof turn.order.total_cents === "number"
            ? { reference: turn.order.reference, total_cents: turn.order.total_cents }
            : null,
      },
      { headers: { "Cache-Control": "no-store" } },
    );
  } catch {
    return NextResponse.json({ error: "backend_unreachable" }, { status: 502 });
  }
}
