"""Outils MCP : implémentations partagées par le graphe LangGraph et le serveur FastMCP.

Les outils manipulent des produits génériques (`attributes`) et délèguent les règles propres au vin (assemblage de
sélections, accessoires, recommandation) à `aura_agent.mcp.bundles`.
"""

import asyncio
import uuid
from collections.abc import Awaitable, Callable
from dataclasses import dataclass
from typing import Any, Literal

import httpx
from pydantic import BaseModel, ConfigDict, Field

from aura_agent.mcp.backend_client import (
    AddItemRequest,
    AttributeFilter,
    BackendClient,
    BackendError,
    CartSummary,
    ProductOut,
    SearchRequest,
    VerifySelectionRequest,
)
from aura_agent.mcp.bundles import ProposeParams, WineBundles

MAX_SELECTIONS = 3
MAX_BOTTLES = 6
_UUID = r"^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}$"


class ToolInputError(Exception):
    """Entrée d'outil invalide (ex. produit inconnu du cache) — message sûr, sans donnée utilisateur."""


class BackendTools:
    def __init__(self, backend: BackendClient) -> None:
        self._backend = backend
        self._bundles = WineBundles()

    # --- recherche -------------------------------------------------------------------------------
    async def search_catalog_tool(
        self,
        query: str,
        budget_cents: int | None = None,
        category: str | None = None,
        limit: int = 5,
        categories: list[str] | None = None,
        filters: list[AttributeFilter | dict[str, Any]] | None = None,
    ) -> dict[str, Any]:
        """Recherche sémantique dans le catalogue. Symfony calcule l'embedding 384d via l'endpoint
        /internal/embed de cet agent (même modèle fastembed), puis applique les filtres stricts (budget, catégorie,
        `filters` sur les attributs).

        `categories` lance une recherche par catégorie en parallèle : un seul appel d'outil (donc un seul tour de
        LLM) suffit pour préparer plusieurs listes."""
        wanted: list[str | None] = list(dict.fromkeys(categories)) if categories else [category]
        attribute_filters = _filters(filters)
        requests = [
            SearchRequest(
                query=query,
                budget_cents=budget_cents,
                category=c,
                limit=limit,
                filters=attribute_filters,
            )
            for c in wanted
        ]
        responses = await asyncio.gather(*(self._backend.search(r) for r in requests))
        return {
            "strategy": responses[0].strategy,
            "results": [p.slim() for response in responses for p in response.results],
        }

    # --- comparatif ------------------------------------------------------------------------------
    async def verify_and_compare_selections_tool(
        self,
        selections: list[dict[str, Any]],
        budget_cents: int | None = None,
        dish: str | None = None,
        serving_year: int | None = None,
    ) -> dict[str, Any]:
        """Comparatif structuré de 1 à 3 sélections (listes de produits) vérifiés par le moteur."""
        if not 1 <= len(selections) <= MAX_SELECTIONS:
            raise ToolInputError("1 à 3 sélections attendus")
        bundles = self._bundles
        rows: list[dict[str, Any]] = []
        for index, selection in enumerate(selections):
            items = [self._known_product(i) for i in selection.get("item_ids", [])]
            if not items:
                raise ToolInputError("item_ids vide")
            report = await self._backend.verify_selection(
                VerifySelectionRequest(
                    item_ids=[uuid.UUID(p.id) for p in items],
                    budget_cents=budget_cents,
                    dish=dish,
                    serving_year=serving_year,
                )
            )
            rows.append(
                {
                    "label": f"Sélection {index + 1}",
                    "items": [_item(p, bundles.role(p)) for p in items],
                    "total_cents": report.total_cents,
                    "metrics": bundles.metrics(items),
                    "details": bundles.details(items, report),
                    "compatible": report.compatible,
                    "within_budget": report.within_budget,
                    "issues": [i.model_dump() for i in bundles.issues(items, report.issues)],
                }
            )
        return {"selections": rows, "recommended_index": bundles.recommend(rows)}

    # --- selections ---------------------------------------------------------------------------------
    async def propose_selections_tool(
        self,
        query: str,
        budget_cents: int | None = None,
        filters: list[AttributeFilter | dict[str, Any]] | None = None,
        dish: str | None = None,
        bottles: int = 1,
        serving_year: int | None = None,
        limit: int = MAX_SELECTIONS,
    ) -> dict[str, Any]:
        """Seul outil à utiliser pour une sélection : le moteur assemble des ensembles de bouteilles, Symfony les
        vérifie, et seul le comparatif est renvoyé (jamais de pièces seules)."""
        selections, reason = await self._bundles.propose(
            self._backend,
            ProposeParams(
                query=query,
                budget_cents=budget_cents,
                filters=_filters(filters),
                bottles=max(1, min(bottles, MAX_BOTTLES)),
                limit=max(1, min(limit, MAX_SELECTIONS)),
                dish=dish,
            ),
        )
        if not selections:
            return {"selections": [], "recommended_index": None, "reason": reason}
        return await self.verify_and_compare_selections_tool(
            [{"item_ids": [p.id for p in selection]} for selection in selections],
            budget_cents,
            dish,
            serving_year,
        )

    # --- accessoires -----------------------------------------------------------------------------
    async def recommend_compatible_accessories_tool(self, item_ids: list[str]) -> dict[str, Any]:
        """Accessoires compatibles avec la sélection choisie (carafe, verres, tire-bouchon…)."""
        items = [self._known_product(i) for i in item_ids]
        return await self._bundles.accessories(self._backend, items)

    # --- panier & livraison ----------------------------------------------------------------------
    async def update_cart_tool(self, cart_id: str, product_id: str, quantity: int = 1) -> dict[str, Any]:
        summary = await self._backend.add_item(
            _cart_uuid(cart_id), AddItemRequest(product_id=uuid.UUID(product_id), quantity=quantity)
        )
        return _cart_result(summary)

    async def remove_from_cart_tool(self, cart_id: str, product_id: str) -> dict[str, Any]:
        """Retire entièrement un produit du panier (ex. changement de selection) et renvoie le panier à jour."""
        summary = await self._backend.remove_item(_cart_uuid(cart_id), uuid.UUID(product_id))
        return _cart_result(summary)

    async def start_checkout_tool(self, cart_id: str) -> dict[str, Any]:
        """Démarre la collecte des coordonnées de livraison dans le chat (une donnée par message, côté Symfony)."""
        turn = await self._backend.start_checkout(_cart_uuid(cart_id))
        return {"status": "checkout_started", "step": turn.step, "instruction": turn.instruction}

    async def edit_checkout_tool(
        self, cart_id: str, field: Literal["name", "address", "phone", "email"]
    ) -> dict[str, Any]:
        """Le client veut corriger une information de livraison déjà donnée : Symfony rouvre ce champ (collecte en cours
        ou commande déjà créée) ; la nouvelle valeur est ressaisie dans le canal sécurisé, jamais lue par le LLM."""
        turn = await self._backend.edit_checkout(_cart_uuid(cart_id), field)
        return {"status": "checkout_reopened", "step": turn.step, "instruction": turn.instruction}

    async def checkout_hint(self, cart_id: str) -> str | None:
        """Consigne serveur de l'étape de checkout en cours, ou None. Jamais bloquant pour la conversation."""
        try:
            hint = await self._backend.checkout_hint(_cart_uuid(cart_id))
        except (ToolInputError, BackendError, httpx.HTTPError):
            return None
        return hint.instruction if hint.instruction else None

    # --- utilitaires -----------------------------------------------------------------------------
    def cart_total(self, cart_id: str) -> int:
        return self._backend.cart_totals.get(cart_id, 0)

    def _known_product(self, product_id: object) -> ProductOut:
        product = self._backend.products.get(str(product_id))
        if product is None:
            raise ToolInputError("produit inconnu : lancez d'abord search_catalog_tool")
        return product


