"use client";

import { AnimatePresence, motion } from "framer-motion";
import { ArrowUp, ArrowUpRight, Loader2, Lock, Mic, ShoppingBag, Wine } from "lucide-react";
import { useCallback, useEffect, useLayoutEffect, useMemo, useRef, useState, type FormEvent, type KeyboardEvent } from "react";

import { AccessoryUpsellBar } from "@/components/accessory-upsell-bar";
import { BundleComparisonCard } from "@/components/bundle-comparison-card";
import { CheckoutCard } from "@/components/checkout-card";
import { ProductCarousel } from "@/components/product-card";
import { ProductDetailSheet } from "@/components/product-detail-sheet";
import { CartDrawer } from "@/components/cart-drawer";
import { Button } from "@/components/ui/button";
import { useChat } from "@/hooks/use-chat";
import { useSpeechInput } from "@/hooks/use-speech-input";
import { itemToProduct } from "@/lib/attributes";
import { splitPlaceholders, type CheckoutPlaceholder } from "@/lib/checkout-api";
import type { Block, ChatMessage } from "@/lib/chat-reducer";
import { addSelectionToCartMessage, addToCartMessage, askAboutMessage, chooseSelectionMessage, DECLINE_ACCESSORIES_MESSAGE, showAccessoriesMessage } from "@/lib/scenario";
import { WINE, type WineConfig } from "@/lib/wine";
import type { SelectionItem, Product } from "@/lib/types";
import { cn } from "@/lib/utils";

