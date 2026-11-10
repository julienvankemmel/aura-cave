import { type NextRequest, NextResponse } from "next/server";

import { isCartId, isProductId, isSameOrigin } from "@/lib/proxy";

export const runtime = "nodejs";
export const dynamic = "force-dynamic";

interface Params {
  params: Promise<{ cartId: string; productId: string }>;
}

/** Retire une ligne du panier côté Symfony (la clé interne reste côté serveur). */
export async function DELETE(request: NextRequest, { params }: Params): Promise<Response> {
  if (!isSameOrigin(request.headers.get("origin"), request.headers.get("host"))) {
    return NextResponse.json({ error: "forbidden_origin" }, { status: 403 });
  }
  const { cartId, productId } = await params;
  if (!isCartId(cartId) || !isProductId(productId)) return NextResponse.json({ error: "invalid_request" }, { status: 422 });

  const apiKey = process.env.INTERNAL_API_KEY;
  if (!apiKey) return NextResponse.json({ error: "proxy_not_configured" }, { status: 503 });

  const backendUrl = (process.env.BACKEND_URL ?? "http://localhost:8088").replace(/\/$/, "");
  try {
    const upstream = await fetch(`${backendUrl}/api/cart/${cartId}/items/${productId}`, {
      method: "DELETE",
      headers: { "X-Internal-Api-Key": apiKey },
      cache: "no-store",
    });
    if (upstream.status === 404) return NextResponse.json({ error: "item_not_in_cart" }, { status: 404 });
    if (!upstream.ok) return NextResponse.json({ error: "backend_error" }, { status: 502 });
    return NextResponse.json(await upstream.json(), { headers: { "Cache-Control": "no-store" } });
  } catch {
    return NextResponse.json({ error: "backend_unreachable" }, { status: 502 });
  }
}
