"""Assemblage des « sélections » de bouteilles. Le moteur d'outils délègue ici les règles propres au vin."""

import re
import unicodedata
from dataclasses import dataclass, field
from typing import Any

from aura_agent.mcp.backend_client import (
    AttributeFilter,
    BackendClient,
    BackendError,
    CompatibilityReport,
    Issue,
    ProductOut,
    RecommendRequest,
    SearchRequest,
)


@dataclass(frozen=True)
class ProposeParams:
    query: str
    budget_cents: int | None = None
    filters: list[AttributeFilter] = field(default_factory=list)
    bottles: int = 1
    limit: int = 3
    dish: str | None = None


def _group(key: str, label: str, products: list[ProductOut]) -> dict[str, Any]:
    return {"key": key, "label": label, "items": [p.slim() for p in products]}


class WineBundles:
    """Sélections de bouteilles : une bouteille (3 alternatives) ou plusieurs bouteilles complémentaires."""

    def role(self, product: ProductOut) -> str:
        return "wine" if product.category == "wine" else "accessory"

    async def propose(self, backend: BackendClient, params: ProposeParams) -> tuple[list[list[ProductOut]], str | None]:
        """Filtre SQL (stock, budget) + reranking gustatif du sommelier côté Symfony ; en cas d'indisponibilité de ce
        service, recherche sémantique classée localement."""
        try:
            recommended = await backend.recommend(
                RecommendRequest(
                    query=params.query,
                    dish=params.dish,
                    budget_cents=params.budget_cents,
                    limit=_RERANK_POOL if params.bottles > 1 else params.limit,
                    filters=params.filters,
                )
            )
        except BackendError:
            return await self._propose_by_search(backend, params)
        if recommended.status == "no_match":
            return [], "no_wine"
        wines = [w for w in recommended.results if w.stock > 0]
        selections = _wine_selections(wines, params.bottles, params.budget_cents, params.limit)
        if selections:
            return selections, None
        return await self._propose_by_search(backend, params)

    async def _propose_by_search(
        self, backend: BackendClient, params: ProposeParams
    ) -> tuple[list[list[ProductOut]], str | None]:
        response = await backend.search(
            SearchRequest(
                query=params.query,
                budget_cents=params.budget_cents,
                category="wine",
                limit=20,
                filters=params.filters,
                min_similarity=-1,
            )
        )
        wines = [w for w in response.results if w.stock > 0]
        wines = await self._with_red_meat_classics(backend, params, wines)
        if not wines:
            return [], "no_wine"
        wines = _rank_for_dish(wines, params.dish)
        selections = _wine_selections(wines, params.bottles, params.budget_cents, params.limit)
        return (selections, None) if selections else ([], "no_selection_within_budget")

    async def _with_red_meat_classics(
        self, backend: BackendClient, params: ProposeParams, wines: list[ProductOut]
    ) -> list[ProductOut]:
        """Viande rouge ou grillade : les rouges structurés des appellations classiques (Rhône, Graves, Pomerol…) dans
        le budget sont ajoutés aux candidats, car une recherche sémantique seule les noie parmi des milliers de vins.
        Sans effet si le client a déjà précisé une région ou une appellation."""
        if not params.dish or not _words(params.dish) & _RED_MEAT:
            return wines
        if any(f.key in ("region", "appellation", "country") for f in params.filters):
            return wines
        classics = [
            *params.filters,
            AttributeFilter(key="type", op="eq", value="rouge"),
            AttributeFilter(key="tannin", op="gte", value=3),
            AttributeFilter(key="body", op="gte", value=3),
            AttributeFilter(key="appellation", op="in", value=list(_RED_MEAT_APPELLATIONS_DATASET)),
        ]
        try:
            extra = await backend.search(
                SearchRequest(
                    query=params.query,
                    budget_cents=params.budget_cents,
                    category="wine",
                    limit=20,
                    filters=classics,
                    min_similarity=-1,
                )
            )
        except BackendError:
            return wines
        known = {w.id for w in wines}
        return [*(w for w in extra.results if w.stock > 0 and w.id not in known), *wines]

    def metrics(self, items: list[ProductOut]) -> dict[str, Any]:
        wines = [p for p in items if p.category == "wine"]
        return {"bottles": len(wines)}

    def details(self, items: list[ProductOut], report: CompatibilityReport) -> dict[str, Any]:
        return {"pairing": report.pairing, "serving_year": report.serving_year}

    def issues(self, items: list[ProductOut], issues: list[Issue]) -> list[Issue]:
        """Quand le sommelier a jugé l'accord, les avis d'accord de la table de familles (PAIRING_OK / PAIRING_MISMATCH)
        ne s'affichent plus : ils contrediraient son analyse gustative. Un contresens de type (rouge sur poisson) reste
        signalé."""
        wines = [p for p in items if p.category == "wine"]
        if not wines or not all(p.sommelier_justification for p in wines):
            return issues
        return [i for i in issues if i.code not in ("PAIRING_OK", "PAIRING_MISMATCH")]

    def recommend(self, rows: list[dict[str, Any]]) -> int | None:
        """Compatible et dans le budget : la sélection la mieux notée par le sommelier ; sans avis du sommelier, celle
        sans avertissement d'accord ou de maturité ; sinon la première valide."""
        valid = [(i, r) for i, r in enumerate(rows) if r["compatible"] and r["within_budget"]]
        if not valid:
            return None
        rated = [(i, _sommelier_average(r)) for i, r in valid]
        if all(score is not None for _, score in rated):
            return max(rated, key=lambda t: (t[1] or 0.0, -t[0]))[0]
        clean = [t for t in valid if not any(i["severity"] == "warning" for i in t[1]["issues"])]
        return (clean or valid)[0][0]

    async def accessories(self, backend: BackendClient, items: list[ProductOut]) -> dict[str, Any]:
        """Carafes, verres, tire-bouchon adaptés aux types de vin choisis."""
        types = {str(p.attr("type")) for p in items if p.category == "wine" and p.attr("type")}
        found = await backend.search(
            SearchRequest(query="accessoire de service du vin", category="accessory", limit=20)
        )
        suited = [
            p for p in found.results if not types or not p.attr("suited_for") or types & set(p.attr("suited_for") or [])
        ]
        return {
            "item_ids": [p.id for p in items],
            "groups": [_group("accessories", "Pour servir et conserver", suited)],
        }