export function ChatShell() {
  const config = WINE;
  const { state, dispatch, send, answerCheckout, abortCheckout, checkoutValues, removeItem, reset, ready } = useChat();
  const { shop } = state;
  const [input, setInput] = useState("");
  const speech = useSpeechInput(input, (text) => setInput(text.slice(0, 2000)));
  const [detail, setDetail] = useState<Product | null>(null);
  const [cartOpen, setCartOpen] = useState(false);
  const [accessoriesDeclinedFor, setAccessoriesDeclinedFor] = useState<number | null>(null);
  const bottomRef = useRef<HTMLDivElement>(null);
  const inputRef = useRef<HTMLTextAreaElement>(null);
  const busy = state.status === "streaming";

  useEffect(() => {
    bottomRef.current?.scrollIntoView({ behavior: "smooth", block: "end" });
  }, [state.messages]);

  // Le champ grandit avec le texte (jusqu'à ~6 lignes, puis défile).
  useLayoutEffect(() => {
    const el = inputRef.current;
    if (!el) return;
    el.style.height = "auto";
    el.style.height = `${Math.min(el.scrollHeight, 160)}px`;
  }, [input]);

  const inCartIds = useMemo(() => new Set((shop.cart?.items ?? []).map((i) => i.product_id)), [shop.cart]);

  const resolve = useCallback((item: SelectionItem): Product => shop.catalog[item.id] ?? itemToProduct(item), [shop.catalog]);

  const checkoutActive = shop.checkoutStep !== null;

  const submit = (e: FormEvent) => {
    e.preventDefault();
    const text = input.trim();
    if (!text || busy || !ready) return;
    speech.stop();
    setInput("");
    // Pendant la commande, la saisie (nom, adresse, téléphone, email) va à Symfony, pas à l'agent.
    if (checkoutActive) void answerCheckout(text);
    else void send(text);
  };

  const onComposerKeyDown = (e: KeyboardEvent<HTMLTextAreaElement>) => {
    // Entrée envoie, Maj+Entrée insère un saut de ligne (et on respecte la composition IME).
    if (e.key === "Enter" && !e.shiftKey && !e.nativeEvent.isComposing) {
      e.preventDefault();
      e.currentTarget.form?.requestSubmit();
    }
  };

  const askAbout = (product: Product, question: string) => {
    setDetail(null);
    void send(askAboutMessage(product.name, product.id, question), { display: `${product.name} — ${question}` });
  };

  const renderBlock = (block: Block, key: string) => {
    switch (block.type) {
      case "products":
        return <ProductCarousel key={key} products={block.products} onInfo={setDetail} />;
      case "comparison":
        return (
          <BundleComparisonCard
            key={key}
            data={block.data}
            chosenIndex={shop.chosenIndex}
            disabled={busy}
            onInfo={(item) => setDetail(resolve(item))}
            onChoose={(index, selection) => {
              dispatch({ type: "choose_selection", index });
              void send(
                chooseSelectionMessage(
                  index,
                  selection.items.filter((i) => i.role !== "accessory").map((i) => i.id),
                ),
                { display: `Je choisis : ${selection.items.filter((i) => i.role !== "accessory").map((i) => i.name).join(" + ")}` },
              );
            }}
          />
        );
      case "upsell":
        return (
          <AccessoryUpsellBar
            key={key}
            data={block.data}
            inCartIds={inCartIds}
            disabled={busy}
            selectionInCart={block.data.item_ids.length > 0 && block.data.item_ids.every((id) => inCartIds.has(id))}
            onAddSelection={() =>
              void send(addSelectionToCartMessage(block.data.item_ids), {
                display: "Ajouter la sélection au panier",
              })
            }
            forLabel={block.data.item_ids.map((id) => shop.catalog[id]?.name).filter(Boolean).join(" + ") || undefined}
            onInfo={setDetail}
            onAdd={(p) => void send(addToCartMessage(p.name, p.id), { display: `Ajouter au panier : ${p.name}` })}
          />
        );
      case "checkout":
        return <CheckoutCard key={key} data={block.data} />;
    }
  };

  // Sélection choisie et déjà au panier : on propose (sans insister) de voir des accessoires.
  const chosenSelection = shop.chosenIndex !== null ? shop.comparison?.selections[shop.chosenIndex] : undefined;
  const chosenWineIds = (chosenSelection?.items ?? []).filter((i) => i.role !== "accessory").map((i) => i.id);
  const upsellShown = state.messages.some((m) => m.blocks.some((b) => b.type === "upsell"));
  const offerAccessories =
    chosenWineIds.length > 0 &&
    chosenWineIds.every((id) => inCartIds.has(id)) &&
    !upsellShown &&
    accessoriesDeclinedFor !== shop.chosenIndex &&
    shop.checkout === null &&
    !busy;

  const cartCount = (shop.cart?.items ?? []).reduce((n, i) => n + i.quantity, 0);
  const hasPreview = cartCount === 0 && shop.comparison !== null;
  const empty = state.messages.length === 0;

  return (
    <div className="min-h-dvh">
      <header className="fixed inset-x-0 top-0 z-40 flex justify-center px-4 pt-4">
        <div className="glass flex h-12 w-full max-w-3xl items-center justify-between rounded-full pl-5 pr-2">
          <div className="flex items-center gap-2.5">
            <Wine className="size-[1.15rem]" strokeWidth={1.5} aria-hidden />
            <span className="font-display text-[0.95rem] font-medium tracking-tight" data-testid="brand-name">
              {config.brandName}
            </span>
          </div>
          <div className="flex items-center gap-1">
          <Button variant="ghost" size="sm" onClick={() => setCartOpen(true)} aria-label={`Ouvrir le panier, ${cartCount} article${cartCount > 1 ? "s" : ""}`} data-testid="cart-button">
            <ShoppingBag />
            <span className="hidden sm:inline">Panier</span>
            <span key={cartCount} className="grid min-w-5 place-items-center rounded-full bg-primary px-1.5 text-[0.7rem] font-medium leading-5 text-primary-foreground" data-testid="cart-count">
              {cartCount}
            </span>
            {hasPreview ? <span className="size-1.5 rounded-full bg-ok" title="Sélection en cours" aria-hidden /> : null}
          </Button>
          </div>
        </div>
      </header>

      <main className={cn("mx-auto flex min-h-dvh w-full max-w-3xl flex-col px-5 pt-24", empty ? "pb-32" : "pb-44")}>
        {empty ? <Welcome config={config} disabled={!ready || busy} onStart={() => void send(config.demoMessage)} onSuggest={(t) => void send(t)} /> : null}
        <div className="space-y-8" role="log" aria-live="polite" aria-label="Conversation">
          <AnimatePresence initial={false}>
            {state.messages.map((m, index) => (
              <Bubble key={m.id} message={m} streaming={busy && index === state.messages.length - 1} renderBlock={renderBlock} loadingText={config.loadingText} values={checkoutValues} />
            ))}
          </AnimatePresence>
          {state.error ? (
            <p role="alert" className="rounded-2xl bg-destructive/10 px-4 py-3 text-sm text-destructive">
              {state.error}
            </p>
          ) : null}
        </div>
        {offerAccessories ? (
          <div className="mt-6 flex flex-wrap items-center gap-2" role="group" aria-label="Accessoires" data-testid="accessory-offer">
            <span className="mr-1 text-sm text-muted-foreground">Des accessoires pour servir ce vin ? C&apos;est facultatif.</span>
            <Button size="sm" variant="outline" onClick={() => void send(showAccessoriesMessage(chosenWineIds), { display: "Voir les accessoires compatibles" })}>
              Voir les accessoires
            </Button>
            <Button
              size="sm"
              variant="ghost"
              onClick={() => {
                setAccessoriesDeclinedFor(shop.chosenIndex);
                void send(DECLINE_ACCESSORIES_MESSAGE, { display: "Non merci pour les accessoires" });
              }}
            >
              Non merci
            </Button>
          </div>
        ) : null}
        <div ref={bottomRef} className="scroll-mb-44" />
      </main>

      <div className="pointer-events-none fixed inset-x-0 bottom-0 z-30 bg-gradient-to-t from-background via-background/85 to-transparent px-4 pb-5 pt-12">
        <form onSubmit={submit} className="glass-strong pointer-events-auto mx-auto flex max-w-2xl items-end gap-2 rounded-[1.75rem] p-1.5 pl-6">
          <textarea
            ref={inputRef}
            rows={1}
            aria-label="Votre message"
            placeholder={checkoutActive ? "Votre réponse (saisie sécurisée)…" : config.placeholder}
            value={input}
            maxLength={2000}
            disabled={!ready}
            onChange={(e) => setInput(e.target.value)}
            onKeyDown={onComposerKeyDown}
            className="max-h-40 min-h-10 min-w-0 flex-1 resize-none self-center bg-transparent py-2.5 text-[0.95rem] leading-6 outline-none placeholder:text-muted-foreground/70 disabled:opacity-50"
          />
          <Button
            type="button"
            size="icon"
            variant={speech.listening ? "default" : "ghost"}
            disabled={!speech.supported || busy || !ready}
            onClick={speech.toggle}
            aria-pressed={speech.listening}
            aria-label={speech.listening ? "Arrêter la dictée vocale" : "Dicter votre message"}
            title={
              speech.supported
                ? speech.listening
                  ? "Arrêter la dictée"
                  : "Dicter votre message (reconnaissance vocale du navigateur)"
                : "Dictée vocale indisponible sur ce navigateur"
            }
            data-testid="mic-button"
            className={cn("relative", speech.listening && "animate-pulse")}
          >
            <Mic />
          </Button>
          <Button type="submit" size="icon" disabled={busy || !input.trim() || !ready} aria-label="Envoyer">
            {busy ? <Loader2 className="animate-spin" /> : <ArrowUp />}
          </Button>
        </form>
        {checkoutActive ? (
          <p
            className="pointer-events-auto mx-auto mt-2 flex max-w-2xl flex-wrap items-center justify-between gap-2 px-6 text-xs text-muted-foreground"
            data-testid="checkout-notice"
          >
            <span className="flex items-center gap-1.5">
              <Lock className="size-3" aria-hidden /> Saisie sécurisée : l&apos;assistant IA ne voit que des jetons anonymes, jamais vos coordonnées.
            </span>
            <button type="button" onClick={() => void abortCheckout()} className="underline underline-offset-2 hover:text-foreground" data-testid="checkout-cancel">
              Annuler la commande
            </button>
          </p>
        ) : null}
        {speech.listening || speech.error ? (
          <p
            role={speech.error ? "alert" : "status"}
            data-testid="speech-status"
            className={cn("pointer-events-auto mx-auto mt-2 max-w-2xl px-6 text-xs", speech.error ? "text-destructive" : "text-muted-foreground")}
          >
            {speech.error ?? "Je vous écoute… parlez, puis appuyez sur le micro pour arrêter."}
          </p>
        ) : null}
      </div>

      <CartDrawer
        open={cartOpen}
        count={cartCount}
        onOpenChange={setCartOpen}
        state={state}
        onBudgetChange={(cents) => dispatch({ type: "set_budget", cents })}
        onCheckout={() => void send("Je souhaite passer commande", { display: "Je souhaite passer commande" })}
        onRemove={(item) => void removeItem(item.product_id)}
        onReset={() => {
          reset();
          setCartOpen(false);
        }}
      />
      <ProductDetailSheet product={detail} onClose={() => setDetail(null)} onAsk={askAbout} disabled={busy} />
    </div>
  );
}

