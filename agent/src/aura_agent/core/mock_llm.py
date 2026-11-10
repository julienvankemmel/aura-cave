"""Modèle de chat déterministe : simule l'orchestration des tools sans clé API (démo, CI, tests). Univers : le vin."""

import json
import re
import uuid
from collections.abc import AsyncIterator, Iterator, Sequence
from typing import Any

from langchain_core.callbacks import AsyncCallbackManagerForLLMRun, CallbackManagerForLLMRun
from langchain_core.language_models import BaseChatModel
from langchain_core.messages import AIMessage, AIMessageChunk, BaseMessage, HumanMessage, SystemMessage, ToolMessage
from langchain_core.outputs import ChatGeneration, ChatGenerationChunk, ChatResult
from langchain_core.runnables import Runnable

from aura_agent.core.checkout_reply import fallback_reply

_CHECKOUT = re.compile(r"payer|paiement|command(?:e|er)\b|checkout|livraison|finaliser|valider", re.IGNORECASE)
_HINT_MARKER = "CONSIGNE SERVEUR"
_QUOTED = re.compile(r"«\s*(.+?)\s*»", re.DOTALL)
_TOKEN = re.compile(r"\[(?:CUSTOMER_NAME|SHIPPING_ADDRESS|CUSTOMER_PHONE|CUSTOMER_EMAIL|INVALID_INPUT|EDIT_REQUEST)\]")
_EDIT_VERB = re.compile(r"tromp|corrig|modifi|chang(?:er|e)\b|erreur", re.IGNORECASE)
_DECLINE_ACCESSORIES = re.compile(
    r"non merci|pas d'?accessoire|sans accessoire|ne veux pas d'?accessoire|pas besoin d'?accessoire", re.IGNORECASE
)
_YES = re.compile(r"^\s*(?:oui|ok|d'accord|volontiers|bien s[ûu]r|allons-y|avec plaisir|yes)\b", re.IGNORECASE)
_ORDER_QUESTION = "passer à la commande"
_ADD_TO_CART = re.compile(r"ajout|panier", re.IGNORECASE)
_ID_TAG = re.compile(r"\[id:([0-9a-fA-F-]{36})\]")
_SELECTION_N = re.compile(r"s[ée]lection\s*(\d)", re.IGNORECASE)
_REMOVE_VERB = re.compile(r"supprim|retir|enl[eè]v|\bvire", re.IGNORECASE)
_REMOVE_NUMS = re.compile(
    r"(?:supprim\w*|retir\w*|enl[eè]v\w*|vire\w*|remplac\w*)\s+(?:le\s+|la\s+)?(?:s[ée]lection\s*)?(\d)",
    re.IGNORECASE,
)
_ADD_NUMS = re.compile(
    r"(?:sur|choisis|prends|veux|par|ajoute\w*)\s+(?:le\s+|la\s+)?s[ée]lection\s*(\d)", re.IGNORECASE
)
_CHOOSE = re.compile(r"choisi|je prends|je retiens|je pars sur|cette sélection|je veux la sélection", re.IGNORECASE)
_ACCESSORIES = re.compile(r"accessoire|carafe|tire-bouchon|\bverres?\b", re.IGNORECASE)
_CHEAPEST = re.compile(r"moins cher|plus abordable|moins co[uû]teux|plus [ée]conomique|meilleur prix", re.IGNORECASE)
_COMPARISON_TOOLS = ("verify_and_compare_selections_tool", "propose_selections_tool")
_BUDGET = re.compile(r"(\d[\d\s.]{2,8})\s*(?:€|eur|euros)", re.IGNORECASE)


def _parse_budget(text: str) -> int | None:
    match = _BUDGET.search(text)
    if not match:
        return None
    digits = re.sub(r"\D", "", match.group(1))
    return int(digits) * 100 if digits else None


def _euros(cents: int) -> str:
    return f"{cents / 100:,.0f}".replace(",", " ") + " €"


def _tool_results(messages: Sequence[BaseMessage]) -> list[tuple[str, dict[str, Any]]]:
    results: list[tuple[str, dict[str, Any]]] = []
    for m in messages:
        if isinstance(m, ToolMessage) and isinstance(m.content, str):
            try:
                payload = json.loads(m.content)
            except json.JSONDecodeError:
                continue
            if isinstance(payload, dict):
                results.append((m.name or "", payload))
    return results


def _call(name: str, args: dict[str, Any]) -> dict[str, Any]:
    return {"name": name, "args": args, "id": f"call_{uuid.uuid4().hex[:12]}", "type": "tool_call"}


