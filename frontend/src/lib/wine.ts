/**
 * Vocabulaire et présentation du catalogue de vins. L'interface est agnostique : elle parcourt les `attributes` des
 * produits ; ce fichier ne fournit que de la donnée de présentation (libellés, unités, ordre, formats), avec un
 * repli lisible pour toute clé inconnue.
 */

type Attrs = Record<string, unknown>;
type Formatter = (value: unknown, attributes: Attrs) => string | null;

/** Attribut calculé à partir de plusieurs attributs bruts (ex. une plage de valeurs). */
export interface VirtualAttribute {
  key: string;
  label: string;
  from: string[];
  format: (values: unknown[]) => string | null;
}

export interface Aggregate {
  label: string;
  kind: "sum" | "count";
  /** sum : attribut à additionner ; count : catégories comptées (toutes si absent). */
  attribute?: string;
  categories?: string[];
  format: (n: number) => string;
}

/** Note de 1 à `max` affichée en jauge demi-circulaire (ex. tanins, acidité, corps). */
export interface GaugeSpec {
  key: string;
  label: string;
  max: number;
}

/** Visuel de repli quand un produit n'a pas d'image : bouteille colorée selon un attribut. */
export interface VisualSpec {
  kind: "bottle";
  /** Catégories de produits représentées par une bouteille (les accessoires gardent une icône). */
  categories: string[];
  /** Attribut dont la valeur choisit la couleur. */
  colorKey: string;
  palette: Record<string, string>;
  fallbackColor: string;
}

export interface WineConfig {
  brandName: string;
  kicker: string;
  headline: [string, string];
  intro: string;
  demoMessage: string;
  suggestions: string[];
  placeholder: string;
  loadingText: string;
  defaultBudgetCents: number;
  noun: { singular: string; plural: string; comparisonTitle: string; comparisonSubtitle: string; recommended: string };
  roleLabels: Record<string, string>;
  categoryLabels: Record<string, string>;
  /** Groupes du panier ; le dernier, sans catégories, sert de repli. */
  cartGroups: { key: string; label: string; categories?: string[] }[];
  aggregates: Aggregate[];
  /** Attribut affiché en haut à droite de la carte produit. */
  badgeKey?: string;
  /** Indicateurs notés affichés en jauges sous la description (aucun : la zone reprend les métriques). */
  gauges: GaugeSpec[];
  /** Visuel de repli sans image (aucun : icône de la catégorie). */
  visual?: VisualSpec;
  /** Attributs du sous-titre de carte, par catégorie (`default` sinon). */
  headlineKeys: Record<string, string[]>;
  attributeLabels: Record<string, string>;
  /** Attributs ordonnés en premier dans la fiche ; les autres suivent dans l'ordre reçu. */
  order: string[];
  hidden: string[];
  virtual: VirtualAttribute[];
  formatters: Record<string, Formatter>;
  metrics: Record<string, { label: string; format: (v: unknown) => string | null }>;
  details: Record<string, { label: string; format: (v: unknown) => string | null }>;
  /** Lignes supplémentaires propres au catalogue pour un produit (ex. mention propre à une catégorie). */
  extraRows?: (category: string, attributes: Attrs) => [string, string][];
  /** Placeholder de la question posée à l'IA sur un produit. */
  askPlaceholder: string;
  /** Texte de transparence du comparatif. */
  engineNote: string;
}

const asNumber = (v: unknown): number | null => (typeof v === "number" && Number.isFinite(v) ? v : null);
const withUnit = (unit: string): Formatter => (v) => {
  const n = asNumber(v);
  return n === null ? null : `${n} ${unit}`;
};