/** Rend uniquement le **gras** (les LLM en glissent) : jamais de HTML brut, tout reste du texte. */
function renderInline(text: string, values: Partial<Record<CheckoutPlaceholder, string>>): React.ReactNode[] {
  return text.split(/(\*\*[^*\n]+\*\*)/g).map((part, i) =>
    part.startsWith("**") && part.endsWith("**") && part.length > 4 ? (
      <strong key={i} className="font-semibold">
        {part.slice(2, -2)}
      </strong>
    ) : (
      <span key={i}>
        {splitPlaceholders(part, values).map((seg, j) =>
          typeof seg === "string" ? (
            seg
          ) : (
            <span key={j} tabIndex={0} className="group relative cursor-help underline decoration-dotted underline-offset-4 outline-none">
              {seg.value}
              <span
                role="tooltip"
                className="pointer-events-none invisible absolute bottom-full left-1/2 z-50 mb-2 w-64 -translate-x-1/2 rounded-lg bg-foreground px-3 py-2 text-left text-xs font-normal leading-snug text-background no-underline opacity-0 shadow-lg transition-opacity group-hover:visible group-hover:opacity-100 group-focus:visible group-focus:opacity-100"
              >
                Affiché depuis votre navigateur : l&apos;assistant IA n&apos;a vu que « {seg.token} », jamais cette donnée.
              </span>
            </span>
          ),
        )}
      </span>
    ),
  );
}

