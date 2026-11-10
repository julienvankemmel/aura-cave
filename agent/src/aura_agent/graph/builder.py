import json
import logging
import uuid
from dataclasses import dataclass
from typing import Annotated, Any, Literal, TypedDict

from langchain_core.messages import AIMessage, AnyMessage, HumanMessage, SystemMessage, ToolMessage
from langchain_core.runnables import Runnable
from langgraph.checkpoint.memory import InMemorySaver
from langgraph.config import get_stream_writer
from langgraph.graph import END, START, StateGraph
from langgraph.graph.message import add_messages
from langgraph.graph.state import CompiledStateGraph
from pydantic import ValidationError

from aura_agent.core.guardrails import check_user_message, refusal_for
from aura_agent.core.prompts import SYSTEM_PROMPT
from aura_agent.graph.llm_view import KEEP_ACROSS_TURNS, llm_view
from aura_agent.mcp.tools import TOOL_ERRORS, TOOL_SPECS, BackendTools

logger = logging.getLogger(__name__)

UiEventName = Literal[
    "text_delta",
    "ui_bundle_comparison",
    "ui_product_card",
    "ui_accessory_upsell",
    "ui_checkout_step",
    "ui_cart_update",
]
MAX_PRODUCT_CARDS = 3


class ChatState(TypedDict, total=False):
    messages: Annotated[list[AnyMessage], add_messages]
    cart_id: str
    # Consigne de Symfony sur l'étape de checkout en cours (sans donnée personnelle) ; jamais la saisie du client.
    checkout_hint: str | None
    blocked: bool


@dataclass(frozen=True)
class GraphDeps:
    llm: Runnable[Any, Any]  # déjà lié aux tools et chaîné avec les fallbacks
    tools: BackendTools


def _emit(event: UiEventName, data: dict[str, Any]) -> None:
    get_stream_writer()({"event": event, "data": data})


def build_graph(deps: GraphDeps) -> CompiledStateGraph[Any, Any, Any, Any]:
    async def guard(state: ChatState) -> dict[str, Any]:
        """Dernière ligne de défense : le message est re-vérifié et masqué avant le LLM / le State."""
        messages = state["messages"]
        last = messages[-1]
        if not isinstance(last, HumanMessage):
            return {"blocked": False}
        result = check_user_message(str(last.content))
        if result.blocked:
            refusal = refusal_for(result.blocked)
            logger.info("message bloqué", extra={"reason": result.blocked.value})
            _emit("text_delta", {"text": refusal})
            return {"blocked": True, "messages": [AIMessage(refusal)]}
        update: dict[str, Any] = {"blocked": False}
        if result.sanitized_text != last.content:
            update["messages"] = [HumanMessage(result.sanitized_text, id=last.id)]
        if result.pii_detected:
            logger.info("PII masquée", extra={"kinds": list(result.pii_kinds)})
        return update

    def route_after_guard(state: ChatState) -> str:
        if state.get("blocked"):
            return END
        return "agent"

    async def agent(state: ChatState) -> dict[str, Any]:
        prompt = SYSTEM_PROMPT
        if hint := state.get("checkout_hint"):
            prompt += CHECKOUT_HINT_PREFIX + hint
        reply = await deps.llm.ainvoke([SystemMessage(prompt), *compact_history(state["messages"])])
        return {"messages": [reply]}

    def route_after_agent(state: ChatState) -> str:
        last = state["messages"][-1]
        return "tools" if isinstance(last, AIMessage) and last.tool_calls else END

    async def tools(state: ChatState) -> dict[str, Any]:
        last = state["messages"][-1]
        assert isinstance(last, AIMessage)  # noqa: S101
        cart_id = state["cart_id"]
        outputs: list[ToolMessage] = []
        done = _calls_already_made(state["messages"])
        failed = _failed_signatures(state["messages"])
        for call in last.tool_calls:
            call_id = call["id"] or uuid.uuid4().hex
            signature = _signature(call["name"], call["args"])
            if signature in failed or (signature in done and call["name"] not in MUTATING_TOOLS):
                # Appel identique déjà fait dans ce tour : on ne rejoue ni le réseau ni l'événement UI.
                note = json.dumps(
                    {"note": "appel identique déjà effectué dans ce tour : réutilise le résultat précédent"},
                    ensure_ascii=False,
                )
                outputs.append(ToolMessage(note, name=call["name"], tool_call_id=call_id))
                continue
            done.add(signature)
            outputs.append(await _run_tool(deps.tools, call["name"], call["args"], call_id, cart_id))
        return {"messages": outputs}

    graph = StateGraph(ChatState)
    graph.add_node("guard", guard)
    graph.add_node("agent", agent)
    graph.add_node("tools", tools)
    graph.add_edge(START, "guard")
    graph.add_conditional_edges("guard", route_after_guard, ["agent", END])
    graph.add_conditional_edges("agent", route_after_agent, ["tools", END])
    graph.add_edge("tools", "agent")
    return graph.compile(checkpointer=InMemorySaver())