# Pool de vins classés demandé au sommelier quand une sélection compte plusieurs bouteilles (assemblage en aval).
_RERANK_POOL = 8


def _sommelier_average(row: dict[str, Any]) -> float | None:
    scores = [
        i["match_score"] for i in row.get("items", []) if i.get("role") == "wine" and i.get("match_score") is not None
    ]
    return sum(scores) / len(scores) if scores else None


_FISH = frozenset({"poisson", "huitre", "saumon", "sole", "turbot", "homard", "crustace", "coquillage", "mer", "sushi"})
# Viandes rouges et grillades : une seule famille d'accords (mots sans accents, singuliers).
_RED_MEAT = frozenset(
    {
        "viande",
        "boeuf",
        "entrecote",
        "steak",
        "bavette",
        "onglet",
        "rumsteck",
        "rosbif",
        "chateaubriand",
        "tournedos",
        "agneau",
        "mouton",
        "gigot",
        "cotelette",
        "grillade",
        "barbecue",
        "bbq",
        "mechoui",
        "merguez",
    }
)
# Tanins solides mais soyeux, épices et fruits noirs : les classiques de la viande rouge grillée.
_RED_MEAT_APPELLATIONS = (
    "Saint-Joseph",
    "Crozes-Hermitage",
    "Hermitage",
    "Cornas",
    "Gigondas",
    "Vacqueyras",
    "Châteauneuf-du-Pape",
    "Côtes du Rhône",
    "Graves",
    "Pessac-Léognan",
    "Pomerol",
    "Saint-Émilion",
)
# Écriture de ces appellations dans le jeu de données (sans accents) : filtre exact `in` côté Symfony.
_RED_MEAT_APPELLATIONS_DATASET = (
    "Saint-Joseph",
    "Crozes-Hermitage",
    "Hermitage",
    "Cornas",
    "Gigondas",
    "Vacqueyras",
    "Chateauneuf-du-Pape",
    "Cotes du Rhone",
    "Cotes du Rhone Villages",
    "Graves",
    "Pessac-Leognan",
    "Pomerol",
    "Saint-Emilion",
)
_SWEET_DISH = frozenset({"dessert", "foie", "gras", "roquefort", "tarte", "chocolat", "fromage"})