function Bubble({
  message,
  streaming,
  renderBlock,
  loadingText,
  values,
}: {
  message: ChatMessage;
  streaming: boolean;
  renderBlock: (b: Block, key: string) => React.ReactNode;
  loadingText: string;
  /** Ce que le client a saisi : les jetons d'anonymisation sont remplacés (et signalés) uniquement à l'affichage. */
  values: Partial<Record<CheckoutPlaceholder, string>>;
}) {
  const user = message.role === "user";
  return (
    <motion.div
      initial={{ opacity: 0, y: 12 }}
      animate={{ opacity: 1, y: 0 }}
      transition={{ duration: 0.35, ease: "easeOut" }}
      className={cn("flex flex-col gap-4", user ? "items-end" : "items-start")}
    >
      {message.blocks.map((b, i) => renderBlock(b, `${message.id}-${i}`))}
      {message.text ? (
        <p
          className={cn(
            "whitespace-pre-wrap text-[0.95rem] leading-7",
            user ? "max-w-[85%] rounded-3xl bg-primary px-5 py-2.5 text-primary-foreground" : "max-w-[92%] text-foreground/90",
          )}
        >
          {renderInline(message.text, values)}
        </p>
      ) : streaming && !user ? (
        <p className="flex items-center gap-2 text-sm text-muted-foreground" aria-label="L'assistant réfléchit">
          <Loader2 className="size-3.5 animate-spin" aria-hidden /> {loadingText}
        </p>
      ) : null}
    </motion.div>
  );
}

function Welcome({
  config,
  disabled,
  onStart,
  onSuggest,
}: {
  config: WineConfig;
  disabled: boolean;
  onStart: () => void;
  onSuggest: (t: string) => void;
}) {
  return (
    <motion.div
      initial={{ opacity: 0, y: 16 }}
      animate={{ opacity: 1, y: 0 }}
      transition={{ duration: 0.7, ease: "easeOut" }}
      className="flex flex-1 flex-col items-center justify-center gap-8 text-center"
    >
      <section className="flex w-full flex-col items-center gap-8 px-2 py-8 sm:py-12" data-testid="welcome">
        <div className="space-y-5">
          <p className="text-[0.7rem] uppercase tracking-[0.34em] text-muted-foreground">{config.kicker}</p>
          <h1 className="font-serif text-5xl font-light leading-[1.05] tracking-tight text-balance sm:text-6xl">
            {config.headline[0]}
            <br />
            {config.headline[1]}
          </h1>
          <p className="mx-auto max-w-md text-[0.95rem] font-light leading-relaxed text-muted-foreground text-pretty">{config.intro}</p>
        </div>
        <button
          type="button"
          disabled={disabled}
          onClick={onStart}
          data-testid="demo-button"
          className="group flex max-w-xl items-center gap-4 rounded-3xl border bg-card px-6 py-5 text-left shadow-sm transition-all duration-300 hover:-translate-y-0.5 hover:shadow-md focus-visible:ring-2 focus-visible:ring-ring disabled:pointer-events-none disabled:opacity-50"
        >
          <span className="text-[0.95rem] leading-relaxed">{config.demoMessage}</span>
          <ArrowUpRight className="size-5 shrink-0 text-muted-foreground transition-transform group-hover:-translate-y-0.5 group-hover:translate-x-0.5" aria-hidden />
        </button>
      </section>
      <div className="flex flex-wrap justify-center gap-2">
        {config.suggestions.map((s) => (
          <Button key={s} variant="outline" size="sm" disabled={disabled} onClick={() => onSuggest(s)}>
            {s}
          </Button>
        ))}
      </div>
    </motion.div>
  );
}
