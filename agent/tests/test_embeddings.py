import math
import os

import pytest
from fastapi.testclient import TestClient

from aura_agent.core.embeddings import EMBEDDING_DIMENSIONS, FastEmbedEmbedder, MockEmbedder, get_embedder
from tests.conftest import API_KEY, make_settings

HEADERS = {"X-Internal-Api-Key": API_KEY}


def cosine(a: list[float], b: list[float]) -> float:
    return sum(x * y for x, y in zip(a, b, strict=True))


def test_mock_embedder_is_384d_normalized_and_deterministic() -> None:
    embedder = MockEmbedder()
    first, second = embedder.embed(["vin rouge de Bordeaux", "vin rouge de Bordeaux"])

    assert len(first) == EMBEDDING_DIMENSIONS
    assert math.isclose(math.sqrt(sum(v * v for v in first)), 1.0, rel_tol=1e-9)
    assert first == second


def test_mock_embedder_keeps_lexical_proximity_and_batch_order() -> None:
    a, b, c = MockEmbedder().embed(
        ["vin rouge de Bordeaux", "grand cru rouge de Bordeaux, Pauillac 2015", "recette de crêpes"]
    )

    assert cosine(a, b) > cosine(a, c)


def test_get_embedder_selects_mock_from_settings() -> None:
    assert isinstance(get_embedder(make_settings(fastembed_mock=True)), MockEmbedder)
    assert isinstance(get_embedder(make_settings(fastembed_mock=False)), FastEmbedEmbedder)


def test_embed_endpoint_single_text_matches_symfony_contract(client: TestClient) -> None:
    response = client.post("/internal/embed", json={"text": "tirage A5"}, headers=HEADERS)

    assert response.status_code == 200
    body = response.json()
    assert len(body["embedding"]) == 384  # clé lue par AgentEmbeddingClient côté Symfony
    assert body["dimensions"] == 384


def test_embed_endpoint_batch(client: TestClient) -> None:
    response = client.post("/internal/embed", json={"texts": ["a", "b", "c"]}, headers=HEADERS)

    assert response.status_code == 200
    assert [len(v) for v in response.json()["embeddings"]] == [384, 384, 384]


def test_embed_endpoint_requires_internal_key(client: TestClient) -> None:
    assert client.post("/internal/embed", json={"text": "x"}).status_code == 401
    assert client.post("/internal/embed", json={"text": "x"}, headers={"X-Internal-Api-Key": "bad"}).status_code == 401


@pytest.mark.parametrize(
    "payload", [{}, {"text": "a", "texts": ["b"]}, {"text": ""}, {"text": "x" * 2001}, {"texts": []}]
)
def test_embed_endpoint_rejects_invalid_payloads(client: TestClient, payload: dict[str, object]) -> None:
    assert client.post("/internal/embed", json=payload, headers=HEADERS).status_code == 422


@pytest.mark.skipif(
    not os.environ.get("RUN_FASTEMBED_MODEL"), reason="télécharge ~220 Mo ; activer avec RUN_FASTEMBED_MODEL=1"
)
def test_real_multilingual_model_understands_french() -> None:
    embedder = FastEmbedEmbedder(
        "sentence-transformers/paraphrase-multilingual-MiniLM-L12-v2", os.environ.get("FASTEMBED_CACHE_PATH")
    )
    query, wine, cuisine = embedder.embed(
        [
            "vin rouge puissant pour un gigot d'agneau",
            "Pauillac 2015, rouge de Bordeaux aux tanins soyeux, accord viande rouge et agneau",
            "recette de tarte aux pommes",
        ]
    )

    assert len(query) == 384
    assert cosine(query, wine) > cosine(query, cuisine) + 0.2