def _words(text: str) -> set[str]:
    """Mots significatifs sans accents, singularisés grossièrement (« huîtres » -> « huitre »)."""
    folded = unicodedata.normalize("NFKD", text.lower()).encode("ascii", "ignore").decode()
    words = {w for w in re.split(r"[^a-z]+", folded) if len(w) >= 3}
    return {w[:-1] if len(w) > 4 and w.endswith("s") else w for w in words}


def _rank_for_dish(wines: list[ProductOut], dish: str | None) -> list[ProductOut]:
    """Avec un plat connu, les vins dont les accords le citent passent devant ; un type en contresens passe après.

    Viandes rouges et grillades forment une seule famille (mouton = agneau = entrecôte = viande rouge…), comme dans le
    moteur de Symfony, qui reste l'arbitre final (avertissements PAIRING_*) : ce classement évite seulement de proposer
    d'emblée un liquoreux pour une côte de bœuf quand la sémantique seule ne sait pas départager. Pour ces viandes,
    un rouge à tanins solides et corps moyen à puissant passe devant, et les appellations réputées pour leurs notes
    épicées et de fruits noirs (Rhône, Graves, Pomerol) reçoivent un léger avantage."""
    if not dish:
        return wines
    dish_words = _words(dish)
    red_meat = bool(dish_words & _RED_MEAT)

    def adjusted(w: ProductOut) -> float:
        pairing_words = _words(" ".join(str(p) for p in w.attr("food_pairing") or []))
        matched = bool(dish_words & pairing_words) or (red_meat and bool(pairing_words & _RED_MEAT))
        bonus = 0.5 if matched else 0.0
        kind = str(w.attr("type") or "")
        clash = (kind == "rouge" and bool(dish_words & _FISH)) or (kind == "liquoreux" and not dish_words & _SWEET_DISH)
        if red_meat and kind == "rouge":
            if (w.attr("tannin") or 0) >= 3 and (w.attr("body") or 0) >= 3:
                bonus += 0.3
            appellation = _words(str(w.attr("appellation") or ""))
            if any(_words(name) <= appellation for name in _RED_MEAT_APPELLATIONS):
                bonus += 0.2
        return (w.score or 0.0) + bonus - (1.0 if clash else 0.0)

    return sorted(wines, key=lambda w: (-adjusted(w), w.price_cents))


def _variety_key(w: ProductOut) -> str:
    """Ce qui rend deux bouteilles « différentes » dans une sélection : appellation, à défaut région, à défaut producteur."""
    return str(w.attr("appellation") or w.attr("region") or w.brand)


def _wine_selections(
    wines: list[ProductOut], bottles: int, budget_cents: int | None, limit: int
) -> list[list[ProductOut]]:
    """Une bouteille : les `limit` meilleures. Plusieurs : sélections variées (appellations ou régions distinctes) dans le budget."""
    ranked = wines  # déjà classés (pertinence sémantique, accord avec le plat)
    if bottles <= 1:
        return [[w] for w in ranked[:limit] if budget_cents is None or w.price_cents <= budget_cents]
    selections: list[list[ProductOut]] = []
    for start in range(len(ranked)):
        if len(selections) == limit:
            break
        picked = [ranked[start]]
        seen = {_variety_key(ranked[start])}
        for w in ranked:
            if len(picked) == bottles:
                break
            variety = _variety_key(w)
            total = sum(p.price_cents for p in picked) + w.price_cents
            if w.id in {p.id for p in picked} or variety in seen or (budget_cents is not None and total > budget_cents):
                continue
            picked.append(w)
            seen.add(variety)
        if len(picked) < bottles or any({p.id for p in picked} == {q.id for q in s} for s in selections):
            continue
        if budget_cents is None or sum(p.price_cents for p in picked) <= budget_cents:
            selections.append(picked)
    return selections