def _cart_product_ids(history: Sequence[tuple[str, dict[str, Any]]]) -> list[str]:
    """Produits du panier d'après le dernier résultat d'outil panier."""
    for name, payload in reversed(history):
        if name in ("update_cart_tool", "remove_from_cart_tool") and "items" in payload:
            return [str(i["product_id"]) for i in payload["items"]]
    return []


def _selection_item_ids(selection: dict[str, Any]) -> list[str]:
    """Bouteilles d'une sélection (hors accessoires)."""
    return [i["id"] for i in selection.get("items", []) if i.get("role") == "wine"]


def _selection_ids(history: Sequence[tuple[str, dict[str, Any]]], numbers: list[str]) -> list[str]:
    comparisons = [p for n, p in history if n in _COMPARISON_TOOLS]
    if not comparisons:
        return []
    selections = comparisons[-1].get("selections", [])
    ids: list[str] = []
    for number in numbers:
        index = int(number) - 1
        if 0 <= index < len(selections):
            ids += _selection_item_ids(selections[index])
    return ids


def _plan_cart_removal(human_text: str, history: Sequence[tuple[str, dict[str, Any]]]) -> AIMessage:
    """« supprime X », « remplace la sélection 1 par la sélection 2 », « finalement la sélection 2, supprime le 1 »."""
    in_cart = _cart_product_ids(history)
    explicit = _ID_TAG.findall(human_text)
    removals = explicit or _selection_ids(history, _REMOVE_NUMS.findall(human_text))
    removals = [i for i in dict.fromkeys(removals) if i in in_cart]
    additions = [] if explicit else _selection_ids(history, _ADD_NUMS.findall(human_text))
    additions = [i for i in dict.fromkeys(additions) if i not in in_cart or i in removals]
    if not removals and not additions:
        return AIMessage(
            "Je ne trouve pas cet article dans votre panier. Précisez le produit ou la sélection à retirer."
        )
    calls = [_call("remove_from_cart_tool", {"product_id": i}) for i in removals[:6]]
    calls += [_call("update_cart_tool", {"product_id": i, "quantity": 1}) for i in additions[:6]]
    return AIMessage("", tool_calls=calls)


def _cart_wine_ids(history: Sequence[tuple[str, dict[str, Any]]], latest: dict[str, Any] | None) -> list[str]:
    """Bouteilles du panier (les articles du comparatif de rôle « vin »), pour proposer des accessoires adaptés."""
    wine_ids = {
        i["id"] for s in (latest or {}).get("selections", []) for i in s.get("items", []) if i.get("role") == "wine"
    }
    return [i for i in _cart_product_ids(history) if i in wine_ids]


def _selection_name(selection: dict[str, Any] | None) -> str:
    return " + ".join(i["name"] for i in (selection or {}).get("items", []) if i.get("role") == "wine")


def _superlative_answer(text: str, comparison: dict[str, Any]) -> str | None:
    """Répond « le moins cher » à partir des sélections déjà affichées."""
    selections: list[dict[str, Any]] = comparison.get("selections", [])
    if selections and _CHEAPEST.search(text):
        best = min(selections, key=lambda c: c["total_cents"])
        return (
            f"Le plus accessible est le {best['label']} ({_selection_name(best)}), à {_euros(best['total_cents'])} : "
            "il laisse de la marge dans votre budget pour les accessoires. Souhaitez-vous le choisir ?"
        )
    return None


def _turn_call_args(turn_messages: Sequence[BaseMessage], name: str) -> dict[str, Any]:
    for m in turn_messages:
        if isinstance(m, AIMessage):
            for call in m.tool_calls:
                if call["name"] == name:
                    return dict(call["args"])
    return {}


_REGIONS = {"bordeaux": "Bordeaux", "bourgogne": "Bourgogne", "rhône": "Rhône", "rhone": "Rhône"}
_TYPES = {"rouge": "rouge", "blanc": "blanc", "liquoreux": "liquoreux", "sauternes": "liquoreux"}
_DISH = re.compile(
    r"(?:avec|accompagn\w+|autour d['’e ]|pour)\s+(?:un|une|des|du|de la|le|la|les|l['’])?\s*"
    r"([^,.;?!]+?)(?=\s+(?:et|budget|dans|pour|avec)\b|[,.;?!]|$)",
    re.IGNORECASE,
)
_BOTTLES = re.compile(r"(\d)\s*bouteilles?", re.IGNORECASE)
_SUBJECT = re.compile(
    r"repas|d[iî]ner|d[ée]jeuner|cadeau|plat|accord|mariage|anniversaire|ap[ée]ro|agneau|b[œo]uf|poisson|canard|"
    r"gibier|fromage|dessert|foie gras|hu[iî]tre|homard|rouge|blanc|liquoreux|bordeaux|bourgogne|rh[ôo]ne|pauillac|"
    r"margaux|pomerol|chablis|cave|garde|vin ",
    re.IGNORECASE,
)
_SOLO = re.compile(r"juste (?:le |un |une |la )?(?:vin|bouteille)|une seule bouteille", re.IGNORECASE)
_DISCOVERY = (
    "Avec plaisir ! Pour bien vous conseiller, pour quelle occasion ou quel plat cherchez-vous un vin, "
    "et quel budget avez-vous en tête ?"
)


