"""Posture de sommelier : découverte, proposition ciblée, tri sans nouvelle recherche, choix puis accessoires."""

import json
from typing import Any

import respx
from fastapi.testclient import TestClient

from aura_agent.core.mock_llm import _superlative_answer
from aura_agent.core.prompts import SYSTEM_PROMPT
from tests.conftest import ALL, API_KEY, CART_ID

HEADERS = {"X-Internal-Api-Key": API_KEY}
SCENARIO = "Un vin rouge de Bordeaux pour un gigot d'agneau, budget max 400€."
ID = {p["sku"]: p["id"] for p in ALL}


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


# --- Étape 1 : découverte ---------------------------------------------------------------------------------


def test_vague_request_gets_a_discovery_question_without_any_search(
    client: TestClient, backend: respx.MockRouter
) -> None:
    for message in ("Bonjour", "Je cherche un vin"):
        events = chat(client, message)

        assert names(events) == ["text_delta"] * (len(names(events)) - 1) + ["done"]
        assert text_of(events).strip().endswith("?")
        assert "budget" in text_of(events)
    assert backend.calls.call_count == backend["checkout_hint"].calls.call_count


def test_complete_request_goes_straight_to_a_proposal(client: TestClient) -> None:
    events = chat(client, SCENARIO)

    assert "ui_bundle_comparison" in names(events)


# --- Tri sur des propositions déjà affichées ---------------------------------------------------------------


def test_cheapest_question_is_answered_from_existing_selections_without_new_search(
    client: TestClient, backend: respx.MockRouter
) -> None:
    chat(client, SCENARIO)
    searches, verifies = backend["search"].calls.call_count, backend["verify"].calls.call_count

    events = chat(client, "Et le moins cher ?")

    assert names(events).count("ui_bundle_comparison") == 0
    assert "ui_product_card" not in names(events)
    assert (backend["search"].calls.call_count, backend["verify"].calls.call_count) == (searches, verifies)
    text = text_of(events)
    assert "Sélection 1" in text and "Château Test Pauillac 2015" in text and "120 €" in text
    assert names(events) == ["text_delta"] * (len(names(events)) - 1) + ["done"]
    assert text.strip().endswith("?")


def test_superlative_answer_picks_the_cheapest_selection() -> None:
    def selection(label: str, cents: int) -> dict[str, Any]:
        return {"label": label, "items": [{"role": "wine", "name": f"V{label}"}], "total_cents": cents}

    comparison = {
        "selections": [
            selection("Sélection 1", 30000),
            selection("Selection 2", 25000),
            selection("Selection 3", 20000),
        ]
    }

    assert "Selection 3" in (_superlative_answer("lequel est le moins cher", comparison) or "")
    assert _superlative_answer("merci beaucoup", comparison) is None
    assert _superlative_answer("le moins cher", {"selections": []}) is None


# --- Étape 3 : choix de la sélection -> panier direct, accessoires seulement si le client le souhaite ------------------


def test_choosing_a_selection_adds_it_to_the_cart_and_only_offers_accessories(
    client: TestClient, backend: respx.MockRouter
) -> None:
    chat(client, SCENARIO)

    events = chat(client, f"Je choisis la sélection 1 : Château Test Pauillac 2015 [id:{ID['PAUILLAC-2015']}]")

    assert backend["items"].calls.call_count == 1, "la sélection choisie va directement au panier"
    assert next(d for n, d in events if n == "ui_cart_update")["total_cents"] == 12000
    assert "ui_accessory_upsell" not in names(events), "les accessoires ne sont jamais imposés"
    text = text_of(events)
    assert "panier" in text and "accessoires" in text and "sans aucune obligation" in text
    assert text.strip().endswith(".")


def test_choosing_without_ids_uses_the_selection_number(client: TestClient) -> None:
    chat(client, SCENARIO)

    events = chat(client, "Je prends la sélection 1")

    cart = next(d for n, d in events if n == "ui_cart_update")
    assert [i["product_id"] for i in cart["items"]] == [ID["PAUILLAC-2015"]]


