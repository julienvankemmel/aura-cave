"use client";

import { motion } from "framer-motion";
import { Check, Info, TriangleAlert, Wine } from "lucide-react";

import { GaugeRow } from "@/components/gauge";
import { ProductVisual } from "@/components/product-visual";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { badgeValue, gaugeReadings, headline, roleLabel, selectionRows } from "@/lib/attributes";
import { WINE } from "@/lib/wine";
import type { SelectionItem, SelectionRow } from "@/lib/types";
import { cn, formatEuros } from "@/lib/utils";

interface Props {
  selection: SelectionRow;
  index: number;
  recommended: boolean;
  chosen: boolean;
  disabled?: boolean;
  onChoose: () => void;
  onInfo: (item: SelectionItem) => void;
}

function Row({ label, children }: { label: string; children: React.ReactNode }) {
  return (
    <div className="flex items-baseline justify-between gap-3 border-t border-border py-1.5 text-sm first:border-t-0">
      <dt className="text-xs text-muted-foreground">{label}</dt>
      <dd className="text-right font-medium tracking-tight">{children}</dd>
    </div>
  );
}

/**
 * Carte d'une sélection, en deux colonnes : à gauche l'image (ou le visuel de repli), à droite le descriptif
 * (produits, prix, accord) puis, en dessous, les jauges des notes de dégustation.
 */
export function SelectionCard({ selection, index, recommended, chosen, disabled, onChoose, onInfo }: Props) {
  const noun = WINE.noun;
  const products = selection.items.filter((i) => i.role !== "accessory");
  const featured = products.length > 0 ? products : selection.items;
  const warnings = selection.issues.filter((i) => i.severity !== "info");
  const info = selection.issues.find((i) => i.severity === "info");
  const rows = selectionRows(selection.metrics ?? {}, selection.details ?? {});
  const gauges = gaugeReadings(featured.map((i) => i.attributes));
  const notes = selection.items.filter((i) => i.sommelier_justification);
  const nounLabel = noun.singular.charAt(0).toUpperCase() + noun.singular.slice(1);

  return (
    <motion.article
      initial={{ opacity: 0, y: 10 }}
      animate={{ opacity: 1, y: 0 }}
      transition={{ delay: index * 0.07, duration: 0.4, ease: "easeOut" }}
      className={cn("glass flex h-full flex-col gap-4 rounded-2xl p-4 transition-shadow sm:p-5", chosen && "ring-1 ring-foreground/60")}
      data-testid="selection-card"
    >
      <header className="flex items-center justify-between">
        <span className="text-[0.7rem] uppercase tracking-[0.16em] text-muted-foreground">{selection.label}</span>
        {recommended ? <Badge variant="default">{noun.recommended}</Badge> : null}
      </header>

      <div className="grid grid-cols-[7.5rem_minmax(0,1fr)] gap-3 sm:grid-cols-[11rem_minmax(0,1fr)] sm:gap-4">
        <ProductVisual items={featured} className="h-full min-h-60" />

        <div className="flex min-w-0 flex-col gap-3">
          <div className="space-y-3" data-testid="selection-description">
            {selection.items.map((item) => (
              <button
                key={item.id}
                type="button"
                onClick={() => onInfo(item)}
                aria-label={`Détails de ${item.name}`}
                className="block w-full rounded-lg text-left transition-opacity hover:opacity-70 focus-visible:ring-2 focus-visible:ring-ring"
              >
                <span className="block text-[0.65rem] uppercase tracking-[0.14em] text-muted-foreground">{roleLabel(item.role)}</span>
                <span className="block text-sm font-medium leading-snug tracking-tight">{item.name}</span>
                <span className="mt-0.5 block text-xs text-muted-foreground">
                  {[badgeValue(item), headline(item), formatEuros(item.price_cents)].filter(Boolean).join(" · ")}
                </span>
              </button>
            ))}

            <dl>
              <Row label="Prix total">
                <span className="font-display text-xl font-light">{formatEuros(selection.total_cents)}</span>
              </Row>
              {rows.map(([rowLabel, value]) => (
                <Row key={rowLabel} label={rowLabel}>
                  {value}
                </Row>
              ))}
            </dl>
          </div>

          {gauges.length > 0 ? (
            <div className="mt-auto border-t border-border pt-3" data-testid="selection-gauges">
              <GaugeRow readings={gauges} />
            </div>
          ) : null}
        </div>
      </div>

      {notes.length > 0 ? (
        <div className="space-y-2 rounded-xl bg-muted/50 p-3 text-xs leading-relaxed" data-testid="sommelier-notes">
          <p className="flex items-center gap-1.5 text-[0.65rem] uppercase tracking-[0.14em] text-muted-foreground">
            <Wine className="size-3" aria-hidden /> L&apos;avis du sommelier
          </p>
          {notes.map((item) => (
            <p key={item.id}>
              <span className="font-medium">
                {item.name}
                {typeof item.match_score === "number" ? ` · ${item.match_score}/100` : ""}
              </span>{" "}
              <span className="text-muted-foreground">{item.sommelier_justification}</span>
            </p>
          ))}
        </div>
      ) : null}

      <div className="flex flex-wrap gap-1.5">
        <Badge variant={selection.compatible ? "ok" : "destructive"}>
          {selection.compatible ? <Check aria-hidden /> : null}
          {selection.compatible ? "Compatible" : "Incompatible"}
        </Badge>
        <Badge variant={selection.within_budget ? "secondary" : "warn"}>{selection.within_budget ? "Dans le budget" : "Hors budget"}</Badge>
      </div>

      {warnings.length > 0 ? (
        <ul className="space-y-1 text-xs text-warn">
          {warnings.map((w) => (
            <li key={w.code} className="flex gap-1.5">
              <TriangleAlert className="mt-0.5 size-3 shrink-0" aria-hidden /> {w.message}
            </li>
          ))}
        </ul>
      ) : null}
      {info ? (
        <p className="flex gap-1.5 text-xs text-muted-foreground">
          <Info className="mt-0.5 size-3 shrink-0" aria-hidden /> {info.message}
        </p>
      ) : null}

      <Button className="mt-auto" variant={chosen ? "secondary" : "default"} disabled={disabled || !selection.compatible} onClick={onChoose}>
        {chosen ? <Check /> : null}
        {chosen ? `${nounLabel} choisie` : `Choisir cette ${noun.singular}`}
      </Button>
    </motion.article>
  );
}
