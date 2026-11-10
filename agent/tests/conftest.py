import json
import uuid
from collections.abc import Iterator
from typing import Any

import httpx
import pytest
import respx
from fastapi.testclient import TestClient
from pydantic import SecretStr

from aura_agent.config import Settings
from aura_agent.main import create_app

BACKEND = "http://backend.test"
API_KEY = "test-internal-key"
CART_ID = str(uuid.UUID("6f1c2a54-3b8e-4f0a-9d71-0a5c8e2b7d13"))


def make_settings(**overrides: Any) -> Settings:
    base: dict[str, Any] = {
        "backend_url": BACKEND,
        "internal_api_key": SecretStr(API_KEY),
        "llm_provider": "mock",
        "fastembed_mock": True,
        "llm_requests_per_second": 1000.0,
        "llm_primary_max_retries": 0,
    }
    return Settings(**{**base, **overrides})


_COMMON = ("name", "brand", "stock", "tags", "description")


def product(sku: str, category: str, price: int, **kw: Any) -> dict[str, Any]:
    """Produit au format générique de l'API : socle commun + `attributes` (tous les autres kwargs)."""
    common = {k: kw.pop(k) for k in _COMMON if k in kw}
    return {
        "id": str(uuid.uuid5(uuid.NAMESPACE_URL, sku)),
        "sku": sku,
        "name": common.get("name", sku),
        "brand": common.get("brand", "Test"),
        "category": category,
        "price_cents": price,
        "stock": common.get("stock", 10),
        "tags": common.get("tags", []),
        "description": common.get("description", "ne doit pas être répété au LLM"),
        "attributes": kw,
    }


WINES = [
    product(
        "PAUILLAC-2015",
        "wine",
        12000,
        name="Château Test Pauillac 2015",
        stock=6,
        type="rouge",
        region="Bordeaux",
        appellation="Pauillac",
        cepages=["Cabernet Sauvignon", "Merlot"],
        vintage=2015,
        food_pairing=["agneau", "boeuf"],
        alcohol_degree=13.5,
        drinking_window={"from": 2024, "until": 2050},
    ),
    product(
        "MARGAUX-2016",
        "wine",
        15000,
        name="Château Test Margaux 2016",
        stock=4,
        type="rouge",
        region="Bordeaux",
        appellation="Margaux",
        cepages=["Cabernet Sauvignon", "Merlot"],
        vintage=2016,
        food_pairing=["agneau", "canard"],
        alcohol_degree=13.5,
        drinking_window={"from": 2025, "until": 2055},
    ),
    product(
        "CHABLIS-2018",
        "wine",
        8500,
        name="Chablis Grand Cru Test 2018",
        stock=1,
        type="blanc",
        region="Bourgogne",
        appellation="Chablis Grand Cru",
        cepages=["Chardonnay"],
        vintage=2018,
        food_pairing=["poisson", "huîtres"],
        alcohol_degree=13.0,
        drinking_window={"from": 2022, "until": 2038},
    ),
    product(
        "HERMITAGE-2016",
        "wine",
        28500,
        name="Hermitage Test 2016",
        stock=3,
        type="rouge",
        region="Rhône",
        appellation="Hermitage",
        cepages=["Syrah"],
        vintage=2016,
        food_pairing=["gibier"],
        alcohol_degree=14.0,
        drinking_window={"from": 2026, "until": 2055},
    ),
]
WINE_ACCESSORIES = [
    product("CARAFE", "accessory", 14900, name="Carafe Test", kind="Carafe", suited_for=["rouge"]),
    product("VERRES", "accessory", 39000, name="Verres Test", kind="Verres", suited_for=["rouge", "blanc"]),
]
BY_CATEGORY = {"wine": WINES, "accessory": WINE_ACCESSORIES}
ALL = [p for items in BY_CATEGORY.values() for p in items]
BY_ID = {p["id"]: p for p in ALL}


def _attr(product: dict[str, Any], path: str) -> Any:
    node: Any = product["attributes"]
    for segment in path.split("."):
        if not isinstance(node, dict) or segment not in node:
            return None
        node = node[segment]
    return node


