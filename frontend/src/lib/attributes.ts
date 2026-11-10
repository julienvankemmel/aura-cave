/**
 * Rendu générique des attributs d'un produit : parcourt dynamiquement `attributes`, sans libellé figé.
 * Les clés inconnues sont humanisées ("serving_temp" -> "Serving temp") ; une valeur absente n'est jamais affichée.
 */
import type { SelectionItem, Product } from "./types";
import { WINE } from "./wine";
import { formatEuros, formatWeight } from "./utils";

type Attrs = Record<string, unknown>;
export interface AttributeRow {
  key: string;
  label: string;
  value: string;
}

export function humanize(key: string): string {
  const spaced = key.split(".").pop()!.replace(/_/g, " ").trim();
  return spaced.charAt(0).toUpperCase() + spaced.slice(1);
}

const isBlank = (v: unknown): boolean =>
  v === null || v === undefined || v === "" || (Array.isArray(v) && v.length === 0);

const isPlainObject = (v: unknown): v is Attrs => typeof v === "object" && v !== null && !Array.isArray(v);

/** Unité déduite du suffixe de la clé quand aucun format dédié n'existe. */
function formatScalar(key: string, value: unknown): string | null {
  if (isBlank(value)) return null;
  if (typeof value === "boolean") return value ? "Oui" : "Non";
  if (Array.isArray(value)) {
    const items = value.filter((v) => !isBlank(v)).map((v) => String(v));
    return items.length > 0 ? items.join(" · ") : null;
  }
  if (typeof value === "number") {
    if (key.endsWith("_cents")) return formatEuros(value);
    if (key.endsWith("_mm")) return `${value} mm`;
    if (key.endsWith("_cl")) return `${value} cl`;
    if (key.endsWith("_g")) return formatWeight(value);
    return String(value).replace(".", ",");
  }
  if (typeof value === "string") return value;
  return null;
}

function formatEntry(path: string, value: unknown, attributes: Attrs): string | null {
  const formatter = WINE.formatters[path];
  return formatter ? formatter(value, attributes) : formatScalar(path, value);
}

/** Lignes ordonnées (attributs virtuels inclus) ; les blocs imbriqués sont aplatis en `bloc.clé`. */
export function attributeRows(attributes: Attrs): AttributeRow[] {
  const rows = new Map<string, AttributeRow>();

  const push = (path: string, value: unknown) => {
    if (WINE.hidden.includes(path)) return;
    const text = formatEntry(path, value, attributes);
    if (text === null || text === "") return;
    rows.set(path, { key: path, label: WINE.attributeLabels[path] ?? humanize(path), value: text });
  };

  for (const [key, value] of Object.entries(attributes)) {
    if (isPlainObject(value) && !WINE.formatters[key]) {
      for (const [sub, subValue] of Object.entries(value)) push(`${key}.${sub}`, subValue);
    } else {
      push(key, value);
    }
  }
  for (const virtual of WINE.virtual) {
    const text = virtual.format(virtual.from.map((k) => attributes[k]));
    if (text) rows.set(virtual.key, { key: virtual.key, label: virtual.label, value: text });
  }

  const rank = (key: string): number => {
    const i = WINE.order.indexOf(key);
    return i === -1 ? WINE.order.length : i;
  };
  return [...rows.values()].sort((a, b) => rank(a.key) - rank(b.key));
}

/** Fiche détaillée : socle commun (marque, stock, référence) + attributs du vin. */
export function specRows(product: Pick<Product, "brand" | "sku" | "category" | "stock" | "attributes">): [string, string][] {
  const rows: [string, string][] = [["Marque", product.brand]];
  rows.push(...(WINE.extraRows?.(product.category, product.attributes) ?? []));
  for (const r of attributeRows(product.attributes)) rows.push([r.label, r.value]);
  if (typeof product.stock === "number" && product.stock > 0 && product.stock <= 5) {
    rows.push(["Stock", `${product.stock} restant${product.stock > 1 ? "s" : ""}`]);
  }
  rows.push(["Référence", product.sku]);
  return rows.filter((r) => r[1] !== "");
}

