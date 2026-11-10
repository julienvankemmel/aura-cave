import json
import uuid

import httpx
import pytest
import respx
from pydantic import SecretStr, ValidationError

from aura_agent.mcp.backend_client import BackendClient, BackendError
from aura_agent.mcp.server import build_mcp_server
from aura_agent.mcp.tools import TOOL_SPECS, BackendTools, ToolInputError
from tests.conftest import API_KEY, BACKEND, CART_ID, WINES

PAUILLAC, MARGAUX, CHABLIS, HERMITAGE = WINES


@pytest.fixture
async def tools(backend: respx.MockRouter) -> BackendTools:
    return BackendTools(BackendClient(BACKEND, SecretStr(API_KEY)))


def sent_json(route: respx.Route, index: int = 0) -> dict[str, object]:
    payload: dict[str, object] = json.loads(route.calls[index].request.content)
    return payload


async def test_every_backend_call_carries_internal_api_key(tools: BackendTools, backend: respx.MockRouter) -> None:
    await tools.search_catalog_tool("grand vin rouge", category="wine")
    await tools.search_catalog_tool("carafe", category="accessory")
    await tools.verify_and_compare_selections_tool([{"item_ids": [PAUILLAC["id"]]}])
    await tools.update_cart_tool(CART_ID, str(PAUILLAC["id"]), 2)
    await tools.start_checkout_tool(CART_ID)

    assert len(backend.calls) == 5
    for call in backend.calls:
        assert call.request.headers["X-Internal-Api-Key"] == API_KEY


async def test_search_payload_follows_symfony_dto(tools: BackendTools, backend: respx.MockRouter) -> None:
    result = await tools.search_catalog_tool(
        "grand vin rouge",
        budget_cents=30000,
        filters=[{"key": "region", "op": "eq", "value": "Bordeaux", "include_missing": True}],
        category="wine",
        limit=3,
    )

    route = backend["search"]
    assert sent_json(route) == {
        "query": "grand vin rouge",
        "budget_cents": 30000,
        "category": "wine",
        "limit": 3,
        "filters": [{"key": "region", "op": "eq", "value": "Bordeaux", "include_missing": True}],
    }
    assert result["strategy"] == "vector"
    assert [p["sku"] for p in result["results"]] == ["PAUILLAC-2015", "MARGAUX-2016"]
    assert all(len(p["description"]) <= 240 for p in result["results"])  # description tronquée


async def test_search_with_categories_runs_one_backend_search_per_category(
    tools: BackendTools, backend: respx.MockRouter
) -> None:
    result = await tools.search_catalog_tool("vin rouge", budget_cents=30000, categories=["wine", "accessory", "wine"])

    sent = [json.loads(c.request.content) for c in backend["search"].calls]
    assert sorted(p["category"] for p in sent) == ["accessory", "wine"]  # doublons ignorés
    assert all(p["budget_cents"] == 30000 for p in sent)
    assert {p["category"] for p in result["results"]} == {"wine", "accessory"}


async def test_compare_does_not_fetch_accessories_before_the_client_chooses(
    tools: BackendTools, backend: respx.MockRouter
) -> None:
    await tools.search_catalog_tool("vin", category="wine")
    searches_before = backend["search"].calls.call_count

    result = await tools.verify_and_compare_selections_tool([{"item_ids": [PAUILLAC["id"]]}])

    assert "recommended_accessories" not in result
    assert backend["search"].calls.call_count == searches_before


async def test_search_rejects_values_symfony_would_refuse(tools: BackendTools) -> None:
    for kwargs in (
        {"query": ""},
        {"query": "x", "filters": [{"key": "Bad Key!"}]},
        {"query": "x", "limit": 99},
        {"query": "x", "budget_cents": -1},
    ):
        with pytest.raises(ValidationError):
            await tools.search_catalog_tool(**kwargs)


