"use client";

import { Send } from "lucide-react";
import { useState } from "react";

import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Dialog, DialogContent, DialogDescription, DialogTitle } from "@/components/ui/dialog";
import { Input } from "@/components/ui/input";
import type { Product } from "@/lib/types";
import { categoryLabel, specRows } from "@/lib/attributes";
import { WINE } from "@/lib/wine";
import { formatEuros } from "@/lib/utils";


interface Props {
  product: Product | null;
  onClose: () => void;
  onAsk: (product: Product, question: string) => void;
  disabled?: boolean;
}

export function ProductDetailSheet({ product, onClose, onAsk, disabled }: Props) {
  const [question, setQuestion] = useState("");

  const submit = () => {
    if (!product || !question.trim()) return;
    onAsk(product, question.trim());
    setQuestion("");
  };

  return (
    <Dialog open={product !== null} onOpenChange={(open) => !open && onClose()}>
      <DialogContent side="right" aria-describedby="product-sheet-desc">
        {product ? (
          <>
            <div className="space-y-2 pr-6">
              <Badge variant="secondary">{categoryLabel(product.category)}</Badge>
              <DialogTitle className="font-display text-2xl font-light leading-tight tracking-tight">{product.name}</DialogTitle>
              <DialogDescription id="product-sheet-desc" className="text-sm text-muted-foreground">
                Caractéristiques issues du catalogue.
              </DialogDescription>
              <p className="font-display text-3xl font-light tracking-tight">{formatEuros(product.price_cents)}</p>
            </div>
            <dl className="divide-y divide-border rounded-2xl bg-white/50 text-sm">
              {specRows(product).map(([label, value]) => (
                <div key={label} className="flex justify-between gap-4 px-4 py-2.5">
                  <dt className="text-muted-foreground">{label}</dt>
                  <dd className="text-right font-medium">{value}</dd>
                </div>
              ))}
            </dl>
            <form
              className="mt-auto space-y-2"
              onSubmit={(e) => {
                e.preventDefault();
                submit();
              }}
            >
              <label htmlFor="ask-ai" className="text-xs font-medium tracking-wide text-muted-foreground">
                Poser une question à l&apos;IA sur ce produit
              </label>
              <div className="flex gap-2">
                <Input
                  id="ask-ai"
                  value={question}
                  maxLength={300}
                  placeholder={WINE.askPlaceholder}
                  onChange={(e) => setQuestion(e.target.value)}
                />
                <Button type="submit" size="icon" disabled={disabled || !question.trim()} aria-label="Envoyer la question">
                  <Send />
                </Button>
              </div>
            </form>
          </>
        ) : null}
      </DialogContent>
    </Dialog>
  );
}
