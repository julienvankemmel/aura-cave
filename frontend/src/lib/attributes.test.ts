import { describe, expect, it } from "vitest";

import { attributeRows, badgeValue, bottleColor, categoryLabel, gaugeReadings, headline, humanize, imageUrl, roleLabel, selectionRows, specRows } from "./attributes";
import type { Product } from "./types";

const asMap = (p: Product) => Object.fromEntries(specRows(p));

describe("generic attribute rendering", () => {
  const wine: Product = {
    id: "w",
    sku: "PETRUS-2014",
    name: "Pétrus 2014",
    brand: "Pétrus",
    category: "wine",
    price_cents: 320000,
    stock: 2,
    attributes: {
      type: "rouge",
      region: "Bordeaux",
      appellation: "Pomerol",
      cepages: ["Merlot"],
      vintage: 2014,
      food_pairing: ["gibier", "canard"],
      alcohol_degree: 14,
      drinking_window: { from: 2026, until: 2070 },
      tasting_notes: "Truffe noire.",
      country: "France",
      body: 4,
      tannin: 3,
      serving_temp_c: { min: 16, max: 18 },
      source: { dataset: "kaggle:dev7halo/wine-information", id: 137197, price_krw: 220000, derived: ["food_pairing"] },
    },
  };

  it("walks the attributes dynamically with wine labels", () => {
    const rows = asMap(wine);

    expect(rows["Région"]).toBe("Bordeaux");
    expect(rows["Appellation"]).toBe("Pomerol");
    expect(rows["Cépages"]).toBe("Merlot");
    expect(rows["Millésime"]).toBe("2014");
    expect(rows["Accords mets-vins"]).toBe("gibier · canard");
    expect(rows["Degré d'alcool"]).toBe("14 % vol.");
    expect(rows["À boire"]).toBe("2026–2070");
    expect(rows["Stock"]).toBe("2 restants");
    expect(rows["Pays"]).toBe("France");
    expect(rows["Corps"]).toBe("4/5");
    expect(rows["Tanins"]).toBe("3/5");
    expect(rows["Service"]).toBe("16–18 °C");
    expect(rows["Profil sensoriel"]).toBe("Truffe noire.");
    expect(Object.keys(rows).some((label) => /dataset|price|source/i.test(label))).toBe(false);
    expect(headline(wine)).toBe("Merlot · 2014");
    expect(badgeValue(wine)).toBe("Bordeaux");
    expect(categoryLabel("wine")).toBe("Vin");
    expect(roleLabel("wine")).toBe("Vin");
  });

  it("humanizes unknown keys and flattens unknown nested blocks", () => {
    const rows = attributeRows({ some_new_key: "x", bloc: { inner_value: 3 } });

    expect(rows.map((r) => [r.label, r.value])).toEqual([
      ["Some new key", "x"],
      ["Inner value", "3"],
    ]);
    expect(humanize("weather_sealed")).toBe("Weather sealed");
  });

  it("renders metrics and details, ignoring unknown complex objects", () => {
    expect(
      selectionRows({ bottles: 2 }, { pairing: { dish: "agneau", matches: ["a", "b"], mismatches: [] }, serving_year: 2026, mystery: { a: 1 } }),
    ).toEqual([
      ["Bouteilles", "2"],
      ["Accord", "agneau · 2/2 accordés"],
      ["Année de service", "2026"],
    ]);
  });
});

describe("gauges, images and bottle colours", () => {
  it("builds gauge readings from the wine structure, skipping missing notes", () => {
    const red = gaugeReadings([{ tannin: 4, acidity: 3, body: 5, sweetness: 1 }]);
    const white = gaugeReadings([{ acidity: 5, body: 2, sweetness: 1 }]);

    expect(red.map((r) => [r.key, r.label, r.value, r.max])).toEqual([
      ["tannin", "Tanins", 4, 5],
      ["acidity", "Acidité", 3, 5],
      ["body", "Corps", 5, 5],
      ["sweetness", "Sucre", 1, 5],
    ]);
    expect(white.map((r) => r.key)).toEqual(["acidity", "body", "sweetness"]);
  });

  it("averages several bottles and keeps the value within 1..max", () => {
    const readings = gaugeReadings([{ tannin: 4, body: 3 }, { tannin: 3, body: 9 }, { body: 0 }]);

    const tannin = readings.find((r) => r.key === "tannin")!;
    expect(tannin.value).toBe(3.5);
    expect(tannin.count).toBe(2);
    expect(readings.find((r) => r.key === "body")!.value).toBe(3); // (3 + 5 + 1) / 3 : 9 et 0 sont ramenés à 5 et 1
    expect(gaugeReadings([{ tannin: 99 }])[0]!.value).toBe(5);
    expect(gaugeReadings([{ tannin: -3 }])[0]!.value).toBe(1);
  });

  it("ignores non numeric values and unrelated attributes", () => {
    expect(gaugeReadings([{ region: "Bordeaux", vintage: "x" }])).toEqual([]);
    expect(gaugeReadings([{ tannin: "fort", acidity: null }])).toEqual([]);
  });

  it("only accepts local or https image urls", () => {
    expect(imageUrl({ image_url: "/bottles/clos.webp" })).toBe("/bottles/clos.webp");
    expect(imageUrl({ image_url: " https://cdn.example.fr/a.jpg " })).toBe("https://cdn.example.fr/a.jpg");
    for (const bad of ["javascript:alert(1)", "data:image/png;base64,AAAA", "//evil.example/a.png", "http://example.fr/a.png", "ftp://x/y", "", 42, null]) {
      expect(imageUrl({ image_url: bad })).toBeNull();
    }
    expect(imageUrl({})).toBeNull();
  });

  it("draws a coloured bottle for wines only", () => {
    expect(bottleColor("wine", { type: "rouge" })).toBe("#6b1f2c");
    expect(bottleColor("wine", { type: "blanc" })).not.toBe(bottleColor("wine", { type: "rouge" }));
    expect(bottleColor("wine", { type: "inconnu" })).toBe("#4a3b32");
    expect(bottleColor("accessory", { type: "rouge" })).toBeNull();
    expect(bottleColor("body", {})).toBeNull();
  });
});