MUTATING_TOOLS = frozenset({"update_cart_tool", "remove_from_cart_tool", "start_checkout_tool", "edit_checkout_tool"})
# Ajouté au prompt système pendant le tunnel de commande : la consigne vient de Symfony, jamais du client.
CHECKOUT_HINT_PREFIX = (
    "\n\nCONSIGNE SERVEUR (commande en cours, fiable). Le client répond dans un canal sécurisé : tu ne vois que des "
    "jetons entre crochets ([CUSTOMER_NAME], [SHIPPING_ADDRESS], [CUSTOMER_PHONE], [CUSTOMER_EMAIL], "
    "[INVALID_INPUT]), jamais la donnée. N'écris, ne demande et ne répète jamais de donnée personnelle ; "
    "une seule question par message. Consigne : "
)
OMITTED = json.dumps({"note": "résultat d'un tour précédent omis"}, ensure_ascii=False)


def _signature(name: str, args: dict[str, Any]) -> str:
    return name + json.dumps(args, sort_keys=True, default=str)


def _current_turn(messages: list[AnyMessage]) -> list[AnyMessage]:
    start = max((i for i, m in enumerate(messages) if isinstance(m, HumanMessage)), default=-1)
    return messages[start + 1 :]


def _calls_already_made(messages: list[AnyMessage]) -> set[str]:
    """Signatures des appels d'outils déjà exécutés (et répondus) dans le tour courant."""
    turn = _current_turn(messages)
    answered = {m.tool_call_id for m in turn if isinstance(m, ToolMessage)}
    return {
        _signature(c["name"], c["args"])
        for m in turn
        if isinstance(m, AIMessage)
        for c in m.tool_calls
        if c["id"] in answered
    }


def _failed_signatures(messages: list[AnyMessage]) -> set[str]:
    """Signatures des appels du tour courant dont le résultat était une erreur (inutile de réessayer à l'identique)."""
    turn = _current_turn(messages)
    failed_ids = {m.tool_call_id for m in turn if isinstance(m, ToolMessage) and str(m.content).startswith('{"error"')}
    return {
        _signature(c["name"], c["args"])
        for m in turn
        if isinstance(m, AIMessage)
        for c in m.tool_calls
        if c["id"] in failed_ids
    }


def compact_history(messages: list[AnyMessage]) -> list[AnyMessage]:
    """Tours précédents : on ne garde que le dernier comparatif et le dernier état du panier.

    Les anciens résultats de recherche/accessoires sont remplacés par une note : ils gonflent le contexte
    (donc la latence et la consommation de tokens) sans servir au tour courant.
    """
    last_human = max((i for i, m in enumerate(messages) if isinstance(m, HumanMessage)), default=-1)
    latest: dict[str, int] = {}
    for i, m in enumerate(messages[:last_human]):
        if isinstance(m, ToolMessage) and m.name in KEEP_ACROSS_TURNS:
            latest[m.name or ""] = i
    keep = set(latest.values())
    compacted: list[AnyMessage] = []
    for i, m in enumerate(messages):
        if isinstance(m, ToolMessage) and i < last_human and i not in keep:
            compacted.append(ToolMessage(OMITTED, name=m.name, tool_call_id=m.tool_call_id))
        else:
            compacted.append(m)
    return compacted


async def _run_tool(
    tools: BackendTools, name: str, raw_args: dict[str, Any], call_id: str, cart_id: str
) -> ToolMessage:
    spec = TOOL_SPECS.get(name)
    if spec is None:
        return ToolMessage(json.dumps({"error": "unknown_tool"}), name=name, tool_call_id=call_id)
    try:
        args = spec.args_model.model_validate(raw_args).model_dump(mode="json", exclude_none=True)
    except ValidationError:
        return ToolMessage(json.dumps({"error": "invalid_arguments"}), name=name, tool_call_id=call_id)
    try:
        result = await spec.run(tools, args, cart_id)
    except TOOL_ERRORS as exc:
        code = getattr(exc, "code", None) or str(exc)
        result = {"error": code, **getattr(exc, "extra", {})}
    except Exception:  # noqa: BLE001 — jamais de trace ni de message brut vers le LLM
        logger.exception("échec outil", extra={"tool": name})
        result = {"error": "tool_failed"}
    else:
        _emit_tool_ui(spec.ui_event, result)
    return ToolMessage(llm_view(name, result), name=name, tool_call_id=call_id)


def _emit_tool_ui(event: str | None, result: dict[str, Any]) -> None:
    match event:
        case "ui_product_card":
            results = result.get("results", [])
            # Garde-fou : une recherche qui mêle vins et accessoires prépare une sélection, elle n'affiche jamais de
            # pièces seules. Les cartes ne servent qu'aux demandes explicites d'une référence (une seule catégorie).
            if len({str(p.get("category")) for p in results}) > 1:
                return
            for product in results[:MAX_PRODUCT_CARDS]:
                _emit("ui_product_card", product)
        case "ui_bundle_comparison":
            if result.get("selections"):
                _emit("ui_bundle_comparison", result)
        case "ui_accessory_upsell":
            _emit("ui_accessory_upsell", result)
        case "ui_cart_update":
            _emit("ui_cart_update", result)
        case "ui_checkout_step":
            _emit("ui_checkout_step", {"step": result.get("step")})
