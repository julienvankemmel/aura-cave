# syntax=docker/dockerfile:1.7

# --- Stage 1 : dépendances (uv) + modèle d'embedding multilingue 384d pré-téléchargé ---
FROM python:3.12-slim AS builder
COPY --from=ghcr.io/astral-sh/uv:0.5 /uv /usr/local/bin/uv
ENV UV_COMPILE_BYTECODE=1 UV_LINK_MODE=copy UV_PYTHON_DOWNLOADS=never
WORKDIR /app
COPY pyproject.toml uv.lock ./
RUN uv sync --frozen --no-dev --no-install-project
# Le modèle est embarqué dans l'image : aucun téléchargement ni écriture au runtime (fs en lecture seule).
ARG FASTEMBED_MODEL=sentence-transformers/paraphrase-multilingual-MiniLM-L12-v2
RUN /app/.venv/bin/python -c "from fastembed import TextEmbedding; import sys; \
m = TextEmbedding(model_name='${FASTEMBED_MODEL}', cache_dir='/opt/fastembed'); \
v = list(m.embed(['test']))[0]; sys.exit(0 if len(v) == 384 else 1)" \
    && chmod -R a+rX /opt/fastembed

COPY src ./src
RUN uv sync --frozen --no-dev --no-editable

# --- Stage 2 : runtime minimal non-root ---
FROM python:3.12-slim AS runtime
RUN apt-get update && apt-get upgrade -y --no-install-recommends && rm -rf /var/lib/apt/lists/* \
    && groupadd --gid 10001 appuser \
    && useradd --uid 10001 --gid appuser --no-create-home --shell /usr/sbin/nologin appuser
COPY --from=builder --chown=root:root /app/.venv /app/.venv
COPY --from=builder --chown=root:root /opt/fastembed /opt/fastembed
ENV PATH="/app/.venv/bin:$PATH" PYTHONUNBUFFERED=1 PYTHONDONTWRITEBYTECODE=1 \
    FASTEMBED_CACHE_PATH=/opt/fastembed HF_HUB_OFFLINE=1
WORKDIR /app
USER appuser
EXPOSE 8001
HEALTHCHECK --interval=15s --timeout=3s --start-period=60s --retries=3 \
    CMD ["python", "-c", "import urllib.request,sys; sys.exit(0 if urllib.request.urlopen('http://127.0.0.1:8001/health', timeout=2).status == 200 else 1)"]
CMD ["uvicorn", "aura_agent.main:app", "--host", "0.0.0.0", "--port", "8001"]
