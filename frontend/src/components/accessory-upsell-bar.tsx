"use client";

import { Check, ShoppingBag } from "lucide-react";

import { Button } from "@/components/ui/button";
import type { AccessoryUpsell, Product } from "@/lib/types";

import { ProductCard } from "./product-card";

interface Props {
  data: AccessoryUpsell;
  inCartIds: Set<string>;
  disabled?: boolean;
  /** Sélection à laquelle s'appliquent ces accessoires (la sélection choisie peut différer). */
  forLabel?: string;
  selectionInCart?: boolean;
  onAddSelection?: () => void;
  onAdd: (p: Product) => void;
  onInfo: (p: Product) => void;
}

export function AccessoryUpsellBar({ data, inCartIds, disabled, forLabel, selectionInCart, onAddSelection, onAdd, onInfo }: Props) {
  const nonEmpty = data.groups.filter((g) => g.items.length > 0);
  return (
    <section className="w-full space-y-3" data-testid="accessory-upsell" aria-label="Accessoires compatibles">
      <div className="flex flex-wrap items-end justify-between gap-3 px-1">
        <div>
          <h3 className="font-display text-lg font-light tracking-tight">Accessoires compatibles</h3>
          <p className="text-xs text-muted-foreground">
            {forLabel ? `Pour ${forLabel}.` : "Sélectionnés d'après les caractéristiques exactes de votre choix."}
          </p>
        </div>
        {onAddSelection ? (
          <Button size="sm" variant={selectionInCart ? "secondary" : "default"} disabled={disabled || selectionInCart} onClick={onAddSelection}>
            {selectionInCart ? <Check /> : <ShoppingBag />} {selectionInCart ? "Sélection au panier" : "Ajouter la sélection au panier"}
          </Button>
        ) : null}
      </div>
      {nonEmpty.length === 0 ? <p className="px-1 text-sm text-muted-foreground">Aucun accessoire compatible trouvé.</p> : null}
      {nonEmpty.map((group) => (
        <div key={group.key} role="group" aria-label={group.label} className="space-y-1.5">
          <h4 className="px-1 text-[0.7rem] uppercase tracking-[0.16em] text-muted-foreground">{group.label}</h4>
          <div className="-mx-1 flex snap-x gap-3 overflow-x-auto px-1 pb-3">
            {group.items.map((p) => (
              <div key={p.id} className="snap-start">
                <ProductCard product={p} onInfo={onInfo} onAdd={disabled ? undefined : onAdd} inCart={inCartIds.has(p.id)} compact />
              </div>
            ))}
          </div>
        </div>
      ))}
    </section>
  );
}