def _dish(text: str) -> str | None:
    for match in _DISH.finditer(text):
        candidate = match.group(1).strip()
        if len(candidate) >= 3 and not re.search(r"\d|budget|bouteille|€", candidate):
            return candidate[:120]
    return None


def _item_line(item: dict[str, Any]) -> str:
    bits = [str(item.get("appellation") or ""), str(item.get("vintage") or "")]
    window = item.get("drinking_window")
    line = " ".join(b for b in bits if b)
    return f"{line}, à boire {window}" if window and line else line or str(window or "")


def _wine_tail(human_text: str, by_name: dict[str, dict[str, Any]], latest: dict[str, Any] | None) -> AIMessage:
    """Découverte, proposition de sélections de bouteilles, puis recommandation commentée."""
    budget = _parse_budget(human_text)
    if "propose_selections_tool" not in by_name and "search_catalog_tool" not in by_name:
        if latest is None and not budget and not _SUBJECT.search(human_text):
            return AIMessage(_DISCOVERY)
        lowered = human_text.lower()
        args: dict[str, Any] = {"query": human_text[:300]}
        if budget:
            args["budget_cents"] = budget
        filters: list[dict[str, Any]] = []
        for word, region in _REGIONS.items():
            if word in lowered:
                filters.append({"key": "region", "op": "eq", "value": region})
                break
        for word, kind in _TYPES.items():
            if re.search(rf"\b{word}\b", lowered):
                filters.append({"key": "type", "op": "eq", "value": kind})
                break
        if filters:
            args["filters"] = filters
        if _SOLO.search(human_text):
            return AIMessage("", tool_calls=[_call("search_catalog_tool", {**args, "category": "wine", "limit": 3})])
        if dish := _dish(human_text):
            args["dish"] = dish
        if bottles := _BOTTLES.search(human_text):
            args["bottles"] = int(bottles.group(1))
        return AIMessage("", tool_calls=[_call("propose_selections_tool", args)])

    if "search_catalog_tool" in by_name:
        shown = by_name["search_catalog_tool"].get("results", [])[:3]
        if not shown:
            return AIMessage("Je n'ai rien trouvé de tel. Pouvez-vous préciser la région, le type ou le budget ?")
        first = shown[0]
        return AIMessage(
            f"Voici {len(shown)} {'bouteille qui correspond' if len(shown) == 1 else 'bouteilles qui correspondent'}. Je commencerais par le {first['name']}, à "
            f"{_euros(first['price_cents'])} ({_item_line(first)}). Souhaitez-vous le détail, ou une comparaison ?"
        )

    comparison = by_name["propose_selections_tool"]
    selections = comparison.get("selections") or []
    if not selections:
        return AIMessage(
            "Je n'ai trouvé aucune sélection correspondante dans ce budget. Pouvez-vous l'élargir ou changer de région ?"
        )
    index = comparison.get("recommended_index")
    if index is None:
        return AIMessage(
            "Aucune de ces sélections n'est à la fois cohérente et dans votre budget. Souhaitez-vous l'élargir un peu ?"
        )
    best = selections[index]
    wines = [i for i in best.get("items", []) if i.get("role") == "wine"]
    pairing = (best.get("details") or {}).get("pairing") or {}
    warnings = [i for i in best.get("issues", []) if i.get("severity") == "warning"]
    accord = f" Il s'accorde avec {pairing['dish']}." if pairing.get("dish") and not pairing.get("mismatches") else ""
    note = f" Petit point d'attention : {warnings[0]['message']}" if warnings else ""
    detail = " ; ".join(f"{w['name']} ({_item_line(w)})" for w in wines)
    others = (
        "Laquelle de ces sélections vous tente ?" if len(selections) > 1 else "Cette sélection vous convient-elle ?"
    )
    return AIMessage(
        f"Je vous recommande {best['label']} : {detail}, pour {_euros(best['total_cents'])}.{accord}{note} {others}"
    )


