"use client";

import { Package } from "lucide-react";

import { bottleColor, imageUrl } from "@/lib/attributes";
import { cn } from "@/lib/utils";

export interface VisualItem {
  name: string;
  category: string;
  attributes: Record<string, unknown>;
}

/** Visuel de bouteille par défaut (placeholder neutre), utilisé quand le produit n'a pas d'image propre. */
export const DEFAULT_BOTTLE_IMAGE = "/images/wine-placeholder.svg";

function ItemVisual({ item, fill }: { item: VisualItem; fill: boolean }) {
  const src = imageUrl(item.attributes);
  if (src) {
    // eslint-disable-next-line @next/next/no-img-element -- les hôtes d'images autorisés relèvent de la CSP, pas de next/image
    return <img src={src} alt={item.name} loading="lazy" className="h-full w-auto max-w-full object-contain" />;
  }
  if (bottleColor(item.category, item.attributes)) {
    /* eslint-disable @next/next/no-img-element -- image locale statique, rendue à la taille de la colonne */
    if (fill) return <img src={DEFAULT_BOTTLE_IMAGE} alt="" loading="lazy" className="size-full object-cover" />;
    return <img src={DEFAULT_BOTTLE_IMAGE} alt="" loading="lazy" className="h-full w-auto max-w-full rounded-lg object-cover" style={{ aspectRatio: "3 / 4" }} />;
    /* eslint-enable @next/next/no-img-element */
  }
  return <Package className="size-12 text-muted-foreground" strokeWidth={1.2} aria-hidden />;
}

/** Image du produit (`image_url`), sinon le visuel de bouteille par défaut (vins) ou une icône ; jusqu'à 3 produits côte à côte. */
export function ProductVisual({ items, className }: { items: VisualItem[]; className?: string }) {
  // Plusieurs vins sans image propre : le visuel par défaut n'est affiché qu'une fois.
  let defaultSeen = false;
  const shown = items
    .filter((item) => {
      if (imageUrl(item.attributes) || !bottleColor(item.category, item.attributes)) return true;
      if (defaultSeen) return false;
      defaultSeen = true;
      return true;
    })
    .slice(0, 3);
  const label = items.map((i) => i.name).join(", ");
  const [only] = shown;
  // Un seul vin sans image propre : le visuel par défaut remplit toute la colonne.
  const fill = shown.length === 1 && !!only && !imageUrl(only.attributes) && !!bottleColor(only.category, only.attributes);
  return (
    <div
      className={cn("flex h-40 items-end justify-center rounded-xl bg-muted/60", fill ? "overflow-hidden" : "px-2 py-3", className)}
      role="img"
      aria-label={label}
      data-testid="product-visual"
    >
      {shown.map((item, i) => (
        <div key={`${item.name}-${i}`} className={cn("flex h-full items-end", shown.length === 1 ? "w-full" : i > 0 ? "-ml-3" : "")} style={{ zIndex: shown.length - i }}>
          <div className={cn("flex items-end justify-center", shown.length === 1 ? "h-full w-full" : "h-[88%]")}>
            <ItemVisual item={item} fill={fill} />
          </div>
        </div>
      ))}
    </div>
  );
}