def _matches(product: dict[str, Any], flt: dict[str, Any]) -> bool:
    actual = _attr(product, flt["key"])
    if actual is None:
        return bool(flt.get("include_missing"))
    value, op = flt.get("value"), flt.get("op", "eq")
    if op == "eq":
        return bool(actual == value)
    if op == "in":
        return actual in (value or [])
    if op == "gte":
        return bool(actual >= value)
    if op == "lte":
        return bool(actual <= value)
    if op == "contains":
        return value in actual if isinstance(actual, list) else str(value).lower() in str(actual).lower()
    if op == "any":
        return bool(set(actual) & set(value or []))
    return False


def _search(request: httpx.Request) -> httpx.Response:
    payload = json.loads(request.content)
    items = BY_CATEGORY.get(payload.get("category"), ALL)
    if "budget_cents" in payload:
        items = [p for p in items if p["price_cents"] <= payload["budget_cents"]]
    for flt in payload.get("filters", []):
        items = [p for p in items if _matches(p, flt)]
    return httpx.Response(200, json={"strategy": "vector", "results": items[: payload.get("limit", 5)]})


def _verify(request: httpx.Request) -> httpx.Response:
    payload = json.loads(request.content)
    items = [BY_ID[i] for i in payload["item_ids"]]
    total = sum(p["price_cents"] for p in items)
    budget = payload.get("budget_cents")
    body: dict[str, Any] = {
        "compatible": True,
        "within_budget": budget is None or total <= budget,
        "total_cents": total,
        "budget_cents": budget,
        "serving_year": payload.get("serving_year") or 2026,
        "issues": [],
    }
    if payload.get("dish"):
        wines = [p for p in items if p["category"] == "wine"]
        ok = [p["sku"] for p in wines if any(w in payload["dish"] for w in _attr(p, "food_pairing") or [])]
        body["pairing"] = {
            "dish": payload["dish"],
            "matches": ok,
            "mismatches": [p["sku"] for p in wines if p["sku"] not in ok],
        }
    return httpx.Response(200, json=body)


def _cart_summary(cart_id: str) -> dict[str, Any]:
    cart = _CARTS.setdefault(cart_id, {})
    total = sum(BY_ID[pid]["price_cents"] * q for pid, q in cart.items())
    return {
        "cart_id": cart_id,
        "items": [
            {
                "product_id": pid,
                "sku": BY_ID[pid]["sku"],
                "name": BY_ID[pid]["name"],
                "quantity": q,
                "unit_price_cents": BY_ID[pid]["price_cents"],
                "line_total_cents": BY_ID[pid]["price_cents"] * q,
            }
            for pid, q in cart.items()
        ],
        "total_cents": total,
        "compatibility": {"compatible": True, "within_budget": True, "total_cents": total, "issues": []},
    }


def _add_item(request: httpx.Request) -> httpx.Response:
    payload = json.loads(request.content)
    cart_id = request.url.path.split("/")[3]
    cart = _CARTS.setdefault(cart_id, {})
    wanted = cart.get(payload["product_id"], 0) + payload["quantity"]
    stock = BY_ID[payload["product_id"]]["stock"]
    if wanted > stock:
        error = {
            "code": "insufficient_stock",
            "message": "x",
            "available": stock,
            "in_cart": wanted - payload["quantity"],
        }
        return httpx.Response(409, json={"error": error})
    cart[payload["product_id"]] = wanted
    return httpx.Response(201, json=_cart_summary(cart_id))


def _remove_item(request: httpx.Request) -> httpx.Response:
    parts = request.url.path.split("/")
    cart_id, product_id = parts[3], parts[5]
    cart = _CARTS.get(cart_id, {})
    if product_id not in cart:
        return httpx.Response(404, json={"error": {"code": "item_not_in_cart", "message": "x"}})
    del cart[product_id]
    return httpx.Response(200, json=_cart_summary(cart_id))