def _filters(value: list[AttributeFilter | dict[str, Any]] | None) -> list[AttributeFilter]:
    return [f if isinstance(f, AttributeFilter) else AttributeFilter.model_validate(f) for f in value or []]


def _cart_result(summary: CartSummary) -> dict[str, Any]:
    return {
        "cart_id": summary.cart_id,
        "total_cents": summary.total_cents,
        "items": summary.items,
        "compatibility_ok": summary.compatibility.compatible,
        "issues": [i.model_dump() for i in summary.compatibility.issues],
    }


def _cart_uuid(cart_id: str) -> uuid.UUID:
    parsed = uuid.UUID(cart_id)
    if parsed.version != 4:
        raise ToolInputError("cart_id doit être un UUID v4")
    return parsed


def _item(p: ProductOut, role: str) -> dict[str, Any]:
    """Produit d'une sélection, pour l'interface : socle commun + tous ses attributs (rendus dynamiquement)."""
    return {
        "role": role,
        "id": p.id,
        "sku": p.sku,
        "name": p.name,
        "brand": p.brand,
        "category": p.category,
        "price_cents": p.price_cents,
        "stock": p.stock,
        "attributes": p.attributes,
        # Avis du sommelier (reranking) quand le produit en vient ; rien sinon.
        **({"match_score": p.match_score} if p.match_score is not None else {}),
        **({"sommelier_justification": p.sommelier_justification} if p.sommelier_justification else {}),
        **({"is_coup_de_coeur": p.is_coup_de_coeur} if p.is_coup_de_coeur is not None else {}),
    }


