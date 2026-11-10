"use client";

import { CheckCircle2, Lock, ShieldCheck, TriangleAlert, X, XCircle } from "lucide-react";

import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Progress } from "@/components/ui/progress";
import { cartAggregates, validationState, type ChatState } from "@/lib/chat-reducer";
import { WINE } from "@/lib/wine";
import type { CartItem } from "@/lib/types";
import { cn, formatEuros } from "@/lib/utils";

interface Props {
  state: ChatState;
  onBudgetChange: (cents: number) => void;
  onCheckout: () => void;
  onRemove: (item: CartItem) => void;
}

/** Contenu du tiroir Panier : articles, prix, total, compatibilité et accès à la commande sécurisée. */
export function CartPanel({ state, onBudgetChange, onCheckout, onRemove }: Props) {
  const { shop } = state;
  const items = shop.cart?.items ?? [];
  const total = shop.cart?.total_cents ?? 0;
  const count = items.reduce((n, i) => n + i.quantity, 0);
  const ratio = shop.budgetCents > 0 ? total / shop.budgetCents : 0;
  const aggregates = cartAggregates(shop);
  const validation = validationState(shop);

  // Groupes du panier ; un produit hors des catégories listées tombe dans le groupe de repli (le dernier).
  const fallback = WINE.cartGroups.at(-1);
  const groupKeyOf = (productId: string): string | undefined => {
    const category = shop.catalog[productId]?.category;
    return WINE.cartGroups.find((g) => category !== undefined && g.categories?.includes(category))?.key ?? fallback?.key;
  };
  const grouped = WINE.cartGroups
    .map((g) => ({ ...g, items: items.filter((i) => groupKeyOf(i.product_id) === g.key) }))
    .filter((g) => g.items.length > 0);

  if (items.length === 0) {
    return (
      <div className="flex flex-1 flex-col items-center justify-center gap-2 py-16 text-center" data-testid="cart-panel">
        <p className="font-display text-xl font-light tracking-tight">Votre panier est vide</p>
        <p className="max-w-[16rem] text-sm text-muted-foreground">
          Décrivez votre besoin dans la conversation, puis ajoutez la sélection ou les accessoires de votre choix.
        </p>
      </div>
    );
  }

  return (
    <div className="flex flex-1 flex-col gap-6" data-testid="cart-panel">
      <div className="space-y-5" data-testid="cart-items">
        {grouped.map((group) => (
          <section key={group.key} aria-label={group.label} className="space-y-2">
            <h3 className="text-[0.7rem] uppercase tracking-[0.16em] text-muted-foreground">{group.label}</h3>
            <ul className="divide-y divide-border">
              {group.items.map((item) => (
                <Line key={item.product_id} item={item} onRemove={onRemove} />
              ))}
            </ul>
          </section>
        ))}
      </div>

      <div className="mt-auto space-y-4">
        <dl className="space-y-1.5 border-t border-border pt-4 text-sm">
          {aggregates.map((a) => (
            <div key={a.label} className="flex justify-between text-muted-foreground">
              <dt>{a.label}</dt>
              <dd>{a.value}</dd>
            </div>
          ))}
          <div className="flex items-baseline justify-between">
            <dt className="font-medium">Total ({count} article{count > 1 ? "s" : ""})</dt>
            <dd className={cn("font-display text-3xl font-light tracking-tight", ratio > 1 && "text-destructive")} data-testid="cart-total">
              {formatEuros(total)}
            </dd>
          </div>
        </dl>

        <div data-testid="validation" className="space-y-1.5">
          {validation.status !== "idle" ? (
            <>
              <Badge variant={validation.status === "ok" ? "ok" : validation.status === "warning" ? "warn" : "destructive"}>
                {validation.status === "ok" ? <CheckCircle2 aria-hidden /> : validation.status === "warning" ? <TriangleAlert aria-hidden /> : <XCircle aria-hidden />}
                {validation.status === "ok" ? "Compatibilité vérifiée" : validation.status === "warning" ? "Compatible, avec alertes" : "Incompatibilité détectée"}
              </Badge>
              {validation.messages.map((m) => (
                <p key={m} className="text-xs leading-relaxed text-muted-foreground">
                  {m}
                </p>
              ))}
            </>
          ) : null}
        </div>

        <div className="space-y-1.5">
          <div className="flex items-center justify-between text-xs text-muted-foreground">
            <span>Budget</span>
            <label className="flex items-center gap-1">
              <Input
                aria-label="Budget maximum en euros"
                type="number"
                min={0}
                step={100}
                className="h-7 w-20 text-right text-xs"
                value={Math.round(shop.budgetCents / 100)}
                onChange={(e) => onBudgetChange(Number(e.target.value || 0) * 100)}
              />
              €
            </label>
          </div>
          <Progress
            label="Budget consommé"
            value={total}
            max={shop.budgetCents}
            indicatorClassName={ratio > 1 ? "bg-destructive" : ratio > 0.9 ? "bg-warn" : "bg-primary"}
          />
        </div>

        <Button size="lg" className="w-full" disabled={shop.checkout !== null} onClick={onCheckout}>
          {shop.checkout ? <ShieldCheck /> : <Lock />} {shop.checkout ? "Commande confirmée" : "Commander"}
        </Button>
        <p className="text-center text-[0.7rem] leading-relaxed text-muted-foreground">
          Livraison sécurisée : vos coordonnées sont chiffrées dans votre navigateur et jamais transmises à l&apos;IA.
        </p>
      </div>
    </div>
  );
}

function Line({ item, onRemove }: { item: CartItem; onRemove: (item: CartItem) => void }) {
  return (
    <li className="flex items-start justify-between gap-3 py-3">
      <div className="min-w-0">
        <p className="text-sm font-medium leading-snug tracking-tight">{item.name}</p>
        <p className="mt-0.5 text-xs text-muted-foreground">
          {item.quantity} × {formatEuros(item.unit_price_cents)}
        </p>
      </div>
      <div className="flex shrink-0 items-start gap-1.5">
        <p className="text-sm tabular-nums">{formatEuros(item.line_total_cents)}</p>
        <button
          type="button"
          onClick={() => onRemove(item)}
          aria-label={`Retirer ${item.name} du panier`}
          className="grid size-5 place-items-center rounded-full text-muted-foreground transition-colors hover:bg-accent hover:text-foreground focus-visible:ring-2 focus-visible:ring-ring"
        >
          <X className="size-3.5" />
        </button>
      </div>
    </li>
  );
}
