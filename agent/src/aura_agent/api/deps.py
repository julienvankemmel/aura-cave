import hmac
from typing import Annotated

from fastapi import Depends, Header, HTTPException, Request, status

from aura_agent.config import Settings


def get_app_settings(request: Request) -> Settings:
    settings: Settings = request.app.state.settings
    return settings


def require_internal_key(
    settings: Annotated[Settings, Depends(get_app_settings)],
    x_internal_api_key: Annotated[str, Header()] = "",
) -> None:
    """Fail closed : sans clé configurée côté agent, tout est refusé."""
    expected = settings.internal_api_key.get_secret_value()
    if not expected or not hmac.compare_digest(expected, x_internal_api_key):
        raise HTTPException(status.HTTP_401_UNAUTHORIZED, "Unauthorized")