async def test_compare_builds_structured_comparison_with_price_and_pairing(
    tools: BackendTools,
    backend: respx.MockRouter,
) -> None:
    await tools.search_catalog_tool("vin", category="wine")

    result = await tools.verify_and_compare_selections_tool(
        [
            {"item_ids": [PAUILLAC["id"], MARGAUX["id"]]},
            {"item_ids": [HERMITAGE["id"]]},
        ],
        budget_cents=30000,
        dish="agneau",
    )

    verify = backend["verify"]
    assert sent_json(verify)["dish"] == "agneau"
    assert sent_json(verify)["item_ids"] == [PAUILLAC["id"], MARGAUX["id"]]
    assert sent_json(verify)["budget_cents"] == 30000
    first, second = result["selections"]
    assert first["total_cents"] == 12000 + 15000
    assert first["metrics"] == {"bottles": 2}
    assert [i["role"] for i in first["items"]] == ["wine", "wine"]
    assert first["details"]["pairing"]["matches"] == ["PAUILLAC-2015", "MARGAUX-2016"]
    assert first["compatible"] and first["within_budget"]
    assert second["within_budget"] is True  # 285 € <= 300 €
    assert result["recommended_index"] == 0

    tight = await tools.verify_and_compare_selections_tool(
        [{"item_ids": [HERMITAGE["id"]]}, {"item_ids": [CHABLIS["id"]]}], budget_cents=10000
    )
    assert [c["within_budget"] for c in tight["selections"]] == [False, True]
    assert tight["recommended_index"] == 1


async def test_compare_rejects_unknown_products_and_empty_selections(tools: BackendTools) -> None:
    await tools.search_catalog_tool("vin")

    with pytest.raises(ToolInputError):
        await tools.verify_and_compare_selections_tool([{"item_ids": [str(uuid.uuid4()), PAUILLAC["id"]]}])
    with pytest.raises(ToolInputError):
        await tools.verify_and_compare_selections_tool([{"item_ids": []}])
    with pytest.raises(ToolInputError):
        await tools.verify_and_compare_selections_tool([])


async def test_accessories_are_suited_to_the_wine_type(tools: BackendTools, backend: respx.MockRouter) -> None:
    await tools.search_catalog_tool("vin")

    red = await tools.recommend_compatible_accessories_tool([str(PAUILLAC["id"])])
    white = await tools.recommend_compatible_accessories_tool([str(CHABLIS["id"])])

    assert [p["sku"] for p in red["groups"][0]["items"]] == ["CARAFE", "VERRES"]
    assert [p["sku"] for p in white["groups"][0]["items"]] == ["VERRES"]  # la carafe est pour les rouges


async def test_update_cart_posts_item_and_tracks_total(tools: BackendTools, backend: respx.MockRouter) -> None:
    await tools.search_catalog_tool("vin")

    result = await tools.update_cart_tool(CART_ID, str(PAUILLAC["id"]), 2)

    route = backend["items"]
    assert route.calls[0].request.url.path == f"/api/cart/{CART_ID}/items"
    assert sent_json(route) == {"product_id": PAUILLAC["id"], "quantity": 2}
    assert result["total_cents"] == 2 * 12000
    assert tools.cart_total(CART_ID) == 2 * 12000


async def test_remove_from_cart_deletes_line_and_returns_updated_cart(
    tools: BackendTools, backend: respx.MockRouter
) -> None:
    await tools.search_catalog_tool("vin")
    await tools.update_cart_tool(CART_ID, str(PAUILLAC["id"]))
    await tools.update_cart_tool(CART_ID, str(MARGAUX["id"]))

    result = await tools.remove_from_cart_tool(CART_ID, str(MARGAUX["id"]))

    call = backend["remove"].calls[0].request
    assert call.method == "DELETE"
    assert call.url.path == f"/api/cart/{CART_ID}/items/{MARGAUX['id']}"
    assert call.headers["X-Internal-Api-Key"] == API_KEY
    assert result["total_cents"] == 12000
    assert [i["sku"] for i in result["items"]] == ["PAUILLAC-2015"]
    assert tools.cart_total(CART_ID) == 12000