_CARTS: dict[str, dict[str, int]] = {}
# Consigne de checkout par panier : simule l'état tenu par Symfony (aucune donnée personnelle).
CHECKOUT_INSTRUCTIONS: dict[str, str] = {}
FIRST_QUESTION = "À quel nom et prénom devons-nous adresser la commande ?"


def _checkout_start(request: httpx.Request) -> httpx.Response:
    cart_id = request.url.path.split("/")[3]
    if not _CARTS.get(cart_id):
        return httpx.Response(409, json={"error": {"code": "empty_cart", "message": "x"}})
    instruction = f"Le tunnel de commande démarre. Demande au client quelque chose comme : « {FIRST_QUESTION} »"
    CHECKOUT_INSTRUCTIONS[cart_id] = instruction
    return httpx.Response(
        200,
        json={
            "accepted": True,
            "step": "AWAITING_FULL_NAME",
            "llm_message": "",
            "instruction": instruction,
            "order": None,
        },
    )


def _checkout_edit(request: httpx.Request) -> httpx.Response:
    cart_id = request.url.path.split("/")[3]
    if cart_id not in CHECKOUT_INSTRUCTIONS:
        return httpx.Response(409, json={"error": {"code": "nothing_to_edit", "message": "x"}})
    field = json.loads(request.content)["field"]
    questions = {
        "name": "À quel nom et prénom devons-nous adresser la commande ?",
        "address": "Quelle est votre adresse postale de livraison ?",
        "phone": "À quel numéro de téléphone le livreur peut-il vous joindre ?",
        "email": "Et enfin, quelle est votre adresse email pour le suivi ?",
    }
    instruction = f"Le client souhaite corriger {field}. Confirme puis demande de nouveau : « {questions[field]} »"
    CHECKOUT_INSTRUCTIONS[cart_id] = instruction
    return httpx.Response(
        200,
        json={
            "accepted": True,
            "step": f"EDIT_{field}",
            "llm_message": "[EDIT_REQUEST]",
            "instruction": instruction,
            "order": None,
        },
    )


def _checkout_hint(request: httpx.Request) -> httpx.Response:
    cart_id = request.url.path.split("/")[3]
    instruction = CHECKOUT_INSTRUCTIONS.get(cart_id)
    body = {"active": instruction is not None, "step": "AWAITING_FULL_NAME" if instruction else None}
    return httpx.Response(200, json={**body, "instruction": instruction})


@pytest.fixture
def backend() -> Iterator[respx.MockRouter]:
    _CARTS.clear()
    CHECKOUT_INSTRUCTIONS.clear()
    with respx.mock(base_url=BACKEND, assert_all_called=False) as mock:
        mock.post("/api/products/search", name="search").mock(side_effect=_search)
        # Par défaut le sommelier est indisponible : l'agent retombe sur la recherche sémantique classée localement.
        mock.post("/api/sommelier/recommend", name="recommend").mock(
            return_value=httpx.Response(503, json={"error": {"code": "unavailable", "message": "x"}})
        )
        mock.post("/api/compatibility/verify-selection", name="verify").mock(side_effect=_verify)
        mock.post(path__regex=r"/api/cart/[0-9a-f-]+/items", name="items").mock(side_effect=_add_item)
        mock.delete(path__regex=r"/api/cart/[0-9a-f-]+/items/[0-9a-f-]+", name="remove").mock(side_effect=_remove_item)
        mock.post(path__regex=r"/api/checkout/[0-9a-f-]+/start", name="checkout_start").mock(
            side_effect=_checkout_start
        )
        mock.post(path__regex=r"/api/checkout/[0-9a-f-]+/edit", name="checkout_edit").mock(side_effect=_checkout_edit)
        mock.post(path__regex=r"/api/checkout/[0-9a-f-]+/hint", name="checkout_hint").mock(side_effect=_checkout_hint)
        yield mock


@pytest.fixture
def client(backend: respx.MockRouter) -> Iterator[TestClient]:
    with TestClient(create_app(make_settings())) as c:
        yield c
