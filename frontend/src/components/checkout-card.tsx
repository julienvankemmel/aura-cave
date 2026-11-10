import { CreditCard, ShieldCheck } from "lucide-react";

import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import type { StripeCheckout } from "@/lib/types";
import { formatEuros } from "@/lib/utils";

export function CheckoutCard({ data }: { data: StripeCheckout }) {
  return (
    <section className="glass w-full space-y-4 rounded-2xl p-5" data-testid="checkout-card" aria-label="Paiement">
      <p className="flex items-center gap-2 text-sm text-muted-foreground">
        <ShieldCheck className="size-4 text-ok" aria-hidden /> Adresse enregistrée de façon sécurisée
      </p>
      <div className="flex flex-wrap items-end justify-between gap-4">
        <div>
          <p className="text-xs text-muted-foreground">Total à régler</p>
          <p className="font-display text-3xl font-light tracking-tight">{formatEuros(data.total_cents)}</p>
        </div>
        <div className="flex items-center gap-2">
          {data.mode === "test" ? <Badge variant="warn">Mode test</Badge> : null}
          {data.checkout_url ? (
            <Button asChild>
              <a href={data.checkout_url} rel="noopener noreferrer">
                <CreditCard /> Payer avec Stripe
              </a>
            </Button>
          ) : (
            <Button disabled title="Le paiement Stripe n'est pas encore branché">
              <CreditCard /> Paiement Stripe (bientôt)
            </Button>
          )}
        </div>
      </div>
    </section>
  );
}
