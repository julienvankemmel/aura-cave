"use client";

import { Check, Info, Plus } from "lucide-react";

import { GaugeRow } from "@/components/gauge";
import { ProductVisual } from "@/components/product-visual";
import { Slider } from "@/components/slider";
import { Button } from "@/components/ui/button";
import { badgeValue, categoryLabel, gaugeReadings, headline } from "@/lib/attributes";
import type { Product } from "@/lib/types";
import { cn, formatEuros } from "@/lib/utils";

interface ProductCardProps {
  product: Product;
  onInfo: (p: Product) => void;
  onAdd?: (p: Product) => void;
  inCart?: boolean;
  /** Variante étroite pour les listes denses (accessoires). */
  compact?: boolean;
}

/**
 * Carte produit en deux colonnes : l'image (ou son visuel de repli) à gauche ; à droite le descriptif (nom, origine,
 * prix, stock), puis les jauges des notes de dégustation.
 */
export function ProductCard({ product, onInfo, onAdd, inCart, compact }: ProductCardProps) {
  const badge = badgeValue(product);
  const outOfStock = product.stock !== undefined && product.stock <= 0;
  const lowStock = product.stock !== undefined && product.stock > 0 && product.stock <= 5;
  const gauges = gaugeReadings([product.attributes]);
  const sub = headline(product);
  return (
    <article className={cn("glass flex h-full flex-col gap-4 rounded-2xl p-4", compact ? "w-[19rem] shrink-0" : "sm:p-5")} data-testid="product-card">
      <div className={cn("grid gap-3", compact ? "grid-cols-[5.5rem_minmax(0,1fr)]" : "grid-cols-[6.5rem_minmax(0,1fr)] sm:grid-cols-[8.5rem_minmax(0,1fr)] sm:gap-4")}>
        <ProductVisual items={[product]} className={cn("h-full", compact ? "min-h-32" : "min-h-44")} />
        <div className="flex min-w-0 flex-col gap-3">
          <div className="space-y-1.5">
            <div className="flex items-center justify-between gap-2 text-[0.65rem] uppercase tracking-[0.14em] text-muted-foreground">
              <span>{categoryLabel(product.category)}</span>
              {badge ? <span className="truncate">{badge}</span> : null}
            </div>
            <h4 className="line-clamp-3 text-[0.95rem] font-medium leading-snug tracking-tight">{product.name}</h4>
            {sub ? <p className="text-xs text-muted-foreground">{sub}</p> : null}
            <p className="font-display text-xl font-light tracking-tight">{formatEuros(product.price_cents)}</p>
            {outOfStock ? <p className="text-xs text-warn">Épuisé</p> : lowStock ? <p className="text-xs text-warn">{product.stock} restant{product.stock === 1 ? "" : "s"}</p> : null}
          </div>
          {gauges.length > 0 ? (
            <div className="mt-auto border-t border-border pt-3">
              <GaugeRow readings={gauges} />
            </div>
          ) : null}
        </div>
      </div>
      <div className="mt-auto flex gap-1.5">
        <Button size="sm" variant="ghost" onClick={() => onInfo(product)} aria-label={`Plus d'infos sur ${product.name}`}>
          <Info /> Détails
        </Button>
        {onAdd ? (
          <Button size="sm" variant={inCart ? "secondary" : "outline"} className="flex-1" disabled={inCart || outOfStock} onClick={() => onAdd(product)}>
            {inCart ? <Check /> : <Plus />} {inCart ? "Ajouté" : outOfStock ? "Épuisé" : "Ajouter"}
          </Button>
        ) : null}
      </div>
    </article>
  );
}

interface CarouselProps {
  products: Product[];
  onInfo: (p: Product) => void;
  onAdd?: (p: Product) => void;
}

/** Produits suggérés dans le même carrousel que les sélections. */
export function ProductCarousel({ products, onInfo, onAdd }: CarouselProps) {
  return (
    <Slider label="Produits suggérés" itemLabel="Produit">
      {products.map((p) => (
        <ProductCard key={p.id} product={p} onInfo={onInfo} onAdd={onAdd} />
      ))}
    </Slider>
  );
}