def _checkout_reply(messages: Sequence[BaseMessage], human_text: str) -> AIMessage | None:
    """Tunnel de commande : la consigne de Symfony (dans le prompt système) dicte la réponse ; le client n'envoie que des
    jetons d'anonymisation, jamais ses coordonnées."""
    system = next((str(m.content) for m in messages if isinstance(m, SystemMessage)), "")
    if _HINT_MARKER not in system or not _TOKEN.search(human_text):
        return None
    return AIMessage(fallback_reply(system.split(_HINT_MARKER, 1)[1]))


def _edit_field(text: str) -> str | None:
    lowered = text.lower()
    if re.search(r"mail|courriel", lowered):
        return "email"
    if re.search(r"t[ée]l[ée]phone|\btel\b|num[ée]ro|portable", lowered):
        return "phone"
    if re.search(r"\bnom\b|pr[ée]nom", lowered):
        return "name"
    if re.search(r"adresse|\brue\b|livraison", lowered):
        return "address"
    return None


def _previous_assistant_text(messages: Sequence[BaseMessage], before: int) -> str:
    for m in reversed(messages[:before]):
        if isinstance(m, AIMessage) and isinstance(m.content, str) and m.content.strip():
            return m.content
    return ""


def decide(messages: Sequence[BaseMessage]) -> AIMessage:
    last_human = max((i for i, m in enumerate(messages) if isinstance(m, HumanMessage)), default=-1)
    human_text = str(messages[last_human].content) if last_human >= 0 else ""
    turn_messages = messages[last_human + 1 :]
    turn = _tool_results(turn_messages)
    history = _tool_results(messages)
    called = {name for name, _ in turn}
    comparisons = [p for n, p in history if n in _COMPARISON_TOOLS]
    latest = comparisons[-1] if comparisons else None

    checkout_reply = _checkout_reply(messages, human_text)
    if checkout_reply is not None and not turn:
        return checkout_reply

    if "edit_checkout_tool" in called:
        edited = dict(turn)["edit_checkout_tool"]
        if "error" in edited:
            return AIMessage(
                "Je n'ai pas encore d'informations de livraison à corriger : souhaitez-vous passer à la commande ?"
            )
        return AIMessage(fallback_reply(str(edited.get("instruction", ""))))

    if _EDIT_VERB.search(human_text) and not turn and not _TOKEN.search(human_text):
        field = _edit_field(human_text)
        if field is not None:
            return AIMessage("", tool_calls=[_call("edit_checkout_tool", {"field": field})])
        if "tromp" in human_text.lower():
            return AIMessage(
                "Pas de souci : quelle information souhaitez-vous corriger : le nom, l'adresse, le téléphone ou l'email ?"
            )

    if "start_checkout_tool" in called:
        started = dict(turn)["start_checkout_tool"]
        if "error" in started:
            return AIMessage("Votre panier est vide : choisissez d'abord une sélection, puis nous passerons commande.")
        question = _QUOTED.search(str(started.get("instruction", "")))
        return AIMessage(question.group(1) if question else "À quel nom et prénom devons-nous adresser la commande ?")

    if not turn and (
        _CHECKOUT.search(human_text)
        or (_YES.search(human_text) and _ORDER_QUESTION in _previous_assistant_text(messages, last_human))
    ):
        return AIMessage("", tool_calls=[_call("start_checkout_tool", {})])

    cart_results = [(n, p) for n, p in turn if n in ("update_cart_tool", "remove_from_cart_tool")]
    if cart_results:
        last = cart_results[-1][1]
        if "total_cents" not in last:
            return AIMessage("Je n'ai pas pu modifier le panier : vérifiez les produits et réessayez.")
        total = _euros(int(last["total_cents"]))
        if any(n == "remove_from_cart_tool" for n, _ in cart_results):
            return AIMessage(f"Votre panier est à jour (total {total}). Souhaitez-vous passer au paiement ?")
        if _CHOOSE.search(human_text) and not _ADD_TO_CART.search(human_text):
            # Choix d'une sélection : elle est déjà au panier ; les accessoires ne sont qu'une proposition.
            return AIMessage(
                f"Excellent choix, votre sélection est dans le panier (total {total}). Si cela vous dit, je peux "
                "vous montrer quelques accessoires de service (carafe, verres, tire-bouchon), sans aucune "
                "obligation : dites-moi simplement si cela vous intéresse, ou nous passons directement au paiement."
            )
        return AIMessage(f"C'est ajouté au panier (total {total}). Souhaitez-vous passer au paiement ?")

    if _REMOVE_VERB.search(human_text) and not turn:
        return _plan_cart_removal(human_text, history)

    if _ADD_TO_CART.search(human_text) and not turn:
        # Ids explicites ([id:…], posés par l'interface), sinon "sélection N", sinon sélection recommandée.
        ids = _ID_TAG.findall(human_text)
        if not ids:
            if not latest or not latest.get("selections"):
                return AIMessage("Je n'ai pas encore de sélection à ajouter : décrivez votre besoin et votre budget.")
            selections = latest["selections"]
            picked = _SELECTION_N.search(human_text)
            index = int(picked.group(1)) - 1 if picked else latest.get("recommended_index")
            if index is None or not 0 <= index < len(selections):
                return AIMessage("Quelle sélection souhaitez-vous ajouter au panier ?")
            ids = _selection_item_ids(selections[index])
        return AIMessage("", tool_calls=[_call("update_cart_tool", {"product_id": i, "quantity": 1}) for i in ids[:6]])

    # Accessoires : uniquement sur demande du client (jamais imposés après un choix).
    if "recommend_compatible_accessories_tool" in called:
        acc = dict(turn)["recommend_compatible_accessories_tool"]
        if any(g.get("items") for g in acc.get("groups", [])):
            return AIMessage(
                "Voici de quoi servir et conserver ce vin dans les règles de l'art. Ajoutez ceux qui vous plaisent "
                "d'un clic, ou dites-moi quand vous souhaitez passer au paiement."
            )
        return AIMessage("Je n'ai pas d'accessoire adapté à ce vin pour le moment. Souhaitez-vous passer au paiement ?")
    if _DECLINE_ACCESSORIES.search(human_text) and not turn:
        return AIMessage("Très bien, sans accessoires. Souhaitez-vous passer à la commande ?")
    if _ACCESSORIES.search(human_text) and not turn:
        ids = _ID_TAG.findall(human_text) or _cart_wine_ids(history, latest)
        if ids:
            return AIMessage("", tool_calls=[_call("recommend_compatible_accessories_tool", {"item_ids": ids[:25]})])

    # Étape 3 : le client choisit une sélection -> ses bouteilles vont directement au panier.
    if _CHOOSE.search(human_text) and not turn:
        ids = _ID_TAG.findall(human_text)
        if not ids and latest and latest.get("selections"):
            picked = _SELECTION_N.search(human_text)
            index = int(picked.group(1)) - 1 if picked else latest.get("recommended_index")
            if index is not None and 0 <= index < len(latest["selections"]):
                ids = _selection_item_ids(latest["selections"][index])
        if ids:
            return AIMessage(
                "", tool_calls=[_call("update_cart_tool", {"product_id": i, "quantity": 1}) for i in ids[:6]]
            )
        return AIMessage(
            "Je n'ai pas encore de sélection à valider. Quel est votre projet, et quel budget avez-vous en tête ?"
        )

    # Question de tri sur les propositions déjà affichées : on répond avec leurs données, sans nouvelle recherche.
    if latest and not turn:
        answer = _superlative_answer(human_text, latest)
        if answer:
            return AIMessage(answer)

    return _wine_tail(human_text, dict(turn), latest)


