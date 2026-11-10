from functools import lru_cache
from typing import Literal

from pydantic import SecretStr
from pydantic_settings import BaseSettings, SettingsConfigDict

LLMProvider = Literal["openai", "anthropic", "groq", "openrouter", "ollama", "mock"]


class Settings(BaseSettings):
    model_config = SettingsConfigDict(extra="ignore")

    backend_url: str = "http://nginx:8080"
    internal_api_key: SecretStr = SecretStr("")

    llm_provider: LLMProvider = "groq"
    # Modèles premium (payants), à activer via LLM_PROVIDER=openai|anthropic + clé API ; les modèles gratuits
    # (Groq puis OpenRouter) restent le secours automatique.
    openai_api_key: SecretStr = SecretStr("")
    openai_model: str = "gpt-4o"
    anthropic_api_key: SecretStr = SecretStr("")
    anthropic_model: str = "claude-sonnet-5-5"
    groq_api_key: SecretStr = SecretStr("")
    groq_model: str = "openai/gpt-oss-120b"
    openrouter_api_key: SecretStr = SecretStr("")
    openrouter_model: str = "nvidia/nemotron-3-ultra-550b-a55b:free"
    ollama_base_url: str = "http://host.docker.internal:11434/v1"
    ollama_model: str = "llama3.1"
    # Groq free tier ~30 req/min ; OpenRouter :free ~20 req/min.
    llm_requests_per_second: float = 0.4
    # Les offres payantes n'ont pas ces quotas serrés : cadence bien plus haute.
    llm_premium_requests_per_second: float = 5.0
    # Retries du fournisseur primaire (le SDK respecte `retry-after` : utile contre les quotas de tokens/minute
    # des paliers gratuits, avant de basculer sur un secours plus lent).
    llm_primary_max_retries: int = 2

    fastembed_mock: bool = False
    fastembed_model: str = "sentence-transformers/paraphrase-multilingual-MiniLM-L12-v2"
    fastembed_cache_path: str | None = None


@lru_cache
def get_settings() -> Settings:
    return Settings()
