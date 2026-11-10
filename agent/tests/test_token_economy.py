import json
from typing import Any

import respx
from langchain_core.messages import AIMessage, AnyMessage, HumanMessage, ToolMessage
from langchain_core.runnables import RunnableLambda
from langchain_openai import ChatOpenAI
from pydantic import SecretStr

from aura_agent.core.llm_factory import build_chat_model
from aura_agent.graph.builder import OMITTED, GraphDeps, build_graph, compact_history
from aura_agent.graph.llm_view import llm_view
from aura_agent.mcp.backend_client import BackendClient
from aura_agent.mcp.tools import BackendTools
from tests.conftest import API_KEY, BACKEND, CART_ID, make_settings

FULL_PRODUCT = {
    "id": "p1",
    "sku": "S1",
    "name": "Château Test Pauillac 2015",
    "brand": "Château Test",
    "category": "wine",
    "price_cents": 12000,
    "stock": 6,
    "score": 0.53,
    "description": "texte long inutile pour le LLM " * 5,
    "tags": ["a", "b"],
    "attributes": {
        "type": "rouge",
        "region": "Bordeaux",
        "appellation": "Pauillac",
        "cepages": ["Cabernet Sauvignon", "Merlot"],
        "vintage": 2015,
        "body": 4,
        "tannin": 4,
        "alcohol_degree": 13.5,
        "producer_story": None,
        "organic": True,
        "bottle_size_cl": 75,
        "food_pairing": ["agneau"],
    },
}


def test_search_view_keeps_decision_fields_only_and_is_much_smaller() -> None:
    result = {"strategy": "vector", "results": [FULL_PRODUCT] * 6}

    view = json.loads(llm_view("search_catalog_tool", result))

    assert set(view["results"][0]) == {
        "id",
        "sku",
        "name",
        "category",
        "price_cents",
        "stock",
        "type",
        "region",
        "appellation",
        "cepages",
        "vintage",
        "body",
        "tannin",
        "alcohol_degree",
        "food_pairing",
    }
    assert len(llm_view("search_catalog_tool", result)) < len(json.dumps(result)) * 0.6


def test_compare_view_drops_info_issues_and_truncates_warnings() -> None:
    def item(sku: str, price: int) -> dict[str, Any]:
        return {
            "role": "wine",
            "id": sku.lower(),
            "name": sku,
            "brand": "X",
            "sku": sku,
            "category": "wine",
            "price_cents": price,
            "attributes": {"type": "rouge", "appellation": "Pauillac"},
        }

    selection = {
        "label": "Sélection 1",
        "items": [item("B", 1), item("L", 2)],
        "total_cents": 3,
        "metrics": {"bottles": 2},
        "details": {"serving_year": 2026},
        "compatible": True,
        "within_budget": True,
        "issues": [
            {"severity": "info", "code": "I", "message": "info"},
            {"severity": "warning", "code": "W", "message": "x" * 500},
        ],
    }

    view = json.loads(
        llm_view("verify_and_compare_selections_tool", {"selections": [selection], "recommended_index": 0})
    )

    issues = view["selections"][0]["issues"]
    assert [i["severity"] for i in issues] == ["warning"]
    assert len(issues[0]["message"]) == 140
    assert all("brand" not in i and "sku" not in i for i in view["selections"][0]["items"])
    assert view["selections"][0]["metrics"] == {"bottles": 2}


def test_errors_pass_through_unchanged() -> None:
    assert json.loads(llm_view("search_catalog_tool", {"error": "http_error"})) == {"error": "http_error"}


def _tool(name: str, call_id: str, payload: dict[str, Any] | None = None) -> ToolMessage:
    return ToolMessage(json.dumps(payload or {"x": 1}), name=name, tool_call_id=call_id)


def test_compact_history_omits_old_searches_but_keeps_latest_comparison_and_cart() -> None:
    messages: list[AnyMessage] = [
        HumanMessage("tour 1"),
        _tool("search_catalog_tool", "1"),
        _tool("verify_and_compare_selections_tool", "2", {"selections": ["ancien"]}),
        _tool("update_cart_tool", "3"),
        HumanMessage("tour 2"),
        _tool("verify_and_compare_selections_tool", "4", {"selections": ["récent"]}),
        _tool("search_catalog_tool", "5"),
        HumanMessage("tour 3"),
        _tool("search_catalog_tool", "6", {"results": ["courant"]}),
    ]

    contents = [str(m.content) for m in compact_history(messages)]

    assert contents[1] == OMITTED  # ancienne recherche
    assert contents[2] == OMITTED  # ancien comparatif remplacé par un plus récent
    assert "r\\u00e9cent" in contents[5] or "récent" in contents[5]  # dernier comparatif conservé
    assert contents[3] != OMITTED  # dernier état du panier conservé
    assert contents[6] == OMITTED
    assert "courant" in contents[8]  # le tour courant est intact


