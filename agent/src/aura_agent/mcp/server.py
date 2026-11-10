"""Serveur FastMCP exposant les mêmes outils (usage : `python -m aura_agent.mcp.server`, stdio)."""

from fastmcp import FastMCP

from aura_agent.config import get_settings
from aura_agent.mcp.backend_client import BackendClient
from aura_agent.mcp.tools import BackendTools


def build_mcp_server(tools: BackendTools) -> FastMCP:
    mcp = FastMCP("aura-catalog-tools")
    for fn in (
        tools.propose_selections_tool,
        tools.search_catalog_tool,
        tools.verify_and_compare_selections_tool,
        tools.recommend_compatible_accessories_tool,
        tools.update_cart_tool,
        tools.remove_from_cart_tool,
        tools.start_checkout_tool,
        tools.edit_checkout_tool,
    ):
        mcp.tool(fn)
    return mcp


if __name__ == "__main__":
    settings = get_settings()
    client = BackendClient(settings.backend_url, settings.internal_api_key)
    build_mcp_server(BackendTools(client)).run()
