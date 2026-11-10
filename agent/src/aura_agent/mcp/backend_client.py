import contextlib
import uuid
from typing import Any, Literal

import httpx
from pydantic import BaseModel, ConfigDict, Field, SecretStr, field_validator

FilterOp = Literal["eq", "in", "gte", "lte", "contains", "any"]


class BackendError(Exception):
    """Erreur renvoyée par Symfony ; ne porte que le statut et le code (jamais le corps)."""

    def __init__(self, status: int, code: str, extra: dict[str, int] | None = None) -> None:
        super().__init__(f"backend error {status}: {code}")
        self.status = status
        self.code = code
        # Compteurs sûrs (ex. stock disponible) utiles à la réponse ; jamais de texte libre.
        self.extra = extra or {}


class _Request(BaseModel):
    model_config = ConfigDict(extra="forbid")


class AttributeFilter(_Request):
    """Filtre générique sur un attribut produit (chemin pointé), appliqué strictement par Symfony."""

    key: str = Field(pattern=r"^[a-z0-9_]+(\.[a-z0-9_]+)*$", max_length=64, description="Attribut, ex. region, vintage")
    op: FilterOp = Field(default="eq", description="eq | in | gte | lte | contains | any")
    value: str | int | float | bool | list[str | int | float] | None = None
    include_missing: bool = Field(default=False, description="Garder les produits sans cet attribut")


class SearchRequest(_Request):
    query: str = Field(min_length=1, max_length=300)
    budget_cents: int | None = Field(default=None, gt=0)
    category: str | None = Field(default=None, max_length=32)
    limit: int = Field(default=5, ge=1, le=20)
    # -1 : la similarité sémantique ne fait que classer (jamais d'exclusion), utile pour assembler des sélections.
    min_similarity: float | None = Field(default=None, ge=-1, le=1)
    filters: list[AttributeFilter] = Field(default_factory=list, max_length=12)


class RecommendRequest(_Request):
    """Reranking du sommelier : Symfony filtre en SQL (stock, budget), un LLM classe un petit pool de vins réels."""

    query: str = Field(min_length=1, max_length=300)
    dish: str | None = Field(default=None, max_length=200)
    budget_cents: int | None = Field(default=None, gt=0)
    limit: int = Field(default=3, ge=1, le=8)
    filters: list[AttributeFilter] = Field(default_factory=list, max_length=12)


class VerifySelectionRequest(_Request):
    """Sélection de bouteilles (et accessoires) ; contexte facultatif : plat visé, millésime de service."""

    item_ids: list[uuid.UUID] = Field(min_length=1, max_length=25)
    budget_cents: int | None = Field(default=None, gt=0)
    dish: str | None = Field(default=None, max_length=200)
    serving_year: int | None = Field(default=None, ge=1900, le=2200)
    strict: bool = True


class AddItemRequest(_Request):
    product_id: uuid.UUID
    quantity: int = Field(default=1, ge=1, le=10)


class ProductOut(BaseModel):
    """Produit : socle commun + `attributes` propres au vin."""

    model_config = ConfigDict(extra="ignore")

    id: str
    sku: str
    name: str
    brand: str
    category: str
    price_cents: int
    stock: int = 0
    description: str = ""
    tags: list[str] = Field(default_factory=list)
    attributes: dict[str, Any] = Field(default_factory=dict)
    score: float | None = None
    # Avis du sommelier (reranking) : présents seulement sur les produits issus de `recommend`.
    match_score: int | None = None
    sommelier_justification: str | None = Field(default=None, max_length=600)
    is_coup_de_coeur: bool | None = None

    @field_validator("attributes", mode="before")
    @classmethod
    def _empty_php_array(cls, value: Any) -> Any:
        """Tolère `[]` (tableau PHP vide) pour `{}`."""
        return {} if value == [] else value

    def attr(self, path: str) -> Any:
        """Valeur d'un attribut, chemin pointé pour les blocs imbriqués (ex. `drinking_window.from`)."""
        node: Any = self.attributes
        for segment in path.split("."):
            if not isinstance(node, dict) or segment not in node:
                return None
            node = node[segment]
        return node

    def slim(self) -> dict[str, Any]:
        """Fiche transmise à l'interface (description tronquée) ; le LLM reçoit une vue encore plus compacte."""
        data = self.model_dump(mode="json", exclude_none=True)
        data["description"] = self.description[:240]
        return data


class SearchResponse(BaseModel):
    model_config = ConfigDict(extra="ignore")

    strategy: str
    results: list[ProductOut]


class RecommendResponse(BaseModel):
    model_config = ConfigDict(extra="ignore")

    status: Literal["ok", "no_match"]
    source: Literal["llm", "fallback"] | None = None
    model: str | None = None
    reason: str | None = None
    results: list[ProductOut] = Field(default_factory=list)


class Issue(BaseModel):
    model_config = ConfigDict(extra="ignore")

    severity: Literal["error", "warning", "info"]
    code: str
    message: str


class CompatibilityReport(BaseModel):
    model_config = ConfigDict(extra="ignore")

    compatible: bool
    within_budget: bool
    total_cents: int
    budget_cents: int | None = None
    # Données propres au vin (pairing, serving_year…), conservées telles que renvoyées par Symfony.
    pairing: dict[str, Any] | None = None
    serving_year: int | None = None
    issues: list[Issue] = Field(default_factory=list)


