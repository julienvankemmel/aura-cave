import httpx
import pytest
import respx
from langchain_core.messages import AIMessage
from langchain_core.runnables.fallbacks import RunnableWithFallbacks
from pydantic import SecretStr

from aura_agent.core.llm_factory import GROQ_BASE_URL, OPENROUTER_BASE_URL, build_chat_model, resolve_providers
from aura_agent.core.mock_llm import MockChatModel
from tests.conftest import make_settings

OPENAI_URL = "https://api.openai.com/v1/chat/completions"
ANTHROPIC_URL = "https://api.anthropic.com/v1/messages"
GROQ_URL = f"{GROQ_BASE_URL}/chat/completions"
OPENROUTER_URL = f"{OPENROUTER_BASE_URL}/chat/completions"


def completion(text: str) -> httpx.Response:
    return httpx.Response(
        200,
        json={
            "id": "chatcmpl-1",
            "object": "chat.completion",
            "created": 0,
            "model": "m",
            "choices": [{"index": 0, "message": {"role": "assistant", "content": text}, "finish_reason": "stop"}],
            "usage": {"prompt_tokens": 1, "completion_tokens": 1, "total_tokens": 2},
        },
    )


def keys(groq: str = "", openrouter: str = "") -> dict[str, SecretStr]:
    return {"groq_api_key": SecretStr(groq), "openrouter_api_key": SecretStr(openrouter)}


@pytest.mark.parametrize(
    ("provider", "groq", "openrouter", "expected"),
    [
        ("groq", "g", "o", ["groq", "openrouter"]),
        ("groq", "g", "", ["groq"]),
        ("groq", "", "o", ["openrouter"]),
        ("groq", "", "", ["mock"]),
        ("openrouter", "", "o", ["openrouter"]),
        ("openrouter", "", "", ["mock"]),
        ("ollama", "", "", ["ollama"]),
        ("mock", "g", "o", ["mock"]),
    ],
)
def test_resolve_providers(provider: str, groq: str, openrouter: str, expected: list[str]) -> None:
    settings = make_settings(llm_provider=provider, **keys(groq, openrouter))

    assert resolve_providers(settings) == expected


def test_no_api_key_yields_deterministic_mock_without_fallback_wrapper() -> None:
    llm = build_chat_model(make_settings(llm_provider="groq"))

    assert isinstance(llm, MockChatModel)


def test_groq_primary_with_openrouter_fallback_chain() -> None:
    llm = build_chat_model(make_settings(llm_provider="groq", **keys("g", "o")))

    assert isinstance(llm, RunnableWithFallbacks)
    assert len(llm.fallbacks) == 1


@pytest.mark.parametrize("status", [429, 503])
async def test_falls_back_to_openrouter_on_rate_limit_or_outage(status: int) -> None:
    llm = build_chat_model(
        make_settings(llm_provider="groq", **keys("g-key", "o-key")), http_client=httpx.AsyncClient()
    )

    with respx.mock(assert_all_called=True) as mock:
        groq = mock.post(GROQ_URL).mock(return_value=httpx.Response(status, json={"error": {"message": "nope"}}))
        router = mock.post(OPENROUTER_URL).mock(return_value=completion("réponse de secours"))

        reply = await llm.ainvoke("bonjour")

    assert isinstance(reply, AIMessage)
    assert reply.content == "réponse de secours"
    assert groq.call_count == 1  # max_retries=0 : bascule immédiate
    assert router.call_count == 1
    assert groq.calls[0].request.headers["authorization"] == "Bearer g-key"
    assert router.calls[0].request.headers["authorization"] == "Bearer o-key"


async def test_primary_is_used_when_healthy() -> None:
    llm = build_chat_model(make_settings(llm_provider="groq", **keys("g", "o")), http_client=httpx.AsyncClient())

    with respx.mock(assert_all_called=False) as mock:
        mock.post(GROQ_URL).mock(return_value=completion("primaire"))
        router = mock.post(OPENROUTER_URL).mock(return_value=completion("secours"))

        reply = await llm.ainvoke("bonjour")

    assert reply.content == "primaire"
    assert router.call_count == 0


@pytest.mark.parametrize("status", [400, 401, 403, 404])
async def test_falls_back_on_misconfigured_primary(status: int) -> None:
    """Modèle retiré (404), clé refusée (401/403) ou requête refusée (400) : le secours prend le relais."""
    llm = build_chat_model(make_settings(llm_provider="groq", **keys("g", "o")), http_client=httpx.AsyncClient())

    with respx.mock(assert_all_called=True) as mock:
        mock.post(GROQ_URL).mock(return_value=httpx.Response(status, json={"error": {"message": "model gone"}}))
        router = mock.post(OPENROUTER_URL).mock(return_value=completion("secours"))

        reply = await llm.ainvoke("bonjour")

    assert reply.content == "secours"
    assert router.call_count == 1


async def test_primary_error_propagates_when_every_provider_fails() -> None:
    llm = build_chat_model(make_settings(llm_provider="groq", **keys("g", "o")), http_client=httpx.AsyncClient())

    with respx.mock(assert_all_called=True) as mock:
        mock.post(GROQ_URL).mock(return_value=httpx.Response(404, json={"error": {"message": "gone"}}))
        mock.post(OPENROUTER_URL).mock(return_value=httpx.Response(503, json={"error": {"message": "down"}}))

        with pytest.raises(Exception, match="404|gone"):
            await llm.ainvoke("bonjour")


async def test_ollama_provider_targets_configured_base_url() -> None:
    llm = build_chat_model(
        make_settings(llm_provider="ollama", ollama_base_url="http://ollama.test/v1"), http_client=httpx.AsyncClient()
    )

    with respx.mock() as mock:
        route = mock.post("http://ollama.test/v1/chat/completions").mock(return_value=completion("local"))
        reply = await llm.ainvoke("salut")

    assert reply.content == "local"
    assert route.called


async def test_mock_model_orchestrates_tools_deterministically() -> None:
    llm = build_chat_model(make_settings(llm_provider="mock"))

    reply = await llm.ainvoke("Un vin rouge de Bordeaux pour un agneau, budget 400€")

    assert isinstance(reply, AIMessage)
    # demande d'ensemble : un seul outil, dédié aux selections (jamais de recherche de pièces seules)
    assert [c["name"] for c in reply.tool_calls] == ["propose_selections_tool"]
    args = reply.tool_calls[0]["args"]
    assert args["budget_cents"] == 40000
    assert args["filters"] == [
        {"key": "region", "op": "eq", "value": "Bordeaux"},
        {"key": "type", "op": "eq", "value": "rouge"},
    ]
    assert args["dish"] == "agneau"
    assert "categories" not in args and "category" not in args