async def test_identical_tool_call_in_same_turn_is_not_replayed(backend: respx.MockRouter) -> None:
    args = {"query": "vin rouge", "category": "wine"}
    script = iter(
        [
            AIMessage("", tool_calls=[{"name": "search_catalog_tool", "args": args, "id": "c1", "type": "tool_call"}]),
            AIMessage("", tool_calls=[{"name": "search_catalog_tool", "args": args, "id": "c2", "type": "tool_call"}]),
            AIMessage("Voilà."),
        ]
    )
    llm: RunnableLambda[Any, AIMessage] = RunnableLambda(lambda _messages: next(script))
    graph = build_graph(GraphDeps(llm=llm, tools=BackendTools(BackendClient(BACKEND, SecretStr(API_KEY)))))

    result = await graph.ainvoke(
        {"messages": [HumanMessage("un vin rouge pour un agneau")], "cart_id": CART_ID},
        {"configurable": {"thread_id": "dedupe"}, "recursion_limit": 14},
    )

    assert backend["search"].calls.call_count == 1
    tool_messages = [m for m in result["messages"] if isinstance(m, ToolMessage)]
    assert len(tool_messages) == 2
    assert "déjà effectué" in str(tool_messages[1].content)
    assert result["messages"][-1].content == "Voilà."


def test_only_the_primary_provider_retries() -> None:
    settings = make_settings(
        llm_provider="groq", llm_primary_max_retries=2, groq_api_key=SecretStr("g"), openrouter_api_key=SecretStr("o")
    )

    chain = build_chat_model(settings)

    primary = chain.runnable  # type: ignore[attr-defined]
    fallback = chain.fallbacks[0]  # type: ignore[attr-defined]
    assert isinstance(primary, ChatOpenAI) and isinstance(fallback, ChatOpenAI)
    assert primary.max_retries == 2
    assert fallback.max_retries == 0


async def test_identical_failed_call_is_not_retried_even_for_cart_tools(backend: respx.MockRouter) -> None:
    backend["remove"].mock(return_value=__import__("httpx").Response(404, json={"error": {"code": "item_not_in_cart"}}))
    args = {"product_id": "7e0b9ad9-7484-5f39-83e5-1abac1fd0017"}
    script = iter(
        [
            AIMessage(
                "", tool_calls=[{"name": "remove_from_cart_tool", "args": args, "id": f"c{i}", "type": "tool_call"}]
            )
            for i in range(1, 4)
        ]
        + [AIMessage("Rien à retirer.")]
    )
    llm: RunnableLambda[Any, AIMessage] = RunnableLambda(lambda _messages: next(script))
    graph = build_graph(GraphDeps(llm=llm, tools=BackendTools(BackendClient(BACKEND, SecretStr(API_KEY)))))

    result = await graph.ainvoke(
        {"messages": [HumanMessage("retire le vin")], "cart_id": CART_ID},
        {"configurable": {"thread_id": "retry"}, "recursion_limit": 14},
    )

    assert backend["remove"].calls.call_count == 1  # les 2 réessais identiques n'atteignent pas le backend
    assert result["messages"][-1].content == "Rien à retirer."


def test_compare_view_names_the_recommended_selection_explicitly() -> None:
    def selection(label: str) -> dict[str, Any]:
        return {"label": label, "items": [{"role": "wine", "id": "w", "name": "W"}], "total_cents": 1}

    view = json.loads(
        llm_view(
            "verify_and_compare_selections_tool",
            {"selections": [selection("Sélection 1"), selection("Selection 2")], "recommended_index": 1},
        )
    )

    assert view["recommended_label"] == "Selection 2"
    assert (
        json.loads(llm_view("verify_and_compare_selections_tool", {"selections": [], "recommended_index": None}))[
            "recommended_label"
        ]
        is None
    )
