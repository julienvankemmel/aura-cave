"use client";

import { useReducedMotion } from "framer-motion";
import { ChevronLeft, ChevronRight } from "lucide-react";
import { Children, useCallback, useEffect, useRef, useState, type KeyboardEvent, type ReactNode } from "react";

import { cn } from "@/lib/utils";

interface SliderProps {
  /** Nom accessible du carrousel (ex. « Propositions du sommelier »). */
  label: string;
  /** Libellé d'une diapositive pour les lecteurs d'écran et les points (« Proposition »). */
  itemLabel: string;
  children: ReactNode;
  slideClassName?: string;
}

/**
 * Carrousel horizontal : défilement tactile ou à la molette avec aimantation (scroll-snap), flèches, points et
 * touches ←/→. Les diapositives restent dans le flux (pas de transformation) : un clavier ou un lecteur d'écran
 * les parcourt normalement.
 */
export function Slider({ label, itemLabel, children, slideClassName }: SliderProps) {
  const slides = Children.toArray(children);
  const track = useRef<HTMLDivElement>(null);
  const [active, setActive] = useState(0);
  const reduceMotion = useReducedMotion();
  const count = slides.length;

  const slideAt = useCallback((index: number) => track.current?.children[index] as HTMLElement | undefined, []);

  const goTo = useCallback(
    (index: number) => {
      const el = track.current;
      const slide = slideAt(Math.max(0, Math.min(count - 1, index)));
      if (!el || !slide) return;
      el.scrollTo({ left: slide.offsetLeft - (el.clientWidth - slide.clientWidth) / 2, behavior: reduceMotion ? "auto" : "smooth" });
    },
    [count, reduceMotion, slideAt],
  );

  useEffect(() => {
    const el = track.current;
    if (!el) return;
    let frame = 0;
    const update = () => {
      frame = 0;
      const center = el.scrollLeft + el.clientWidth / 2;
      let best = 0;
      let bestDistance = Infinity;
      for (let i = 0; i < el.children.length; i++) {
        const child = el.children[i] as HTMLElement;
        const distance = Math.abs(child.offsetLeft + child.clientWidth / 2 - center);
        if (distance < bestDistance) {
          best = i;
          bestDistance = distance;
        }
      }
      setActive(best);
    };
    const onScroll = () => {
      if (frame === 0) frame = requestAnimationFrame(update);
    };
    el.addEventListener("scroll", onScroll, { passive: true });
    update();
    return () => {
      el.removeEventListener("scroll", onScroll);
      if (frame !== 0) cancelAnimationFrame(frame);
    };
  }, [count]);

  const onKeyDown = (e: KeyboardEvent<HTMLDivElement>) => {
    if (e.key === "ArrowRight") {
      e.preventDefault();
      goTo(active + 1);
    } else if (e.key === "ArrowLeft") {
      e.preventDefault();
      goTo(active - 1);
    }
  };

  return (
    <div className="w-full" role="region" aria-roledescription="carrousel" aria-label={label} data-testid="slider">
      <div className="relative">
        <div
          ref={track}
          tabIndex={0}
          onKeyDown={onKeyDown}
          className="relative -mx-1 flex snap-x snap-mandatory gap-3 overflow-x-auto scroll-smooth px-1 pb-3 outline-none [scrollbar-width:none] focus-visible:ring-2 focus-visible:ring-ring [&::-webkit-scrollbar]:hidden"
        >
          {slides.map((slide, i) => (
            <div
              key={i}
              role="group"
              aria-roledescription="diapositive"
              aria-label={`${itemLabel} ${i + 1} sur ${count}`}
              className={cn("w-[88%] max-w-full shrink-0 snap-center sm:w-[40rem]", slideClassName)}
            >
              {slide}
            </div>
          ))}
        </div>
      </div>

      {count > 1 ? (
        <div className="mt-1 flex items-center justify-center gap-3">
          <button
            type="button"
            onClick={() => goTo(active - 1)}
            disabled={active === 0}
            aria-label="Diapositive précédente"
            className="rounded-full border border-border p-1.5 text-muted-foreground transition-colors hover:text-foreground disabled:opacity-30"
          >
            <ChevronLeft className="size-4" aria-hidden />
          </button>
          <div className="flex items-center gap-1.5" role="tablist" aria-label={`Naviguer dans : ${label}`}>
            {slides.map((_, i) => (
              <button
                key={i}
                type="button"
                role="tab"
                aria-selected={i === active}
                aria-label={`${itemLabel} ${i + 1}`}
                onClick={() => goTo(i)}
                className={cn("h-1.5 rounded-full transition-all", i === active ? "w-5 bg-foreground" : "w-1.5 bg-border hover:bg-muted-foreground")}
              />
            ))}
          </div>
          <button
            type="button"
            onClick={() => goTo(active + 1)}
            disabled={active === count - 1}
            aria-label="Diapositive suivante"
            className="rounded-full border border-border p-1.5 text-muted-foreground transition-colors hover:text-foreground disabled:opacity-30"
          >
            <ChevronRight className="size-4" aria-hidden />
          </button>
        </div>
      ) : null}
    </div>
  );
}
