import re
import unicodedata
from dataclasses import dataclass
from enum import StrEnum

from aura_agent.core.wine import DOMAIN_KEYWORDS, OFF_TOPIC, REFUSAL_INJECTION, REFUSAL_OFF_TOPIC, SHORT_KEYWORDS

REDACTED = "[REDACTED_PII]"

_LETTERS = "A-Za-zÀ-ÿ'’\\-"
_STREET_TYPES = "rue|avenue|av\\.?|boulevard|bd|chemin|impasse|allée|allee|place|quai|route|cours|passage|square|résidence|residence"

_EMAIL = re.compile(r"[A-Za-z0-9._%+\-]+@[A-Za-z0-9.\-]+\.[A-Za-z]{2,}")
_PHONE_FR = re.compile(r"(?<!\d)(?:(?:\+|00)33[\s.\-]?|0)[1-9](?:[\s.\-]?\d{2}){4}(?!\d)")
_PHONE_INTL = re.compile(r"(?<![\w+])\+\d{1,3}[\s.\-]?\d{1,4}(?:[\s.\-]?\d{2,4}){2,4}(?!\d)")
_CARD = re.compile(r"(?<!\d)\d(?:[ \-]?\d){12,18}(?!\d)")
_ADDRESS = re.compile(
    rf"\b\d{{1,4}}\s*(?:bis|ter)?[,\s]+(?:{_STREET_TYPES})\s+(?:[{_LETTERS}]+\s*){{1,4}}"
    rf"(?:,?\s*\d{{5}}\s+[{_LETTERS}]+(?:\s+[{_LETTERS}]+)?)?",
    re.IGNORECASE,
)
_POSTAL_CITY = re.compile(rf"\b\d{{5}}\s+(?!(?i:euros?|eur)\b|€)[A-ZÀ-Ý][{_LETTERS}]+")

_INJECTION_PATTERNS = [
    r"ignore\w*\s+(?:(?:all|any|every|the|your|previous|prior|above|earlier)\s+)+(?:instructions?|rules?|prompts?)",
    r"disregard\s+(?:(?:all|any|the|your|previous|prior|above)\s+)+(?:instructions?|rules?|prompts?)",
    r"ignore\w*\s+(?:(?:toutes?|tes|vos|les|ces|mes)\s+)+(?:instructions?|r[eè]gles?|consignes?)",
    r"oublie\w*\s+(?:(?:toutes?|tes|vos|les|ces)\s+)*(?:instructions?|r[eè]gles?|consignes?)",
    r"(?:reveal|show|print|display|repeat|leak)\s+(?:me\s+)?(?:your|the)\s+(?:system\s+)?(?:prompt|instructions?)",
    r"(?:r[eé]v[eè]le|affiche|montre|donne|r[eé]p[eè]te)\w*\s+(?:moi\s+)?(?:ton|votre|le)\s+(?:system\s+|syst[eè]me\s+)?(?:prompt|instructions?)",
    r"system\s+prompt|prompt\s+syst[eè]me",
    r"you\s+are\s+now\b|from\s+now\s+on\s+you|tu\s+es\s+(?:maintenant|d[eé]sormais)",
    r"\bact\s+as\b|fais\s+comme\s+si|agis\s+comme|joue\s+le\s+r[oô]le",
    r"developer\s+mode|mode\s+d[eé]veloppeur|\bjailbreak|\bDAN\b|do\s+anything\s+now",
    r"<\|?\s*(?:im_start|im_end|system)\s*\|?>|\[/?INST\]|<<\s*SYS\s*>>|^\s*#{2,}\s*system\b",
    r"(?:override|bypass|d[eé]sactive\w*|contourne\w*)\s+(?:the\s+|your\s+|tes\s+|les\s+)?(?:guardrails?|safety|s[eé]curit[eé]|filtres?|restrictions?)",
]
_INJECTION = re.compile("|".join(f"(?:{p})" for p in _INJECTION_PATTERNS), re.IGNORECASE | re.MULTILINE)


class BlockReason(StrEnum):
    PROMPT_INJECTION = "prompt_injection"
    OFF_TOPIC = "off_topic"


REFUSALS = {
    BlockReason.PROMPT_INJECTION: REFUSAL_INJECTION,
    BlockReason.OFF_TOPIC: REFUSAL_OFF_TOPIC,
}


