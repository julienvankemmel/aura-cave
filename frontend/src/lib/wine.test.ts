import { describe, expect, it } from "vitest";

import { WINE } from "./wine";

describe("wine vocabulary", () => {
  it("provides the copy, budget and rated indicators", () => {
    expect(WINE.brandName).toBe("AuraCave");
    expect(WINE.demoMessage).toMatch(/bouteilles/);
    expect(WINE.noun.singular).toBe("sélection");
    expect(WINE.noun.comparisonTitle).toBe("Propositions du sommelier");
    expect(WINE.defaultBudgetCents).toBe(60_000);
    expect(WINE.cartGroups.at(-1)?.categories).toBeUndefined(); // groupe de repli
    expect(WINE.gauges.map((g) => g.key)).toEqual(["tannin", "acidity", "body", "sweetness"]);
  });
});