class CheckoutTurn(BaseModel):
    """Démarrage du checkout : étape et consigne pour le LLM (jamais de donnée personnelle)."""

    model_config = ConfigDict(extra="ignore")

    step: str
    instruction: str


class CheckoutHint(BaseModel):
    """Consigne de l'étape courante du checkout conversationnel, fournie par Symfony."""

    model_config = ConfigDict(extra="ignore")

    active: bool
    step: str | None = None
    instruction: str | None = Field(default=None, max_length=1200)


class CartSummary(BaseModel):
    model_config = ConfigDict(extra="ignore")

    cart_id: str
    total_cents: int
    items: list[dict[str, Any]]
    compatibility: CompatibilityReport


class ProductCache:
    """Produits vus lors des recherches : Symfony n'expose pas de lecture unitaire."""

    def __init__(self, max_size: int = 500) -> None:
        self._items: dict[str, ProductOut] = {}
        self._max = max_size

    def put(self, products: list[ProductOut]) -> None:
        for p in products:
            if len(self._items) >= self._max and p.id not in self._items:
                self._items.pop(next(iter(self._items)))
            self._items[p.id] = p

    def get(self, product_id: str) -> ProductOut | None:
        return self._items.get(str(product_id))


class BackendClient:
    """Client HTTPX de /backend : injecte systématiquement X-Internal-Api-Key."""

    def __init__(
        self,
        base_url: str,
        api_key: SecretStr,
        client: httpx.AsyncClient | None = None,
    ) -> None:
        self._client = client or httpx.AsyncClient(base_url=base_url, timeout=httpx.Timeout(10.0, connect=3.0))
        self._api_key = api_key
        self.products = ProductCache()
        self.cart_totals: dict[str, int] = {}

    async def aclose(self) -> None:
        await self._client.aclose()

    async def _request(
        self, method: str, path: str, payload: dict[str, Any] | None = None, read_timeout_s: float | None = None
    ) -> Any:
        response = await self._client.request(
            method,
            path,
            json=payload,
            timeout=httpx.USE_CLIENT_DEFAULT if read_timeout_s is None else read_timeout_s,
            headers={"X-Internal-Api-Key": self._api_key.get_secret_value()},
        )
        if response.is_success:
            return response.json()
        code = "http_error"
        extra: dict[str, int] = {}
        with contextlib.suppress(ValueError, KeyError, TypeError):
            error = response.json()["error"]
            code = str(error["code"])
            extra = {k: int(error[k]) for k in ("available", "in_cart") if isinstance(error.get(k), int)}
        raise BackendError(response.status_code, code, extra)

    async def _post(self, path: str, payload: dict[str, Any]) -> Any:
        return await self._request("POST", path, payload)

    async def search(self, request: SearchRequest) -> SearchResponse:
        data = await self._post("/api/products/search", request.model_dump(mode="json", exclude_none=True))
        parsed = SearchResponse.model_validate(data)
        self.products.put(parsed.results)
        return parsed

    async def recommend(self, request: RecommendRequest) -> RecommendResponse:
        # Le reranking peut parcourir toute la cascade de modèles côté Symfony (jusqu'à ~4 x 4,5 s) : délai élargi.
        data = await self._request(
            "POST", "/api/sommelier/recommend", request.model_dump(mode="json", exclude_none=True), read_timeout_s=30.0
        )
        parsed = RecommendResponse.model_validate(data)
        if parsed.source != "llm":
            # Tri local de repli : l'ordre est conservé, mais pas de faux « avis du sommelier » dans l'interface.
            parsed.results = [
                p.model_copy(update={"match_score": None, "sommelier_justification": None, "is_coup_de_coeur": None})
                for p in parsed.results
            ]
        self.products.put(parsed.results)
        return parsed

    async def verify_selection(self, request: VerifySelectionRequest) -> CompatibilityReport:
        data = await self._post(
            "/api/compatibility/verify-selection", request.model_dump(mode="json", exclude_none=True)
        )
        return CompatibilityReport.model_validate(data)

    async def add_item(self, cart_id: uuid.UUID, request: AddItemRequest) -> CartSummary:
        data = await self._post(f"/api/cart/{cart_id}/items", request.model_dump(mode="json"))
        summary = CartSummary.model_validate(data)
        self.cart_totals[str(cart_id)] = summary.total_cents
        return summary

    async def remove_item(self, cart_id: uuid.UUID, product_id: uuid.UUID) -> CartSummary:
        data = await self._request("DELETE", f"/api/cart/{cart_id}/items/{product_id}")
        summary = CartSummary.model_validate(data)
        self.cart_totals[str(cart_id)] = summary.total_cents
        return summary

    async def start_checkout(self, cart_id: uuid.UUID) -> CheckoutTurn:
        return CheckoutTurn.model_validate(await self._post(f"/api/checkout/{cart_id}/start", {}))

    async def edit_checkout(self, cart_id: uuid.UUID, field: str) -> CheckoutTurn:
        """Rouvre un champ (nom, adresse, téléphone, email) pour correction ; la nouvelle valeur passe par le canal sécurisé."""
        return CheckoutTurn.model_validate(await self._post(f"/api/checkout/{cart_id}/edit", {"field": field}))

    async def checkout_hint(self, cart_id: uuid.UUID) -> CheckoutHint:
        """Consigne de l'étape en cours (une commande confirmée n'est annoncée qu'une fois côté Symfony)."""
        return CheckoutHint.model_validate(await self._post(f"/api/checkout/{cart_id}/hint", {}))
