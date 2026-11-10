import asyncio
import logging
from collections.abc import AsyncIterator
from contextlib import asynccontextmanager

from fastapi import FastAPI
from pydantic import BaseModel

from aura_agent.api import chat, internal
from aura_agent.config import Settings, get_settings
from aura_agent.core.embeddings import FastEmbedEmbedder, get_embedder
from aura_agent.core.llm_factory import build_chat_model
from aura_agent.graph.builder import GraphDeps, build_graph
from aura_agent.mcp.backend_client import BackendClient
from aura_agent.mcp.tools import BackendTools, tool_schemas

logger = logging.getLogger(__name__)


class HealthResponse(BaseModel):
    status: str


def create_app(settings: Settings | None = None) -> FastAPI:
    settings = settings or get_settings()

    @asynccontextmanager
    async def lifespan(app: FastAPI) -> AsyncIterator[None]:
        embedder = get_embedder(settings)
        if isinstance(embedder, FastEmbedEmbedder):
            # Charge le modèle ONNX au démarrage : la première requête de Symfony ne paie pas le délai.
            await asyncio.to_thread(embedder.load)
        backend = BackendClient(settings.backend_url, settings.internal_api_key)
        tools = BackendTools(backend)
        app.state.embedder = embedder
        app.state.tools = tools
        app.state.graph = build_graph(GraphDeps(llm=build_chat_model(settings, tool_schemas()), tools=tools))
        yield
        await backend.aclose()

    app = FastAPI(title="AuraCave Agent", docs_url=None, redoc_url=None, openapi_url=None, lifespan=lifespan)
    app.state.settings = settings
    app.include_router(internal.router)
    app.include_router(chat.router)

    @app.get("/health")
    async def health() -> HealthResponse:
        return HealthResponse(status="ok")

    return app


app = create_app()
