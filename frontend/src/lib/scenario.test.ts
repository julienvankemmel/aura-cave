import { describe, expect, it } from "vitest";

import { addSelectionToCartMessage, chooseSelectionMessage, DECLINE_ACCESSORIES_MESSAGE, idTag, showAccessoriesMessage, stripIdTags } from "./scenario";

const BOTTLE = "6f1c2a54-3b8e-4f0a-9d71-0a5c8e2b7d13";
const ACCESSORY = "7e0b9ad9-7484-5f39-83e5-1abac1fd0017";

describe("conversation messages", () => {
  it("choosing a selection carries every item id but never asks to add to the cart", () => {
    const message = chooseSelectionMessage(1, [BOTTLE, ACCESSORY]);

    expect(message).toBe(`Je choisis la sélection 2 ${idTag(BOTTLE)} ${idTag(ACCESSORY)}`);
    expect(message.toLowerCase()).not.toMatch(/ajout|panier/);
  });

  it("works for a single bottle as well as for a bottle + accessory pair", () => {
    expect(chooseSelectionMessage(0, [BOTTLE])).toBe(`Je choisis la sélection 1 ${idTag(BOTTLE)}`);
  });

  it("adding the chosen selection is an explicit cart request with all ids", () => {
    const message = addSelectionToCartMessage([BOTTLE, ACCESSORY]);

    expect(message).toMatch(/Ajoute au panier/);
    expect(message).toContain(idTag(BOTTLE));
    expect(message).toContain(idTag(ACCESSORY));
  });

  it("id markers are hidden from what the customer sees", () => {
    expect(stripIdTags(chooseSelectionMessage(0, [BOTTLE, ACCESSORY]))).toBe("Je choisis la sélection 1");
  });

  it("asks for accessories explicitly, with the ids of the chosen bottles", () => {
    const message = showAccessoriesMessage([BOTTLE]);

    expect(message).toBe(`Montre-moi les accessoires compatibles avec ma sélection ${idTag(BOTTLE)}`);
    expect(stripIdTags(message)).toBe("Montre-moi les accessoires compatibles avec ma sélection");
  });

  it("tells the agent the customer declines accessories, without mentioning payment", () => {
    expect(DECLINE_ACCESSORIES_MESSAGE).toMatch(/non merci/i);
    expect(DECLINE_ACCESSORIES_MESSAGE).not.toMatch(/command|pay/i);
  });
});