# --- schémas exposés au LLM (cart_id et blob ne le sont jamais) -----------------------------------
class _Args(BaseModel):
    model_config = ConfigDict(extra="forbid")


class FilterArg(_Args):
    key: str = Field(max_length=64, description="Attribut produit, ex. region, type, vintage")
    op: Literal["eq", "in", "gte", "lte", "contains", "any"] = "eq"
    value: str | int | float | bool | list[str | int | float] | None = None
    include_missing: bool = False


class SearchArgs(_Args):
    query: str = Field(min_length=1, max_length=300, description="Besoin exprimé en langage naturel")
    budget_cents: int | None = Field(default=None, gt=0)
    category: str | None = Field(default=None, max_length=32, description="Catégorie de produit")
    limit: int = Field(default=5, ge=1, le=20)
    filters: list[FilterArg] = Field(
        default_factory=list, max_length=12, description="Filtres stricts sur les attributs"
    )


class ProposeArgs(_Args):
    query: str = Field(min_length=1, max_length=300, description="Besoin exprimé en langage naturel")
    budget_cents: int | None = Field(default=None, gt=0, description="Budget total de l'ensemble, en centimes")
    filters: list[FilterArg] = Field(
        default_factory=list, max_length=12, description="Filtres stricts sur les attributs"
    )
    dish: str | None = Field(default=None, max_length=200, description="Plat à accompagner, en quelques mots")
    bottles: int = Field(default=1, ge=1, le=MAX_BOTTLES, description="Nombre de bouteilles par sélection")
    serving_year: int | None = Field(default=None, ge=1900, le=2200)
    limit: int = Field(default=MAX_SELECTIONS, ge=1, le=MAX_SELECTIONS)


class SelectionArg(_Args):
    item_ids: list[str] = Field(min_length=1, max_length=25, description="ids de produits issus des résultats d'outils")


class CompareArgs(_Args):
    selections: list[SelectionArg] = Field(min_length=1, max_length=3)
    budget_cents: int | None = Field(default=None, gt=0)
    dish: str | None = Field(default=None, max_length=200)
    serving_year: int | None = Field(default=None, ge=1900, le=2200)


class AccessoriesArgs(_Args):
    item_ids: list[str] = Field(min_length=1, max_length=25, description="ids des produits de la sélection choisie")


class UpdateCartArgs(_Args):
    product_id: str = Field(pattern=_UUID)
    quantity: int = Field(default=1, ge=1, le=10)


class RemoveFromCartArgs(_Args):
    product_id: str = Field(pattern=_UUID)


class NoArgs(_Args):
    pass


class EditCheckoutArgs(_Args):
    field: Literal["name", "address", "phone", "email"]


_NOISE_KEYS = frozenset({"title", "additionalProperties", "default", "pattern", "maxLength", "minLength"})


def _strip_schema_noise(node: Any) -> Any:
    """Allège le JSON Schema pydantic envoyé au LLM (la validation stricte reste côté serveur) :
    retire titres, défauts, contraintes de forme, docstrings de modèles et `anyOf: [X, null]` -> X."""
    if isinstance(node, list):
        return [_strip_schema_noise(v) for v in node]
    if not isinstance(node, dict):
        return node
    any_of = node.get("anyOf")
    if isinstance(any_of, list) and len(any_of) == 2 and {"type": "null"} in any_of:
        inner = next(v for v in any_of if v != {"type": "null"})
        merged = {**{k: v for k, v in node.items() if k != "anyOf"}, **inner}
        if isinstance(merged.get("type"), str) and merged["type"] in ("integer", "number", "string", "boolean"):
            # Les LLM envoient volontiers `null` pour un argument optionnel : le schéma doit l'accepter
            # (le fournisseur valide l'appel d'outil contre ce schéma avant de nous le transmettre).
            merged["type"] = [merged["type"], "null"]
        return _strip_schema_noise(merged)
    is_model = "properties" in node
    return {
        k: _strip_schema_noise(v)
        for k, v in node.items()
        if (k not in _NOISE_KEYS or k == "properties") and not (k == "description" and is_model)
    }


