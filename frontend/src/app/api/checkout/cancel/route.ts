import { type NextRequest, NextResponse } from "next/server";

import { isSameOrigin, MAX_BODY_BYTES, sanitizeCheckoutBody } from "@/lib/proxy";

export const runtime = "nodejs";
export const dynamic = "force-dynamic";

/** Abandonne le tunnel de commande : Symfony efface les coordonnées déjà collectées. */
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
  const body = sanitizeCheckoutBody(parsed, false);
  if (!body) return NextResponse.json({ error: "invalid_request" }, { status: 422 });

  const backendUrl = (process.env.BACKEND_URL ?? "http://localhost:8088").replace(/\/$/, "");
  try {
    const upstream = await fetch(`${backendUrl}/api/checkout/${body.cart_id}/cancel`, {
      method: "POST",
      headers: { "Content-Type": "application/json", "X-Internal-Api-Key": apiKey },
      body: "{}",
      cache: "no-store",
    });
    if (!upstream.ok) return NextResponse.json({ error: "backend_error" }, { status: 502 });
    return NextResponse.json({ active: false }, { headers: { "Cache-Control": "no-store" } });
  } catch {
    return NextResponse.json({ error: "backend_unreachable" }, { status: 502 });
  }
}