async def test_remove_from_cart_surfaces_backend_error_code(tools: BackendTools) -> None:
    with pytest.raises(BackendError) as info:
        await tools.remove_from_cart_tool(CART_ID, str(PAUILLAC["id"]))

    assert (info.value.status, info.value.code) == (404, "item_not_in_cart")


async def test_remove_from_cart_requires_uuid_v4_cart_id(tools: BackendTools) -> None:
    with pytest.raises(ToolInputError):
        await tools.remove_from_cart_tool(str(uuid.uuid1()), str(uuid.uuid4()))


async def test_update_cart_requires_uuid_v4_cart_id(tools: BackendTools) -> None:
    with pytest.raises(ToolInputError):
        await tools.update_cart_tool(str(uuid.uuid1()), str(uuid.uuid4()))


async def test_start_checkout_returns_the_server_instruction_and_never_personal_data(
    tools: BackendTools, backend: respx.MockRouter
) -> None:
    await tools.update_cart_tool(CART_ID, str(PAUILLAC["id"]))

    result = await tools.start_checkout_tool(CART_ID)

    assert backend["checkout_start"].calls[0].request.url.path == f"/api/checkout/{CART_ID}/start"
    assert result["status"] == "checkout_started" and result["step"] == "AWAITING_FULL_NAME"
    assert "nom et prénom" in result["instruction"]
    assert await tools.checkout_hint(CART_ID) == result["instruction"]


async def test_checkout_hint_is_none_without_checkout_and_never_blocks_the_chat(
    tools: BackendTools, backend: respx.MockRouter
) -> None:
    assert await tools.checkout_hint(CART_ID) is None
    backend["checkout_hint"].mock(return_value=httpx.Response(503))

    assert await tools.checkout_hint(CART_ID) is None


async def test_backend_error_exposes_status_and_code_only(backend: respx.MockRouter) -> None:
    tools = BackendTools(BackendClient(BACKEND, SecretStr(API_KEY)))

    with pytest.raises(BackendError) as info:
        await tools.start_checkout_tool(CART_ID)  # panier vide

    assert (info.value.status, info.value.code) == (409, "empty_cart")
    assert str(info.value) != "x"


async def test_fastmcp_server_registers_all_tools(tools: BackendTools) -> None:
    server = build_mcp_server(tools)

    names = {t.name for t in await server.list_tools()}

    assert names == {
        "propose_selections_tool",
        "search_catalog_tool",
        "verify_and_compare_selections_tool",
        "recommend_compatible_accessories_tool",
        "update_cart_tool",
        "remove_from_cart_tool",
        "start_checkout_tool",
        "edit_checkout_tool",
    }


def test_llm_tool_schemas_never_expose_cart_id() -> None:
    for spec in TOOL_SPECS.values():
        properties = spec.openai_schema()["function"]["parameters"].get("properties", {})
        assert "cart_id" not in properties


def test_llm_tool_schemas_stay_small() -> None:
    """Garde-fou de consommation : les schémas sont renvoyés à chaque appel LLM (quota Groq 8 000 tokens/min)."""
    from aura_agent.mcp.tools import tool_schemas

    schemas = tool_schemas()
    size = len(json.dumps(schemas, ensure_ascii=False, separators=(",", ":")))
    assert size < 6000, size  # ~1500 tokens (filtres génériques inclus)
    assert '"title"' not in json.dumps(schemas)


async def test_cart_response_with_php_empty_array_for_attributes_is_accepted(backend: respx.MockRouter) -> None:
    """Symfony sérialise un tableau PHP vide en `[]` : un produit sans attributs doit rester lisible."""
    empty = {**PAUILLAC, "attributes": []}
    backend["search"].mock(return_value=httpx.Response(200, json={"strategy": "vector", "results": [empty]}))
    tools = BackendTools(BackendClient(BACKEND, SecretStr(API_KEY)))

    result = await tools.search_catalog_tool("vin")

    assert result["results"][0]["attributes"] == {}
