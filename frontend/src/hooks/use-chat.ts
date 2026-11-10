"use client";

import { useCallback, useEffect, useReducer, useRef, useState } from "react";

import { removeCartItem } from "@/lib/cart-api";
import { cancelCheckout, isPlaceholder, postCheckoutMessage, type CheckoutPlaceholder } from "@/lib/checkout-api";
import { ChatHttpError, getCartId, resetCartId, streamChat } from "@/lib/chat-api";
import { chatReducer, createInitialState } from "@/lib/chat-reducer";
import { stripIdTags } from "@/lib/scenario";

export interface SendOptions {
  /** Texte affiché dans la bulle utilisateur (sinon le message sans marqueurs d'id). */
  display?: string;
}

export function useChat() {
  const [state, dispatch] = useReducer(chatReducer, undefined, createInitialState);
  const [cartId, setCartId] = useState<string | null>(null);
  // Ce que le client a saisi pendant la commande : gardé en mémoire dans ce navigateur pour l'affichage uniquement.
  const [checkoutValues, setCheckoutValues] = useState<Partial<Record<CheckoutPlaceholder, string>>>({});
  const abortRef = useRef<AbortController | null>(null);
  const busyRef = useRef(false);

  useEffect(() => {
    setCartId(getCartId());
    return () => abortRef.current?.abort();
  }, []);

  const send = useCallback(
    async (message: string, options: SendOptions = {}) => {
      if (busyRef.current || !cartId) return;
      busyRef.current = true;
      const controller = new AbortController();
      abortRef.current = controller;

      const shown = options.display ?? stripIdTags(message);
      dispatch({ type: "user_message", id: crypto.randomUUID(), text: shown });
      dispatch({ type: "assistant_start", id: crypto.randomUUID() });
      try {
        for await (const event of streamChat({ cart_id: cartId, message }, controller.signal)) {
          dispatch({ type: "event", event });
        }
        dispatch({ type: "stream_end" });
      } catch (error) {
        if (controller.signal.aborted) {
          dispatch({ type: "stream_end" });
        } else {
          const text =
            error instanceof ChatHttpError && error.status === 401
              ? "Accès refusé par l'agent (clé interne)."
              : "Connexion à l'assistant impossible. Réessayez dans un instant.";
          dispatch({ type: "stream_error", message: text });
        }
      } finally {
        busyRef.current = false;
      }
    },
    [cartId],
  );

  /**
   * Réponse du client pendant la commande : la saisie brute part vers Symfony (qui la valide, la chiffre et fait avancer
   * l'étape) ; l'agent ne reçoit ensuite que le jeton d'anonymisation, pas la donnée.
   */
  const answerCheckout = useCallback(
    async (text: string) => {
      if (busyRef.current || !cartId) return;
      let turn;
      try {
        turn = await postCheckoutMessage(cartId, text);
      } catch {
        dispatch({ type: "stream_error", message: "Votre réponse n'a pas pu être traitée. Réessayez dans un instant." });
        return;
      }
      if (turn.accepted && isPlaceholder(turn.llm_message)) {
        const token = turn.llm_message;
        setCheckoutValues((values) => ({ ...values, [token]: text.trim() }));
      }
      dispatch({ type: "checkout_step", step: turn.step === "CONFIRMED" ? null : turn.step });
      await send(turn.llm_message, { display: text });
      if (turn.order) {
        dispatch({
          type: "event",
          event: {
            event: "ui_stripe_checkout",
            data: { cart_id: cartId, total_cents: turn.order.total_cents, currency: "EUR", mode: "test", checkout_url: null },
          },
        });
      }
    },
    [cartId, send],
  );

  /** Abandonne la commande en cours : Symfony efface ce qui a déjà été collecté. */
  const abortCheckout = useCallback(async () => {
    if (!cartId) return;
    try {
      await cancelCheckout(cartId);
    } catch {
      /* l'état local est remis à zéro dans tous les cas ; Symfony expire de lui-même au prochain démarrage */
    }
    setCheckoutValues({});
    dispatch({ type: "checkout_step", step: null });
  }, [cartId]);

  /** Retire une ligne du panier directement (sans passer par le LLM) et synchronise l'affichage. */
  const removeItem = useCallback(
    async (productId: string) => {
      if (!cartId) return;
      try {
        const cart = await removeCartItem(cartId, productId);
        dispatch({ type: "event", event: { event: "ui_cart_update", data: cart } });
      } catch {
        dispatch({ type: "stream_error", message: "Impossible de retirer cet article du panier. Réessayez." });
      }
    },
    [cartId],
  );

  const reset = useCallback(() => {
    abortRef.current?.abort();
    busyRef.current = false;
    setCartId(resetCartId());
    setCheckoutValues({});
    dispatch({ type: "reset" });
  }, []);

  return { state, dispatch, send, answerCheckout, abortCheckout, checkoutValues, removeItem, reset, cartId, ready: cartId !== null };
}