def test_accessories_are_shown_only_when_the_customer_asks(client: TestClient, backend: respx.MockRouter) -> None:
    chat(client, SCENARIO)
    chat(client, f"Je choisis la sélection 1 [id:{ID['PAUILLAC-2015']}]")

    events = chat(client, "Oui, montre-moi des accessoires")

    upsell = next(d for n, d in events if n == "ui_accessory_upsell")
    assert [p["sku"] for p in upsell["groups"][0]["items"]] == ["CARAFE", "VERRES"]  # adaptés à un rouge
    assert upsell["item_ids"] == [ID["PAUILLAC-2015"]], "accessoires pensés pour les bouteilles du panier"
    assert backend["items"].calls.call_count == 1, "aucun accessoire ajouté sans accord explicite"


def test_choosing_before_any_proposal_asks_which_selection(client: TestClient, backend: respx.MockRouter) -> None:
    events = chat(client, "Je choisis cette sélection")

    assert backend.calls.call_count == backend["checkout_hint"].calls.call_count
    assert text_of(events).strip().endswith("?")


def test_full_guided_journey_discovery_to_cart(client: TestClient, backend: respx.MockRouter) -> None:
    chat(client, "Bonjour")  # 1 découverte
    chat(client, SCENARIO)  # 2 proposition
    chat(client, "Et le moins cher ?")  # tri, sans nouvelle recherche
    chat(client, "Je choisis la sélection 1")  # 3 choix : la sélection part au panier
    assert backend["items"].calls.call_count == 1
    chat(client, "Oui, des accessoires")  # accessoires proposés sur demande seulement

    events = chat(client, f"Ajoute au panier [id:{ID['CARAFE']}]")  # 4 accessoire choisi

    carts = [d for n, d in events if n == "ui_cart_update"]
    assert carts[-1]["total_cents"] == 12000 + 14900


# --- Bouteille seule : uniquement sur demande explicite ----------------------------------------------------------


def test_single_bottle_request_searches_wines_and_skips_the_comparison(
    client: TestClient, backend: respx.MockRouter
) -> None:
    events = chat(client, "Je veux juste une bouteille de blanc, budget 100€")

    sent = json.loads(backend["search"].calls[0].request.content)
    assert sent["category"] == "wine"
    assert {"key": "type", "op": "eq", "value": "blanc", "include_missing": False} in sent["filters"]
    assert backend["verify"].calls.call_count == 0
    assert "Voici 1 bouteille qui correspond" in text_of(events)
    assert "ui_product_card" in names(events) and "ui_bundle_comparison" not in names(events)
    assert text_of(events).strip().endswith("?")


# --- Le prompt système -------------------------------------------------------------------------------------------


def test_system_prompt_encodes_the_advisor_posture_and_stays_small() -> None:
    lowered = SYSTEM_PROMPT.lower()
    for expected in ("découverte", "proposition", "choix", "panier", "sans outil", "jamais", "question"):
        assert expected in lowered, expected
    assert "markdown" in lowered and "jargon" in lowered
    assert len(SYSTEM_PROMPT) < 4800  # envoyé à chaque appel LLM (~1200 tokens ; quota gratuit de 8 000 tokens/min)


def test_system_prompt_adds_the_choice_to_the_cart_and_never_forces_accessories_or_payment() -> None:
    lowered = SYSTEM_PROMPT.lower()
    assert "je choisis" in lowered and "update_cart_tool pour chacune de ses bouteilles" in lowered
    assert "facultatifs" in lowered and "sans insister" in lowered
    assert "s'il refuse" in lowered and "passer à la commande" in lowered
    assert "pas de start_checkout_tool avant" in lowered
    assert "edit_checkout_tool" in lowered and "je me suis trompé d'adresse" in lowered
    assert "consigne serveur" in lowered and "[customer_name]" in lowered


def test_system_prompt_covers_profiles_and_the_sales_only_focus() -> None:
    lowered = SYSTEM_PROMPT.lower()
    for expected in ("amateur", "connaisseur", "vente uniquement", "boire avec modération", "insufficient_stock"):
        assert expected in lowered, expected
    assert "n'invente jamais" in lowered and "non renseigné au catalogue" in lowered
    assert "auracave" in lowered and "sommelier" in lowered
