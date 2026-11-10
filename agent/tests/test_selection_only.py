"""Règle absolue : une demande de sélection n'affiche que des sélections ; les bouteilles seules ne sortent que sur demande explicite."""

import json
from typing import Any

import httpx
import pytest
import respx
from fastapi.testclient import TestClient
from langchain_core.messages import AIMessage, HumanMessage, ToolMessage
from langchain_core.runnables import RunnableLambda
from pydantic import SecretStr

from aura_agent.core.prompts import SYSTEM_PROMPT
from aura_agent.graph import builder
from aura_agent.graph.builder import GraphDeps, build_graph
from aura_agent.mcp.backend_client import BackendClient
from aura_agent.mcp.tools import LLM_TOOL_SCHEMAS, TOOL_SPECS, BackendTools
from tests.conftest import API_KEY, BACKEND, CART_ID

HEADERS = {"X-Internal-Api-Key": API_KEY}


def chat(client: TestClient, message: str) -> list[tuple[str, dict[str, Any]]]:
    events: list[tuple[str, dict[str, Any]]] = []
    with client.stream("POST", "/api/chat/stream", json={"cart_id": CART_ID, "message": message}, headers=HEADERS) as r:
        name = ""
        for line in r.iter_lines():
            if line.startswith("event: "):
                name = line.removeprefix("event: ")
            elif line.startswith("data: "):
                events.append((name, json.loads(line.removeprefix("data: "))))
    return events


def names(events: list[tuple[str, dict[str, Any]]]) -> list[str]:
    return [n for n, _ in events]


def text_of(events: list[tuple[str, dict[str, Any]]]) -> str:
    return "".join(d["text"] for n, d in events if n == "text_delta")


def _tools() -> BackendTools:
    return BackendTools(BackendClient(BACKEND, SecretStr(API_KEY)))


# --- Demande d'ensemble : uniquement des sélections ----------------------------------------------------------------------


@pytest.mark.parametrize(
    "message",
    [
        "Je veux une sélection pour un repas de fête, budget 500€",
        "Il me faut un cadeau, budget 300€",
        "Quel vin pour mon dîner de ce soir, budget 300€",
        "Un coffret de 2 bouteilles de Bordeaux, budget 800€",
        "Je cherche un vin pour accompagner un agneau, budget 400€",
    ],
)
def test_set_requests_only_show_assembled_selections(
    client: TestClient, backend: respx.MockRouter, message: str
) -> None:
    events = chat(client, message)

    seen = names(events)
    assert "ui_bundle_comparison" in seen
    assert "ui_product_card" not in seen, "aucune bouteille seule dans l'interface"
    comparison = next(d for n, d in events if n == "ui_bundle_comparison")
    assert 1 <= len(comparison["selections"]) <= 3
    for selection in comparison["selections"]:
        assert selection["items"], "une sélection n'est jamais vide"
        assert all(i["role"] == "wine" and i["category"] == "wine" for i in selection["items"])
    assert backend["verify"].calls.call_count == len(comparison["selections"]), (
        "chaque sélection est vérifiée par Symfony"
    )
    assert text_of(events).strip().endswith("?")


def test_selection_search_prepares_wines_without_displaying_them(client: TestClient, backend: respx.MockRouter) -> None:
    events = chat(client, "Une sélection pour un repas de fête, budget 500€")

    categories = [json.loads(c.request.content)["category"] for c in backend["search"].calls]
    assert categories == ["wine"], "les bouteilles sont cherchées pour construire les sélections…"
    assert "ui_product_card" not in names(events), "…mais jamais affichées"


# --- Bouteille seule : uniquement sur demande explicite -------------------------------------------------------------------


@pytest.mark.parametrize(
    "message",
    ["Je cherche juste une bouteille de blanc, budget 100€", "Une seule bouteille de rouge, budget 200€"],
)
def test_single_bottles_only_on_explicit_request(client: TestClient, backend: respx.MockRouter, message: str) -> None:
    events = chat(client, message)

    sent = json.loads(backend["search"].calls[0].request.content)
    assert sent["category"] == "wine"
    assert backend["search"].calls.call_count == 1 and backend["verify"].calls.call_count == 0
    cards = [d for n, d in events if n == "ui_product_card"]
    assert cards and {c["category"] for c in cards} == {"wine"}
    assert "ui_bundle_comparison" not in names(events)


# --- Outil propose_selections_tool ----------------------------------------------------------------------------------------


async def test_propose_caps_at_three_selections(backend: respx.MockRouter) -> None:
    result = await _tools().propose_selections_tool("vin", limit=10)

    assert len(result["selections"]) == 3


async def test_propose_reports_why_nothing_matches(backend: respx.MockRouter) -> None:
    none = await _tools().propose_selections_tool("vin", filters=[{"key": "region", "op": "eq", "value": "Alsace"}])
    assert none == {"selections": [], "recommended_index": None, "reason": "no_wine"}

    over = await _tools().propose_selections_tool("vin", bottles=2, budget_cents=10000)
    assert over == {"selections": [], "recommended_index": None, "reason": "no_selection_within_budget"}


