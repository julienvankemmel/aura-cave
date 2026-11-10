"""Pipeline « filtre SQL + reranking du sommelier » : l'agent assemble les sélections à partir du classement renvoyé
par Symfony, et retombe sur la recherche sémantique si ce service est indisponible."""

import json
from typing import Any

import httpx
import respx
from pydantic import SecretStr

from aura_agent.mcp.backend_client import BackendClient
from aura_agent.mcp.tools import BackendTools
from tests.conftest import API_KEY, BACKEND, BY_ID, WINES


def _tools() -> BackendTools:
    client = BackendClient(BACKEND, SecretStr(API_KEY))
    return BackendTools(client)


def _ranked(skus: list[str], scores: list[int]) -> dict[str, Any]:
    wines = {w["sku"]: w for w in WINES}
    results = []
    for i, (sku, score) in enumerate(zip(skus, scores, strict=True)):
        results.append(
            {
                **wines[sku],
                "match_score": score,
                "sommelier_justification": f"Justification gustative {sku}",
                "is_coup_de_coeur": i == 0,
            }
        )
    return {"status": "ok", "source": "llm", "model": "gemini-3.6-flash", "reason": None, "results": results}


def _sent(route: respx.Route) -> list[dict[str, Any]]:
    return [json.loads(call.request.content) for call in route.calls]


async def test_selections_follow_the_sommelier_ranking_and_carry_his_notes(backend: respx.MockRouter) -> None:
    backend["recommend"].mock(
        return_value=httpx.Response(
            200, json=_ranked(["MARGAUX-2016", "PAUILLAC-2015", "HERMITAGE-2016"], [95, 80, 60])
        )
    )

    result = await _tools().propose_selections_tool(
        "rouge pour de l'agneau", dish="mouton au barbecue", budget_cents=50000
    )

    skus = [s["items"][0]["sku"] for s in result["selections"]]
    assert skus == ["MARGAUX-2016", "PAUILLAC-2015", "HERMITAGE-2016"]
    first = result["selections"][0]["items"][0]
    assert first["sommelier_justification"] == "Justification gustative MARGAUX-2016"
    assert first["match_score"] == 95 and first["is_coup_de_coeur"] is True
    assert result["recommended_index"] == 0, "le coup de coeur est la sélection la mieux notée"
    assert not backend["search"].called, "le sommelier remplace la recherche sémantique"
    payload = _sent(backend["recommend"])[0]
    assert payload["dish"] == "mouton au barbecue" and payload["budget_cents"] == 50000 and payload["limit"] == 3


async def test_local_fallback_ranking_does_not_pretend_to_be_the_sommelier(backend: respx.MockRouter) -> None:
    ranked = _ranked(["MARGAUX-2016", "PAUILLAC-2015"], [83, 83])
    ranked["source"], ranked["model"] = "fallback", None
    backend["recommend"].mock(return_value=httpx.Response(200, json=ranked))

    result = await _tools().propose_selections_tool("rouge", dish="agneau")

    assert [s["items"][0]["sku"] for s in result["selections"]] == ["MARGAUX-2016", "PAUILLAC-2015"]
    assert all("sommelier_justification" not in s["items"][0] for s in result["selections"])


async def test_nothing_in_stock_within_budget_is_reported_without_search(backend: respx.MockRouter) -> None:
    backend["recommend"].mock(
        return_value=httpx.Response(
            200,
            json={
                "status": "no_match",
                "source": None,
                "model": None,
                "reason": "no_wine_in_stock_within_budget",
                "results": [],
            },
        )
    )

    result = await _tools().propose_selections_tool("rouge", budget_cents=500)

    assert result == {"selections": [], "recommended_index": None, "reason": "no_wine"}
    assert not backend["search"].called and not backend["verify"].called


async def test_falls_back_to_semantic_search_when_the_sommelier_service_is_down(backend: respx.MockRouter) -> None:
    result = await _tools().propose_selections_tool("grand vin rouge", dish="agneau")

    assert len(result["selections"]) == 3
    assert backend["search"].called
    assert "sommelier_justification" not in result["selections"][0]["items"][0]


async def test_several_bottles_ask_for_a_wider_ranked_pool_and_stay_within_budget(backend: respx.MockRouter) -> None:
    backend["recommend"].mock(
        return_value=httpx.Response(
            200, json=_ranked(["MARGAUX-2016", "PAUILLAC-2015", "HERMITAGE-2016"], [95, 90, 80])
        )
    )

    result = await _tools().propose_selections_tool("dîner entre amis", bottles=2, budget_cents=30000)

    assert _sent(backend["recommend"])[0]["limit"] == 8
    assert result["selections"]
    for selection in result["selections"]:
        assert len(selection["items"]) == 2 and selection["total_cents"] <= 30000


async def test_pairing_table_notes_are_hidden_once_the_sommelier_has_judged(backend: respx.MockRouter) -> None:
    ranked = _ranked(["HERMITAGE-2016"], [88])
    backend["recommend"].mock(return_value=httpx.Response(200, json=ranked))
    # Le moteur de familles juge « gibier » hors sujet pour du mouton : avis PAIRING_MISMATCH renvoyé par Symfony.
    backend["verify"].mock(
        return_value=httpx.Response(
            200,
            json={
                "compatible": True,
                "within_budget": True,
                "total_cents": BY_ID[ranked["results"][0]["id"]]["price_cents"],
                "serving_year": 2026,
                "issues": [
                    {"severity": "warning", "code": "PAIRING_MISMATCH", "message": "pas un accord classique"},
                    {"severity": "warning", "code": "TOO_YOUNG", "message": "encore jeune"},
                ],
            },
        )
    )

    result = await _tools().propose_selections_tool("rouge", dish="mouton")

    assert [i["code"] for i in result["selections"][0]["issues"]] == ["TOO_YOUNG"]
