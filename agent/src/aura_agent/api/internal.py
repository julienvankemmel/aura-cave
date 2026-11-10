import asyncio
from typing import Annotated

from fastapi import APIRouter, Depends, HTTPException, Request
from pydantic import BaseModel, ConfigDict, Field, model_validator

from aura_agent.api.deps import require_internal_key
from aura_agent.core.embeddings import EMBEDDING_DIMENSIONS, Embedder

router = APIRouter(prefix="/internal", dependencies=[Depends(require_internal_key)])

MAX_TEXT_CHARS = 2000
MAX_BATCH = 64


class EmbedRequest(BaseModel):
    """`text` (format de app:seed-catalog côté Symfony) ou `texts` (lot)."""

    model_config = ConfigDict(extra="forbid")

    text: str | None = Field(default=None, min_length=1, max_length=MAX_TEXT_CHARS)
    texts: list[Annotated[str, Field(min_length=1, max_length=MAX_TEXT_CHARS)]] | None = Field(
        default=None, min_length=1, max_length=MAX_BATCH
    )

    @model_validator(mode="after")
    def _exactly_one(self) -> "EmbedRequest":
        if (self.text is None) == (self.texts is None):
            raise ValueError("fournir exactement un champ parmi 'text' et 'texts'")
        return self


class EmbedResponse(BaseModel):
    model: str
    dimensions: int = EMBEDDING_DIMENSIONS
    embedding: list[float] | None = None
    embeddings: list[list[float]] | None = None


@router.post("/embed")
async def embed(payload: EmbedRequest, request: Request) -> EmbedResponse:
    embedder: Embedder = request.app.state.embedder
    batch = [payload.text] if payload.text is not None else (payload.texts or [])
    try:
        vectors = await asyncio.to_thread(embedder.embed, batch)
    except Exception as exc:
        raise HTTPException(503, "Embedding model unavailable") from exc
    if payload.text is not None:
        return EmbedResponse(model=embedder.model_name, embedding=vectors[0])
    return EmbedResponse(model=embedder.model_name, embeddings=vectors)