class MockChatModel(BaseChatModel):
    @property
    def _llm_type(self) -> str:
        return "aura-mock"

    def bind_tools(self, tools: Sequence[Any], **kwargs: Any) -> Runnable[Any, AIMessage]:
        return self

    def _generate(
        self,
        messages: list[BaseMessage],
        stop: list[str] | None = None,
        run_manager: CallbackManagerForLLMRun | None = None,
        **kwargs: Any,
    ) -> ChatResult:
        return ChatResult(generations=[ChatGeneration(message=decide(messages))])

    def _stream(
        self,
        messages: list[BaseMessage],
        stop: list[str] | None = None,
        run_manager: CallbackManagerForLLMRun | None = None,
        **kwargs: Any,
    ) -> Iterator[ChatGenerationChunk]:
        message = decide(messages)
        if message.tool_calls:
            chunks = [
                {"name": c["name"], "args": json.dumps(c["args"]), "id": c["id"], "index": i}
                for i, c in enumerate(message.tool_calls)
            ]
            yield ChatGenerationChunk(message=AIMessageChunk(content="", tool_call_chunks=chunks))
            return
        for word in re.findall(r"\S+\s*", str(message.content)):
            yield ChatGenerationChunk(message=AIMessageChunk(content=word))

    async def _astream(
        self,
        messages: list[BaseMessage],
        stop: list[str] | None = None,
        run_manager: AsyncCallbackManagerForLLMRun | None = None,
        **kwargs: Any,
    ) -> AsyncIterator[ChatGenerationChunk]:
        for chunk in self._stream(messages, stop):
            yield chunk
