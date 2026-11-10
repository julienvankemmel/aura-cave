"use client";

import { SelectionCard } from "@/components/selection-card";
import { Slider } from "@/components/slider";
import { WINE } from "@/lib/wine";
import type { BundleComparison, SelectionItem, SelectionRow } from "@/lib/types";

interface Props {
  data: BundleComparison;
  chosenIndex: number | null;
  disabled?: boolean;
  onChoose: (index: number, selection: SelectionRow) => void;
  onInfo: (item: SelectionItem) => void;
}

/** Les propositions de l'agent (3 au plus) dans un carrousel horizontal : on glisse de la sélection 1 à la 3. */
export function BundleComparisonCard({ data, chosenIndex, disabled, onChoose, onInfo }: Props) {
  const noun = WINE.noun;
  const itemLabel = noun.singular.charAt(0).toUpperCase() + noun.singular.slice(1);
  return (
    <section className="w-full space-y-3" data-testid="bundle-comparison" aria-label={noun.comparisonTitle}>
      <div className="px-1">
        <h3 className="font-display text-lg font-light tracking-tight">{noun.comparisonTitle}</h3>
        <p className="text-xs text-muted-foreground">{WINE.engineNote}</p>
      </div>
      <Slider label={noun.comparisonTitle} itemLabel={itemLabel}>
        {data.selections.map((selection, index) => (
          <SelectionCard
            key={selection.label}
            selection={selection}
            index={index}
            recommended={data.recommended_index === index}
            chosen={chosenIndex === index}
            disabled={disabled}
            onInfo={onInfo}
            onChoose={() => onChoose(index, selection)}
          />
        ))}
      </Slider>
    </section>
  );
}
