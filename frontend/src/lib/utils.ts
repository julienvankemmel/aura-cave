import { clsx, type ClassValue } from "clsx";
import { twMerge } from "tailwind-merge";

export function cn(...inputs: ClassValue[]): string {
  return twMerge(clsx(inputs));
}

const euros = new Intl.NumberFormat("fr-FR", {
  style: "currency",
  currency: "EUR",
  maximumFractionDigits: 0,
});

export function formatEuros(cents: number): string {
  return euros.format(cents / 100);
}

export function formatWeight(grams: number): string {
  return grams >= 1000 ? `${(grams / 1000).toFixed(2).replace(".", ",")} kg` : `${grams} g`;
}