export const WINE: WineConfig = {
  brandName: "AuraCave",
  kicker: "AuraCave AI",
  headline: ["Le bon vin,", "au bon moment."],
  intro:
    "Décrivez le repas ou l'occasion. Je sélectionne des vins du monde entier dans le catalogue, vérifie la cohérence (origine, cépages, millésime, accord avec le plat) et prépare votre commande, sans jamais voir vos données personnelles.",
  demoMessage: "Je cherche 2 bouteilles de rouge pour accompagner une côte de bœuf, budget 600€.",
  suggestions: [
    "Un blanc vif pour des huîtres, budget 80€",
    "Un rouge italien puissant pour un osso buco, 100€",
    "Un liquoreux pour un foie gras, budget 150€",
  ],
  placeholder: "Décrivez l'occasion ou le plat, et votre budget…",
  loadingText: "Consultation de la cave…",
  defaultBudgetCents: 60_000,
  noun: {
    singular: "sélection",
    plural: "sélections",
    comparisonTitle: "Propositions du sommelier",
    comparisonSubtitle: "Cohérence vérifiée par le moteur déterministe : origine, cépages, millésime, accord avec le plat.",
    recommended: "Coup de cœur",
  },
  roleLabels: { wine: "Vin", accessory: "Accessoire" },
  categoryLabels: { wine: "Vin", accessory: "Accessoire" },
  cartGroups: [
    { key: "wine", label: "Vins", categories: ["wine"] },
    { key: "accessory", label: "Accessoires" },
  ],
  aggregates: [{ label: "Bouteilles", kind: "count", categories: ["wine"], format: (n) => String(n) }],
  badgeKey: "region",
  gauges: [
    { key: "tannin", label: "Tanins", max: 5 },
    { key: "acidity", label: "Acidité", max: 5 },
    { key: "body", label: "Corps", max: 5 },
    { key: "sweetness", label: "Sucre", max: 5 },
  ],
  visual: {
    kind: "bottle",
    categories: ["wine"],
    colorKey: "type",
    palette: {
      rouge: "#6b1f2c",
      blanc: "#c8b560",
      "rosé": "#d9818f",
      effervescent: "#d8c98a",
      liquoreux: "#b98128",
      "fortifié": "#6a3519",
    },
    fallbackColor: "#4a3b32",
  },
  headlineKeys: { wine: ["cepages", "vintage"], accessory: ["kind"], default: [] },
  attributeLabels: {
    type: "Type",
    country: "Pays",
    region: "Région",
    appellation: "Appellation",
    producer: "Producteur",
    cepages: "Cépages",
    vintage: "Millésime",
    classification: "Classification",
    body: "Corps",
    tannin: "Tanins",
    acidity: "Acidité",
    sweetness: "Sucre",
    serving_temp_c: "Service",
    use: "Moment",
    food_pairing: "Accords mets-vins",
    tasting_notes: "Profil sensoriel",
    alcohol_degree: "Degré d'alcool",
    volume_cl: "Contenance",
    drinking_window: "À boire",
    kind: "Type d'accessoire",
    capacity_cl: "Contenance",
    capacity_bottles: "Bouteilles",
    pieces: "Pièces",
    material: "Matière",
    suited_for: "Convient à",
  },
  order: ["type", "country", "region", "appellation", "cepages", "vintage", "body", "tannin", "acidity", "sweetness", "food_pairing", "tasting_notes", "alcohol_degree", "serving_temp_c", "volume_cl", "use", "classification", "drinking_window"],
  hidden: [],
  virtual: [],
  formatters: {
    alcohol_degree: (v) => (asNumber(v) ? `${String(v).replace(".", ",")} % vol.` : null),
    // Structure notée de 1 à 5 dans le jeu de données.
    body: (v) => (asNumber(v) ? `${v}/5` : null),
    tannin: (v) => (asNumber(v) ? `${v}/5` : null),
    acidity: (v) => (asNumber(v) ? `${v}/5` : null),
    sweetness: (v) => (asNumber(v) ? `${v}/5` : null),
    serving_temp_c: (v) => {
      const t = v as { min?: number; max?: number } | null;
      if (!t || typeof t.min !== "number" || typeof t.max !== "number") return null;
      return t.min === t.max ? `${t.min} °C` : `${t.min}–${t.max} °C`;
    },
    // Provenance technique (identifiant du jeu de données, prix source) : jamais affichée.
    source: () => null,
    volume_cl: withUnit("cl"),
    capacity_cl: withUnit("cl"),
    drinking_window: (v) => {
      const w = v as { from?: number; until?: number } | null;
      return w && typeof w.from === "number" && typeof w.until === "number" ? `${w.from}–${w.until}` : null;
    },
  },
  metrics: { bottles: { label: "Bouteilles", format: (v) => (asNumber(v) ? String(v) : null) } },
  details: {
    pairing: {
      label: "Accord",
      format: (v) => {
        const p = v as { dish?: string; matches?: string[]; mismatches?: string[] } | null;
        if (!p?.dish) return null;
        const ok = p.matches?.length ?? 0;
        const total = ok + (p.mismatches?.length ?? 0);
        return total > 0 ? `${p.dish} · ${ok}/${total} accordé${ok > 1 ? "s" : ""}` : p.dish;
      },
    },
    serving_year: { label: "Année de service", format: (v) => (asNumber(v) ? String(v) : null) },
  },
  askPlaceholder: "Ex. : avec quel plat le servir ?",
  engineNote: "Cohérence vérifiée par le moteur déterministe : appellation, cépages, millésime, accord avec le plat.",
};