@dataclass(frozen=True)
class ToolSpec:
    name: str
    description: str
    args_model: type[BaseModel]
    ui_event: (
        Literal["ui_product_card", "ui_bundle_comparison", "ui_accessory_upsell", "ui_checkout_step", "ui_cart_update"]
        | None
    )
    run: Callable[[BackendTools, dict[str, Any], str], Awaitable[dict[str, Any]]]

    def openai_schema(self) -> dict[str, Any]:
        """Schéma envoyé au LLM (bruit JSON Schema retiré : moins de tokens)."""
        parameters = _strip_schema_noise(self.args_model.model_json_schema())
        return {
            "type": "function",
            "function": {"name": self.name, "description": self.description, "parameters": parameters},
        }


async def _run_search(t: BackendTools, a: dict[str, Any], _cart: str) -> dict[str, Any]:
    return await t.search_catalog_tool(**a)


async def _run_propose(t: BackendTools, a: dict[str, Any], _cart: str) -> dict[str, Any]:
    return await t.propose_selections_tool(**a)


async def _run_compare(t: BackendTools, a: dict[str, Any], _cart: str) -> dict[str, Any]:
    return await t.verify_and_compare_selections_tool(**a)


async def _run_accessories(t: BackendTools, a: dict[str, Any], _cart: str) -> dict[str, Any]:
    return await t.recommend_compatible_accessories_tool(**a)


async def _run_cart(t: BackendTools, a: dict[str, Any], cart: str) -> dict[str, Any]:
    return await t.update_cart_tool(cart_id=cart, **a)


async def _run_remove(t: BackendTools, a: dict[str, Any], cart: str) -> dict[str, Any]:
    return await t.remove_from_cart_tool(cart_id=cart, **a)


async def _run_start_checkout(t: BackendTools, _a: dict[str, Any], cart: str) -> dict[str, Any]:
    return await t.start_checkout_tool(cart_id=cart)


async def _run_edit_checkout(t: BackendTools, a: dict[str, Any], cart: str) -> dict[str, Any]:
    return await t.edit_checkout_tool(cart_id=cart, field=a["field"])


TOOL_SPECS: dict[str, ToolSpec] = {
    s.name: s
    for s in (
        ToolSpec(
            "propose_selections_tool",
            "UNIQUE outil pour une sélection, un kit, un pack ou un équipement complet : renvoie uniquement des ensembles "
            "vérifiés (3 max), jamais de pièces seules.",
            ProposeArgs,
            "ui_bundle_comparison",
            _run_propose,
        ),
        ToolSpec(
            "search_catalog_tool",
            "Pièce seule UNIQUEMENT (une référence ou un accessoire) sur demande explicite : budget en centimes, category, `filters`.",
            SearchArgs,
            "ui_product_card",
            _run_search,
        ),
        ToolSpec(
            "verify_and_compare_selections_tool",
            "Compare 1 à 3 sélections (listes d'item_ids issus des résultats) : compatibilité, prix, accords mets-vins.",
            CompareArgs,
            "ui_bundle_comparison",
            _run_compare,
        ),
        ToolSpec(
            "recommend_compatible_accessories_tool",
            "Accessoires compatibles avec la sélection choisie par le client (item_ids de la sélection).",
            AccessoriesArgs,
            "ui_accessory_upsell",
            _run_accessories,
        ),
        ToolSpec(
            "update_cart_tool",
            "Ajoute un produit au panier, UNIQUEMENT si le client le demande.",
            UpdateCartArgs,
            "ui_cart_update",
            _run_cart,
        ),
        ToolSpec(
            "remove_from_cart_tool",
            "Retire un produit du panier, UNIQUEMENT si le client le demande.",
            RemoveFromCartArgs,
            "ui_cart_update",
            _run_remove,
        ),
        ToolSpec(
            "start_checkout_tool",
            "Démarre la commande quand le client demande lui-même à payer ou commander (panier non vide) : les "
            "coordonnées sont ensuite collectées pas à pas dans le chat, via un canal sécurisé.",
            NoArgs,
            "ui_checkout_step",
            _run_start_checkout,
        ),
        ToolSpec(
            "edit_checkout_tool",
            "Le client veut corriger son nom, son adresse, son téléphone ou son email (ex. « je me suis trompé "
            "d'adresse »), même après la commande : rouvre ce champ ; il le ressaisit ensuite lui-même.",
            EditCheckoutArgs,
            "ui_checkout_step",
            _run_edit_checkout,
        ),
    )
}


def tool_schemas() -> list[dict[str, Any]]:
    return [spec.openai_schema() for spec in TOOL_SPECS.values()]


LLM_TOOL_SCHEMAS = tool_schemas()

TOOL_ERRORS: tuple[type[Exception], ...] = (ToolInputError, BackendError)
