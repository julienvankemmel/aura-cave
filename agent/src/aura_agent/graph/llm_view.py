"""Vue compacte des résultats d'outils envoyée au LLM.

Les événements UI portent les données complètes ; le LLM n'a besoin que de l'essentiel. Réduire les tokens
évite d'épuiser les quotas gratuits (ex. 8 000 tokens/min chez Groq) et accélère chaque tour.

Les produits sont génériques (socle + `attributes`) ; la liste des attributs utiles au LLM est celle du vin.
"""

import json
from typing import Any

# Attributs du vin utiles à la conversation.
_LLM_ATTRIBUTES: tuple[str, ...] = (
    "type",
    "country",
    "region",
    "appellation",
    "cepages",
    "vintage",
    "body",
    "tannin",
    "acidity",
    "sweetness",
    "alcohol_degree",
    "food_pairing",
    "tasting_notes",
)
_ACCESSORY_KEYS = ("id", "sku", "name", "price_cents")
_MAX_ISSUE_CHARS = 140
# Outils dont le dernier résultat reste utile aux tours suivants (ids de sélections, contenu du panier).
KEEP_ACROSS_TURNS = frozenset(
    {"verify_and_compare_selections_tool", "propose_selections_tool", "update_cart_tool", "remove_from_cart_tool"}
)


def _pick(source: dict[str, Any], keys: tuple[str, ...]) -> dict[str, Any]:
    return {k: source[k] for k in keys if source.get(k) not in (None, [], "")}


def _drinking_window(attributes: dict[str, Any]) -> str | None:
    window = attributes.get("drinking_window")
    if isinstance(window, dict) and window.get("from") and window.get("until"):
        return f"{window['from']}-{window['until']}"
    return None


def _attributes(attributes: dict[str, Any], keys: tuple[str, ...]) -> dict[str, Any]:
    view = _pick(attributes, keys)
    window = _drinking_window(attributes)
    if window:
        view["drinking_window"] = window
    return view


def _product(source: dict[str, Any]) -> dict[str, Any]:
    view = _pick(source, ("id", "sku", "name", "category", "price_cents"))
    if "stock" in source:
        view["stock"] = source["stock"]
    view.update(_attributes(source.get("attributes") or {}, _LLM_ATTRIBUTES))
    return view


def _item(item: dict[str, Any]) -> dict[str, Any]:
    view = _pick(item, ("role", "id", "name", "price_cents"))
    attributes = item.get("attributes") or {}
    role = item.get("role")
    if role == "wine":
        view.update(_attributes(attributes, _LLM_ATTRIBUTES))
        if "stock" in item:
            view["stock"] = item["stock"]
    return view


def _details(details: dict[str, Any]) -> dict[str, Any]:
    view = {k: v for k, v in details.items() if v not in (None, [], {})}
    pairing = view.get("pairing")
    if isinstance(pairing, dict):
        view["pairing"] = {
            k: pairing[k] for k in ("dish", "score", "matches", "mismatches") if pairing.get(k) not in (None, [])
        }
    return view


def _selection(selection: dict[str, Any]) -> dict[str, Any]:
    issues = [
        {"severity": i["severity"], "message": str(i["message"])[:_MAX_ISSUE_CHARS]}
        for i in selection.get("issues", [])
        if i.get("severity") in ("error", "warning")
    ]
    return {
        "label": selection.get("label"),
        "items": [_item(i) for i in selection.get("items", [])],
        "total_cents": selection.get("total_cents"),
        "metrics": selection.get("metrics") or {},
        "details": _details(selection.get("details") or {}),
        "compatible": selection.get("compatible"),
        "within_budget": selection.get("within_budget"),
        **({"issues": issues} if issues else {}),
    }


def llm_view(tool_name: str, result: dict[str, Any]) -> str:
    """JSON compact d'un résultat d'outil, tel que le LLM (et le mock) le lit."""
    if "error" in result:
        view: dict[str, Any] = result
    elif tool_name == "search_catalog_tool":
        view = {"results": [_product(p) for p in result.get("results", [])]}
    elif tool_name in ("verify_and_compare_selections_tool", "propose_selections_tool"):
        selections = result.get("selections", [])
        index = result.get("recommended_index")
        view = {
            "selections": [_selection(c) for c in selections],
            "recommended_index": index,
            # Libellé explicite : un index 0-based est souvent lu comme un numéro (index 1 = « Sélection 1 »).
            "recommended_label": selections[index]["label"]
            if index is not None and 0 <= index < len(selections)
            else None,
            **({"reason": result["reason"]} if result.get("reason") else {}),
        }
    elif tool_name == "recommend_compatible_accessories_tool":
        view = {
            "groups": [
                {
                    "key": g["key"],
                    "label": g["label"],
                    "items": [_pick(p, _ACCESSORY_KEYS) for p in g.get("items", [])],
                }
                for g in result.get("groups", [])
            ]
        }
    elif tool_name in ("update_cart_tool", "remove_from_cart_tool"):
        view = {
            "total_cents": result.get("total_cents"),
            "items": [
                _pick(i, ("product_id", "name", "quantity", "unit_price_cents")) for i in result.get("items", [])
            ],
            "compatibility_ok": result.get("compatibility_ok"),
            "issues": [
                str(i["message"])[:_MAX_ISSUE_CHARS] for i in result.get("issues", []) if i.get("severity") == "error"
            ],
        }
    else:
        view = result
    return json.dumps(view, ensure_ascii=False, separators=(",", ":"))
