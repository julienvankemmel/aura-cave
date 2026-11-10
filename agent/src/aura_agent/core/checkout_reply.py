"""Contrôle de la réponse du LLM pendant le tunnel de commande.

Les questions de collecte sont dictées par Symfony (consigne « … quelque chose comme : « question » »). Un modèle gratuit
peut couper sa phrase, poser deux questions ou s'écarter du script : on le détecte et on y substitue la question prévue,
pour que le client ne reste jamais devant un message tronqué.
"""

import re

from aura_agent.core.guardrails import redact_pii

_QUOTED = re.compile(r"«\s*(.+?)\s*»", re.DOTALL)
_MIN_LENGTH = 12
CONFIRMED_FALLBACK = (
    "Merci [CUSTOMER_NAME], votre commande est confirmée. Elle sera livrée à [SHIPPING_ADDRESS] ; le livreur vous "
    "joindra au [CUSTOMER_PHONE] et un email de suivi partira à [CUSTOMER_EMAIL]."
)


UPDATED_FALLBACK = "C'est corrigé : votre commande est à jour avec votre nouvelle information."


def is_update(hint: str) -> bool:
    return "a bien été mise à jour" in hint


def is_confirmation(hint: str) -> bool:
    return "est confirmée" in hint or is_update(hint)


def fallback_reply(hint: str) -> str:
    """Réponse de secours construite à partir de la consigne de Symfony (jamais de donnée personnelle)."""
    if is_update(hint):
        return UPDATED_FALLBACK
    if is_confirmation(hint):
        return CONFIRMED_FALLBACK
    question = _QUOTED.search(hint)
    if question is None:
        return "Pouvez-vous me donner cette information, s'il vous plaît ?"
    prefix = "Je n'ai pas pu valider cette réponse. " if "[INVALID_INPUT]" in hint else "Merci ! "
    if "souhaite corriger" in hint or "renonce à corriger" in hint or "voulait corriger" in hint:
        prefix = "Bien sûr. "
    return prefix + question.group(1)


def vet_checkout_reply(text: str, hint: str) -> str:
    """Renvoie la réponse du LLM si elle est complète et sans donnée personnelle, sinon la réponse de secours."""
    reply = text.strip()
    if len(reply) < _MIN_LENGTH or redact_pii(reply)[1]:
        return fallback_reply(hint)
    if is_confirmation(hint):
        return reply if reply[-1] in ".!)?" else fallback_reply(hint)
    # Collecte : une seule question, et la réponse se termine par elle (pas de phrase coupée).
    return reply if reply.endswith("?") and reply.count("?") == 1 else fallback_reply(hint)
