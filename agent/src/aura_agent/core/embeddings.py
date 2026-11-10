import hashlib
import math
import re
from collections.abc import Sequence
from functools import lru_cache
from typing import Protocol

from aura_agent.config import Settings

EMBEDDING_DIMENSIONS = 384


class Embedder(Protocol):
    model_name: str

    def embed(self, texts: Sequence[str]) -> list[list[float]]:
        """Vecteurs de 384 dimensions, normalisés L2, dans l'ordre des textes."""
        ...


def _normalize(vector: Sequence[float]) -> list[float]:
    norm = math.sqrt(sum(v * v for v in vector))
    return [float(v) / norm for v in vector] if norm > 0 else [float(v) for v in vector]


class MockEmbedder:
    """Mode léger (FASTEMBED_MOCK=true) : feature hashing déterministe, sans modèle ni réseau."""

    model_name = "mock-hash-384"

    def embed(self, texts: Sequence[str]) -> list[list[float]]:
        return [self._embed_one(t) for t in texts]

    @staticmethod
    def _embed_one(text: str) -> list[float]:
        vector = [0.0] * EMBEDDING_DIMENSIONS
        tokens = re.findall(r"\w+", text.lower())
        features = tokens + [f"{a} {b}" for a, b in zip(tokens, tokens[1:], strict=False)]
        for feature in features:
            digest = hashlib.sha256(feature.encode()).digest()
            index = int.from_bytes(digest[:4], "big") % EMBEDDING_DIMENSIONS
            vector[index] += 1.0 if digest[4] & 1 else -1.0
        return _normalize(vector)


class FastEmbedEmbedder:
    """Modèle multilingue (FR inclus) exécuté localement via ONNX, chargé à la première utilisation."""

    def __init__(self, model_name: str, cache_dir: str | None) -> None:
        self.model_name = model_name
        self._cache_dir = cache_dir
        self._model: object | None = None

    def load(self) -> None:
        if self._model is None:
            from fastembed import TextEmbedding

            self._model = TextEmbedding(model_name=self.model_name, cache_dir=self._cache_dir)

    def embed(self, texts: Sequence[str]) -> list[list[float]]:
        self.load()
        model = self._model
        assert model is not None  # noqa: S101
        vectors = [_normalize(v.tolist()) for v in model.embed(list(texts))]  # type: ignore[attr-defined]
        if any(len(v) != EMBEDDING_DIMENSIONS for v in vectors):
            raise ValueError(f"{self.model_name} ne produit pas {EMBEDDING_DIMENSIONS} dimensions")
        return vectors


@lru_cache
def _build(mock: bool, model_name: str, cache_dir: str | None) -> Embedder:
    return MockEmbedder() if mock else FastEmbedEmbedder(model_name, cache_dir)


def get_embedder(settings: Settings) -> Embedder:
    return _build(settings.fastembed_mock, settings.fastembed_model, settings.fastembed_cache_path)