/** Sous-titre compact d'une carte produit : quelques attributs clés, sans libellés. */
export function headline(product: Pick<Product, "category" | "attributes">): string {
  const keys = WINE.headlineKeys[product.category] ?? WINE.headlineKeys.default ?? [];
  const rows = attributeRows(product.attributes);
  const byKey = new Map(rows.map((r) => [r.key, r.value]));
  const wanted = keys.length > 0 ? keys : rows.slice(0, 2).map((r) => r.key);
  return wanted
    .map((k) => byKey.get(k))
    .filter((v): v is string => Boolean(v))
    .join(" · ");
}

export function badgeValue(product: Pick<Product, "attributes">): string | null {
  if (!WINE.badgeKey) return null;
  return formatScalar(WINE.badgeKey, product.attributes[WINE.badgeKey]);
}

export function categoryLabel(category: string): string {
  return WINE.categoryLabels[category] ?? humanize(category);
}

export function roleLabel(role: string): string {
  return WINE.roleLabels[role] ?? humanize(role);
}

/** Lignes du comparatif : métriques puis détails (les détails inconnus et non scalaires sont ignorés). */
export function selectionRows(
  metrics: Record<string, unknown>,
  details: Record<string, unknown>,
): [string, string][] {
  const rows: [string, string][] = [];
  for (const [key, value] of Object.entries(metrics)) {
    const spec = WINE.metrics[key];
    const text = spec ? spec.format(value) : formatScalar(key, value);
    if (text) rows.push([spec?.label ?? humanize(key), text]);
  }
  for (const [key, value] of Object.entries(details)) {
    const spec = WINE.details[key];
    if (spec) {
      const text = spec.format(value);
      if (text) rows.push([spec.label, text]);
    } else if (!isPlainObject(value)) {
      const text = formatScalar(key, value);
      if (text) rows.push([humanize(key), text]);
    }
  }
  return rows;
}

export function itemToProduct(item: SelectionItem): Product {
  return {
    id: item.id,
    sku: item.sku,
    name: item.name,
    brand: item.brand,
    category: item.category,
    price_cents: item.price_cents,
    stock: item.stock,
    attributes: item.attributes,
  };
}

export interface GaugeReading {
  key: string;
  label: string;
  /** Note (moyenne si plusieurs produits), chaque valeur étant bornée à [1, max]. */
  value: number;
  max: number;
  /** Nombre de produits dont la note a été moyennée. */
  count: number;
}

/**
 * Notes à afficher en jauges pour un ou plusieurs produits : pour chaque indicateur, la moyenne des
 * valeurs numériques présentes (un indicateur absent de tous les produits n'est pas affiché).
 */
export function gaugeReadings(attributesList: Attrs[]): GaugeReading[] {
  const readings: GaugeReading[] = [];
  for (const spec of WINE.gauges) {
    const values = attributesList
      .map((a) => a[spec.key])
      .filter((v): v is number => typeof v === "number" && Number.isFinite(v))
      .map((v) => Math.min(spec.max, Math.max(1, v)));
    if (values.length === 0) continue;
    const mean = values.reduce((sum, v) => sum + v, 0) / values.length;
    readings.push({
      key: spec.key,
      label: spec.label,
      value: Math.round(mean * 10) / 10,
      max: spec.max,
      count: values.length,
    });
  }
  return readings;
}

/** Image d'un produit : `attributes.image_url`, acceptée seulement en chemin local ou en HTTPS (jamais javascript:, data: ou //hôte). */
export function imageUrl(attributes: Attrs): string | null {
  const url = attributes.image_url;
  if (typeof url !== "string") return null;
  const trimmed = url.trim();
  if ((trimmed.startsWith("/") && !trimmed.startsWith("//")) || /^https:\/\/[^\s/]+/i.test(trimmed)) return trimmed;
  return null;
}

/** Couleur de la bouteille de repli d'un produit, selon le visuel de repli (null : pas de bouteille pour ce produit). */
export function bottleColor(category: string, attributes: Attrs): string | null {
  const visual = WINE.visual;
  if (!visual || !visual.categories.includes(category)) return null;
  const value = attributes[visual.colorKey];
  return (typeof value === "string" ? visual.palette[value] : undefined) ?? visual.fallbackColor;
}
