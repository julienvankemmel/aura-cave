"use client";

import { motion, useReducedMotion } from "framer-motion";

import type { GaugeReading } from "@/lib/attributes";

const RADIUS = 26;
/** Demi-cercle ouvert vers le bas : de (6, 32) à (58, 32). */
const ARC = `M ${32 - RADIUS} 32 A ${RADIUS} ${RADIUS} 0 0 1 ${32 + RADIUS} 32`;

function formatValue(value: number): string {
  return Number.isInteger(value) ? String(value) : value.toFixed(1).replace(".", ",");
}

/** Jauge demi-circulaire d'une note de 1 à `max` (ex. tanins 4/5). */
export function SemiGauge({ reading }: { reading: GaugeReading }) {
  const reduce = useReducedMotion();
  const fraction = reading.value / reading.max;
  const text = `${formatValue(reading.value)} sur ${reading.max}`;
  return (
    <div className="flex flex-col items-center gap-0.5" data-testid="gauge">
      <svg viewBox="0 0 64 38" className="h-10 w-16" role="img" aria-label={`${reading.label} : ${text}${reading.count > 1 ? " (moyenne)" : ""}`}>
        <path d={ARC} fill="none" strokeWidth={6} strokeLinecap="round" className="stroke-border" />
        <motion.path
          d={ARC}
          fill="none"
          strokeWidth={6}
          strokeLinecap="round"
          className="stroke-primary"
          initial={reduce ? false : { pathLength: 0 }}
          animate={{ pathLength: fraction }}
          transition={{ duration: 0.7, ease: "easeOut" }}
        />
        <text x="32" y="31" textAnchor="middle" className="fill-foreground text-[11px] font-medium">
          {formatValue(reading.value)}
        </text>
      </svg>
      <span className="text-[0.65rem] uppercase tracking-[0.12em] text-muted-foreground">{reading.label}</span>
    </div>
  );
}

export function GaugeRow({ readings }: { readings: GaugeReading[] }) {
  if (readings.length === 0) return null;
  const averaged = readings.some((r) => r.count > 1);
  return (
    <div>
      <div className="grid grid-cols-[repeat(auto-fit,minmax(3.6rem,1fr))] gap-1" role="group" aria-label="Profil du vin">
        {readings.map((r) => (
          <SemiGauge key={r.key} reading={r} />
        ))}
      </div>
      {averaged ? <p className="mt-1 text-center text-[0.65rem] text-muted-foreground">Moyenne des bouteilles</p> : null}
    </div>
  );
}
