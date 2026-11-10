from collections.abc import Sequence
from typing import Any

import anthropic
import httpx
import openai
from langchain_anthropic import ChatAnthropic
from langchain_core.language_models import BaseChatModel
from langchain_core.messages import BaseMessage
from langchain_core.rate_limiters import InMemoryRateLimiter
from langchain_core.runnables import Runnable
from langchain_openai import ChatOpenAI
from pydantic import SecretStr

from aura_agent.config import LLMProvider, Settings
from aura_agent.core.mock_llm import MockChatModel

GROQ_BASE_URL = "https://api.groq.com/openai/v1"
OPENROUTER_BASE_URL = "https://openrouter.ai/api/v1"

# Basculement sur toute indisponibilité du primaire : 429, 5xx, timeout, réseau, mais aussi modèle
# retiré (404), clé refusée (401/403) ou requête non supportée (400). `APIStatusError` couvre tous les statuts HTTP.
FALLBACK_EXCEPTIONS: tuple[type[BaseException], ...] = (
    openai.APIStatusError,
    openai.APIConnectionError,
    openai.APITimeoutError,
    anthropic.APIStatusError,
    anthropic.APIConnectionError,
    anthropic.APITimeoutError,
)

PREMIUM_PROVIDERS: tuple[LLMProvider, ...] = ("openai", "anthropic")
FREE_PROVIDERS: tuple[LLMProvider, ...] = ("groq", "openrouter")
ANTHROPIC_MAX_TOKENS = 1024

ChatRunnable = Runnable[Any, BaseMessage]


def resolve_providers(settings: Settings) -> list[LLMProvider]:
    """Chaîne effective [primaire, secours...], limitée aux fournisseurs dont la clé est renseignée.

    Le fournisseur choisi passe en premier ; viennent ensuite les autres offres premium puis les modèles gratuits
    (Groq, OpenRouter). Sans aucune clé exploitable : mock déterministe (démo et CI).
    """
    keyed: dict[LLMProvider, bool] = {
        "openai": bool(settings.openai_api_key.get_secret_value()),
        "anthropic": bool(settings.anthropic_api_key.get_secret_value()),
        "groq": bool(settings.groq_api_key.get_secret_value()),
        "openrouter": bool(settings.openrouter_api_key.get_secret_value()),
    }
    match settings.llm_provider:
        case "mock":
            return ["mock"]
        case "ollama":
            return ["ollama"]
        case chosen:
            order = [chosen, *PREMIUM_PROVIDERS, *FREE_PROVIDERS]
            chain = [p for p in dict.fromkeys(order) if keyed.get(p)]
            return chain or ["mock"]


def _chat_openai(
    *,
    model: str,
    base_url: str | None,
    api_key: SecretStr,
    limiter: InMemoryRateLimiter,
    http_client: httpx.AsyncClient | None,
    max_retries: int = 0,
) -> ChatOpenAI:
    return ChatOpenAI(
        model=model,
        base_url=base_url,
        api_key=api_key,
        temperature=0.2,
        max_retries=max_retries,
        timeout=30,
        rate_limiter=limiter,
        http_async_client=http_client,
    )


def _chat_anthropic(
    *,
    model: str,
    api_key: SecretStr,
    limiter: InMemoryRateLimiter,
    http_client: Any | None,
    max_retries: int = 0,
) -> ChatAnthropic:
    chat = ChatAnthropic(
        model=model,
        api_key=api_key,
        # Pas de `temperature` : les derniers modèles Claude refusent toute valeur non par défaut.
        max_tokens=ANTHROPIC_MAX_TOKENS,
        max_retries=max_retries,
        timeout=30,
        rate_limiter=limiter,
    )
    if http_client is not None:
        # Couture de test : le client async de langchain-anthropic est un cached_property, on le préremplit.
        chat.__dict__["_async_client"] = anthropic.AsyncAnthropic(
            api_key=api_key.get_secret_value(),
            http_client=http_client,
            max_retries=max_retries,
        )
    return chat


def _build_one(
    provider: LLMProvider,
    settings: Settings,
    http_client: httpx.AsyncClient | None,
    max_retries: int,
    anthropic_http_client: Any | None = None,
) -> BaseChatModel:
    premium = provider in PREMIUM_PROVIDERS
    limiter = InMemoryRateLimiter(
        requests_per_second=settings.llm_premium_requests_per_second if premium else settings.llm_requests_per_second,
        max_bucket_size=5,
    )
    match provider:
        case "openai":
            return _chat_openai(
                model=settings.openai_model,
                base_url=None,  # API OpenAI officielle
                api_key=settings.openai_api_key,
                limiter=limiter,
                http_client=http_client,
                max_retries=max_retries,
            )
        case "anthropic":
            return _chat_anthropic(
                model=settings.anthropic_model,
                api_key=settings.anthropic_api_key,
                limiter=limiter,
                http_client=anthropic_http_client,
                max_retries=max_retries,
            )
        case "groq":
            return _chat_openai(
                model=settings.groq_model,
                base_url=GROQ_BASE_URL,
                api_key=settings.groq_api_key,
                limiter=limiter,
                http_client=http_client,
                max_retries=max_retries,
            )
        case "openrouter":
            return _chat_openai(
                model=settings.openrouter_model,
                base_url=OPENROUTER_BASE_URL,
                api_key=settings.openrouter_api_key,
                limiter=limiter,
                http_client=http_client,
                max_retries=max_retries,
            )
        case "ollama":
            return _chat_openai(
                model=settings.ollama_model,
                base_url=settings.ollama_base_url,
                api_key=SecretStr("ollama"),
                limiter=limiter,
                http_client=http_client,
                max_retries=max_retries,
            )
        case "mock":
            return MockChatModel()
    raise ValueError(f"provider inconnu : {provider}")


def build_chat_model(
    settings: Settings,
    tools: Sequence[dict[str, Any]] = (),
    http_client: httpx.AsyncClient | None = None,
    anthropic_http_client: Any | None = None,
) -> ChatRunnable:
    """Modèle avec tools liés sur chaque provider, puis chaîné avec `.with_fallbacks()`.

    `http_client` et `anthropic_http_client` (le SDK Anthropic exige un client `httpx2`) sont des coutures de test
    (transport simulé) ; en production chaque SDK crée le sien.
    """
    # Seul le primaire retente (retry-after) ; les secours échouent vite pour ne pas allonger la latence.
    models = [
        _build_one(p, settings, http_client, settings.llm_primary_max_retries if i == 0 else 0, anthropic_http_client)
        for i, p in enumerate(resolve_providers(settings))
    ]
    bound: list[ChatRunnable] = [m.bind_tools(list(tools)) if tools else m for m in models]
    primary, *fallbacks = bound
    if not fallbacks:
        return primary
    return primary.with_fallbacks(fallbacks, exceptions_to_handle=FALLBACK_EXCEPTIONS)