async def test_wine_search_never_excludes_by_similarity(backend: respx.MockRouter) -> None:
    await _tools().propose_selections_tool("vin")

    sent = [json.loads(c.request.content) for c in backend["search"].calls]
    assert {p["category"]: p["min_similarity"] for p in sent} == {"wine": -1}


async def test_recommendation_prefers_selections_without_warnings() -> None:
    from aura_agent.mcp.bundles import WineBundles

    recommend = WineBundles().recommend
    warning = [{"severity": "warning", "code": "PAIRING_WEAK", "message": "x"}]
    rows = [
        {"compatible": True, "within_budget": True, "issues": warning},
        {"compatible": True, "within_budget": True, "issues": []},
        {"compatible": True, "within_budget": False, "issues": []},
    ]

    assert recommend(rows) == 1
    assert recommend([rows[0]]) == 0, "à défaut, on recommande quand même la première sélection valide"
    assert recommend([rows[2]]) is None


# --- Garde-fous du graphe et du schéma LLM ------------------------------------------------------------------------------


def test_llm_schema_cannot_search_several_categories_at_once() -> None:
    search = TOOL_SPECS["search_catalog_tool"].openai_schema()["function"]["parameters"]["properties"]
    propose = TOOL_SPECS["propose_selections_tool"].openai_schema()["function"]["parameters"]["properties"]

    assert "categories" not in search, "le LLM ne peut plus chercher plusieurs catégories en une fois"
    assert "category" in search and "category" not in propose
    assert TOOL_SPECS["propose_selections_tool"].ui_event == "ui_bundle_comparison"
    assert "propose_selections_tool" in {t["function"]["name"] for t in LLM_TOOL_SCHEMAS}


def test_mixed_category_search_results_never_produce_product_cards(monkeypatch: pytest.MonkeyPatch) -> None:
    emitted: list[tuple[str, dict[str, Any]]] = []
    monkeypatch.setattr(builder, "_emit", lambda event, data: emitted.append((event, data)))

    builder._emit_tool_ui(
        "ui_product_card", {"results": [{"category": "wine", "sku": "W"}, {"category": "accessory", "sku": "A"}]}
    )
    assert emitted == [], "vins + accessoires mêlés = préparation d'une sélection, pas d'affichage de pièces"

    builder._emit_tool_ui("ui_product_card", {"results": [{"category": "wine", "sku": f"W{i}"} for i in range(5)]})
    assert len(emitted) == 3 and {d["category"] for _, d in emitted} == {"wine"}


def test_empty_selection_result_displays_nothing(monkeypatch: pytest.MonkeyPatch) -> None:
    emitted: list[tuple[str, dict[str, Any]]] = []
    monkeypatch.setattr(builder, "_emit", lambda event, data: emitted.append((event, data)))

    builder._emit_tool_ui("ui_bundle_comparison", {"selections": [], "recommended_index": None, "reason": "no_wine"})

    assert emitted == []


async def test_llm_calling_search_with_categories_is_rejected(backend: respx.MockRouter) -> None:
    args = {"query": "repas", "categories": ["wine", "accessory"]}
    script = iter(
        [
            AIMessage("", tool_calls=[{"name": "search_catalog_tool", "args": args, "id": "c1", "type": "tool_call"}]),
            AIMessage("Je vous propose des sélections."),
        ]
    )
    llm: RunnableLambda[Any, AIMessage] = RunnableLambda(lambda _m: next(script))
    graph = build_graph(GraphDeps(llm=llm, tools=_tools()))

    result = await graph.ainvoke(
        {"messages": [HumanMessage("une sélection pour un repas")], "cart_id": CART_ID},
        {"configurable": {"thread_id": "guard"}, "recursion_limit": 14},
    )

    tool_message = next(m for m in result["messages"] if isinstance(m, ToolMessage))
    assert json.loads(str(tool_message.content)) == {"error": "invalid_arguments"}
    assert backend["search"].calls.call_count == 0


async def test_backend_error_on_search_is_reported_without_crashing(backend: respx.MockRouter) -> None:
    backend["search"].mock(return_value=httpx.Response(500, json={"error": {"code": "internal_error"}}))
    from aura_agent.graph.builder import _run_tool

    message = await _run_tool(_tools(), "propose_selections_tool", {"query": "vin"}, "c1", CART_ID)

    assert json.loads(str(message.content)) == {"error": "internal_error"}


# --- Le prompt ------------------------------------------------------------------------------------------------------------


def test_prompt_anchors_the_selection_versus_single_bottle_distinction() -> None:
    lowered = SYSTEM_PROMPT.lower()

    assert "règle absolue" in lowered
    assert "uniquement propose_selections_tool" in lowered
    assert "search_catalog_tool (category wine)" in lowered
    assert "categories=[" not in lowered, "l'ancienne consigne de recherche mêlée a disparu"