def refusal_for(reason: BlockReason) -> str:
    """Refus poli du sommelier."""
    return REFUSALS[reason]


@dataclass(frozen=True)
class GuardrailResult:
    sanitized_text: str
    pii_kinds: tuple[str, ...] = ()
    blocked: BlockReason | None = None

    @property
    def pii_detected(self) -> bool:
        return bool(self.pii_kinds)


def _luhn_valid(digits: str) -> bool:
    total = 0
    for i, ch in enumerate(reversed(digits)):
        n = int(ch)
        if i % 2 == 1:
            n *= 2
            if n > 9:
                n -= 9
        total += n
    return total % 10 == 0


# Marqueur d'identifiant produit posé par l'interface (« [id:<uuid>] ») : ses chiffres ne sont jamais de la PII.
_ID_TAG = re.compile(r"\[id:[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}\]")


def redact_pii(text: str) -> tuple[str, tuple[str, ...]]:
    """Masque email, téléphone, carte bancaire (Luhn), adresse postale. Retourne les types détectés.
    Les marqueurs d'identifiant produit (« [id:<uuid>] ») sont laissés intacts."""
    kinds: list[str] = []
    parts = _ID_TAG.split(text)
    if len(parts) > 1:
        tags = _ID_TAG.findall(text)
        redacted: list[str] = []
        for index, part in enumerate(parts):
            clean, part_kinds = redact_pii(part)
            redacted.append(clean)
            kinds.extend(k for k in part_kinds if k not in kinds)
            if index < len(tags):
                redacted.append(tags[index])
        return "".join(redacted), tuple(kinds)

    def mark(kind: str) -> str:
        if kind not in kinds:
            kinds.append(kind)
        return REDACTED

    def card_sub(match: re.Match[str]) -> str:
        digits = re.sub(r"\D", "", match.group(0))
        return mark("card") if 13 <= len(digits) <= 19 and _luhn_valid(digits) else match.group(0)

    # Ordre : les motifs les plus spécifiques d'abord pour éviter les chevauchements.
    out = _EMAIL.sub(lambda _m: mark("email"), text)
    out = _CARD.sub(card_sub, out)
    out = _ADDRESS.sub(lambda _m: mark("address"), out)
    out = _PHONE_FR.sub(lambda _m: mark("phone"), out)
    out = _PHONE_INTL.sub(lambda _m: mark("phone"), out)
    out = _POSTAL_CITY.sub(lambda _m: mark("address"), out)
    return out, tuple(kinds)


def _normalize(text: str) -> str:
    text = unicodedata.normalize("NFKC", text)
    return re.sub(r"[​-‏⁠﻿]", "", text)


def is_prompt_injection(text: str) -> bool:
    return _INJECTION.search(_normalize(text)) is not None


def _has_domain_keyword(lowered: str) -> bool:
    # Mots courts : correspondance sur mot entier ("mm" ne doit pas matcher "pommes").
    words = set(re.findall(r"\w+", lowered))
    long_keywords = tuple(k for k in DOMAIN_KEYWORDS if k not in SHORT_KEYWORDS)
    return bool(words & SHORT_KEYWORDS) or any(k in lowered for k in long_keywords)


def is_on_topic(text: str) -> bool:
    lowered = _normalize(text).lower()
    if _has_domain_keyword(lowered):
        return True
    if OFF_TOPIC.search(lowered):
        return False
    # Relances courtes ("oui", "et le moins cher ?", "le deuxième") sans mot-clé : le LLM refuse poliment le reste.
    return len(lowered.split()) <= 7


def check_user_message(text: str) -> GuardrailResult:
    """Injection/hors-sujet bloquent ; la PII est masquée avant tout LLM, log ou State."""
    if is_prompt_injection(text):
        return GuardrailResult(sanitized_text="", blocked=BlockReason.PROMPT_INJECTION)
    sanitized, kinds = redact_pii(_normalize(text))
    if not is_on_topic(sanitized):
        return GuardrailResult(sanitized_text="", pii_kinds=kinds, blocked=BlockReason.OFF_TOPIC)
    return GuardrailResult(sanitized_text=sanitized, pii_kinds=kinds)
