/** Marqueur lisible par l'agent pour désigner un produit précis ; masqué dans l'interface. */
export function idTag(id: string): string {
  return `[id:${id}]`;
}

export function addToCartMessage(name: string, id: string): string {
  return `Ajoute au panier : ${name} ${idTag(id)}`;
}

/** Étape 3 : le client choisit une sélection ; l'agent l'ajoute directement au panier puis propose (sans l'imposer) des accessoires. */
export function chooseSelectionMessage(index: number, itemIds: string[]): string {
  return `Je choisis la sélection ${index + 1} ${itemIds.map(idTag).join(" ")}`;
}

/** Le client souhaite voir les accessoires adaptés aux bouteilles de sa sélection (jamais demandés automatiquement). */
export function showAccessoriesMessage(itemIds: string[]): string {
  return `Montre-moi les accessoires compatibles avec ma sélection ${itemIds.map(idTag).join(" ")}`;
}

/** Le client ne veut pas d'accessoires : l'agent en prend acte et propose de passer à la commande. */
export const DECLINE_ACCESSORIES_MESSAGE = "Non merci, pas d'accessoires";

/** Étape 4 : ajout explicite des produits de la sélection choisie au panier. */
export function addSelectionToCartMessage(itemIds: string[]): string {
  return `Ajoute au panier la sélection choisie ${itemIds.map(idTag).join(" ")}`;
}

export function askAboutMessage(name: string, id: string, question: string): string {
  return `À propos de ${name} ${idTag(id)} : ${question}`;
}

export function stripIdTags(text: string): string {
  return text.replace(/\s*\[id:[0-9a-fA-F-]{36}\]/g, "");
}
