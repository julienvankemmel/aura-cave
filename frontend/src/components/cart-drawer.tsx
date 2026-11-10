"use client";

import { RotateCcw } from "lucide-react";

import { CartPanel } from "@/components/cart-panel";
import { Button } from "@/components/ui/button";
import { Dialog, DialogContent, DialogDescription, DialogTitle } from "@/components/ui/dialog";
import type { ChatState } from "@/lib/chat-reducer";
import type { CartItem } from "@/lib/types";

interface Props {
  open: boolean;
  onOpenChange: (open: boolean) => void;
  state: ChatState;
  count: number;
  onBudgetChange: (cents: number) => void;
  onCheckout: () => void;
  onRemove: (item: CartItem) => void;
  onReset: () => void;
}

/** Tiroir latéral en verre dépoli : le panier, ouvert depuis l'en-tête. */
export function CartDrawer({ open, onOpenChange, state, count, onBudgetChange, onCheckout, onRemove, onReset }: Props) {
  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent side="right" aria-describedby="cart-desc" onOpenAutoFocus={(e) => e.preventDefault()}>
        <div className="space-y-1 pr-8">
          <DialogTitle className="font-display text-2xl font-light tracking-tight">
            Panier{count > 0 ? <span className="ml-2 text-base text-muted-foreground">({count})</span> : null}
          </DialogTitle>
          <DialogDescription id="cart-desc" className="text-sm text-muted-foreground">
            Vos articles et le total de votre commande.
          </DialogDescription>
        </div>
        <CartPanel
          state={state}
          onBudgetChange={onBudgetChange}
          onRemove={onRemove}
          onCheckout={() => {
            onOpenChange(false);
            onCheckout();
          }}
        />
        <Button variant="ghost" size="sm" className="self-start" onClick={onReset}>
          <RotateCcw /> Vider et recommencer
        </Button>
      </DialogContent>
    </Dialog>
  );
}
