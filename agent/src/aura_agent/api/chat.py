import json
import logging
import uuid
from collections.abc import AsyncIterator
from typing import Any

from fastapi import APIRouter, Depends, Request
from fastapi.responses import StreamingResponse
from langchain_core.messages import AIMessageChunk, HumanMessage
from pydantic import BaseModel, ConfigDict, Field, field_validator

from aura_agent.api.deps import require_internal_key
from aura_agent.core.checkout_reply import vet_checkout_reply
from aura_agent.core.guardrails import check_user_message, refusal_for

logger = logging.getLogger(__name__)

router = APIRouter(dependencies=[Depends(require_internal_key)])

RECURSION_LIMIT = 14


class ChatRequest(BaseModel):
    model_config = ConfigDict(extra="forbid")

    cart_id: uuid.UUID
    # Pendant le checkout, le client n'envoie ici que des jetons d'anonymisation : ses coordonnées passent par Symfony.
    message: str = Field(min_length=1, max_length=2000)

    @field_validator("message")
    @classmethod
    def _not_blank(cls, value: str) -> str:
        if not value.strip():
            raise ValueError("message requis")
        return value

    @field_validator("cart_id")
    @classmethod
    def _v4(cls, value: uuid.UUID) -> uuid.UUID:
        if value.version != 4:
            raise ValueError("cart_id doit être un UUID v4")
        return value


def sse(event: str, data: dict[str, Any]) -> str:
    return f"event: {event}\ndata: {json.dumps(data, ensure_ascii=False)}\n\n"


async def event_stream(request: Request, payload: ChatRequest) -> AsyncIterator[str]:
    graph = request.app.state.graph
    cart_id = str(payload.cart_id)

    # La PII éventuelle est masquée avant d'entrer dans le State, le LLM ou les logs.
    guard = check_user_message(payload.message)
    if guard.blocked:
        yield sse("text_delta", {"text": refusal_for(guard.blocked)})
        yield sse("done", {"blocked": guard.blocked.value})
        return
    human = HumanMessage(guard.sanitized_text)

    # Étape de checkout en cours : consigne de Symfony (fiable, sans donnée personnelle) pour ce tour.
    checkout_hint = await request.app.state.tools.checkout_hint(cart_id)
    state_input = {"messages": [human], "cart_id": cart_id, "checkout_hint": checkout_hint}
    config = {"configurable": {"thread_id": cart_id}, "recursion_limit": RECURSION_LIMIT}
    # Tunnel de commande : la réponse est retenue puis contrôlée (phrase coupée, deux questions…) avant d'être affichée.
    buffered: list[str] = []
    try:
        async for mode, data in graph.astream(state_input, config, stream_mode=["messages", "custom"]):
            if mode == "custom":
                yield sse(str(data["event"]), data["data"])
                if data["event"] == "ui_checkout_step" and checkout_hint is None:
                    checkout_hint = await request.app.state.tools.checkout_hint(cart_id)  # commande tout juste démarrée
                continue
            chunk, meta = data
            if meta.get("langgraph_node") != "agent" or not isinstance(chunk.content, str):
                continue
            if isinstance(chunk, AIMessageChunk) and chunk.tool_call_chunks:
                continue
            if chunk.content:
                if checkout_hint is None:
                    yield sse("text_delta", {"text": chunk.content})
                else:
                    buffered.append(chunk.content)
        if checkout_hint is not None and (buffered or checkout_hint):
            yield sse("text_delta", {"text": vet_checkout_reply("".join(buffered), checkout_hint)})
    except Exception as exc:
        # Type + début du message (erreurs fournisseur LLM / backend), jamais la requête utilisateur.
        logger.error("Flux agent en échec : %s: %s", type(exc).__name__, str(exc)[:300])
        yield sse("error", {"code": "agent_failure", "message": "Le service est momentanément indisponible."})
    yield sse("done", {})


@router.post("/api/chat/stream")
async def chat_stream(payload: ChatRequest, request: Request) -> StreamingResponse:
    return StreamingResponse(
        event_stream(request, payload),
        media_type="text/event-stream",
        headers={"Cache-Control": "no-cache", "X-Accel-Buffering": "no"},
    )
