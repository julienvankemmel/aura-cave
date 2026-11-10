# AuraCave AI

Boutique de vin **conversationnelle** (« zero-navigation ») : on décrit un plat et un budget, un agent sommelier cherche dans le catalogue, vérifie la cohérence de façon déterministe, propose des sélections sous forme de cartes (jauges de dégustation) et conduit la commande jusqu'au paiement **sans jamais voir de donnée personnelle en clair**.

> Exemple : « Je cherche 2 bouteilles de rouge pour accompagner une côte de bœuf, budget 600 € ».

Projet de démonstration (POC) : le paiement est en mode test et le catalogue provient d'un jeu de données public.

## Architecture

```
Navigateur ──► Next.js 15 (frontend) ──SSE──► Agent FastAPI + LangGraph ──MCP/HTTP──► Symfony 8 (backend) ──► PostgreSQL 16 + pgvector
   │  coordonnées de livraison : saisies dans le chat, envoyées à Symfony SANS passer par l'agent   ▲
   └──────────────── /api/checkout/message (Next → Symfony) ───────────────────────────────────────┘ renvoie un jeton
```

| Dossier | Rôle |
| --- | --- |
| `backend/` | Symfony 8 / PHP 8.5 : catalogue, panier, checkout, recherche vectorielle (pgvector, 384 d), règles métier vin (appellations, cépages, accords mets-vins) |
| `agent/` | Python 3.12 (uv) : graphe LangGraph, outils FastMCP, garde-fous, fabrique LLM multi-fournisseur avec repli |
| `frontend/` | Next.js 15 (App Router, TypeScript strict), Tailwind, shadcn/ui, carrousel de sélections, dictée vocale (Web Speech API) |
| `infra/` | Dockerfiles multi-étapes non-root, nginx, scan Trivy |

### Choix notables

- **Pseudonymisation des coordonnées** : le tunnel de commande est une machine à états Symfony (nom → adresse → téléphone → email → confirmation), pilotée dans le chat. Chaque saisie va directement du navigateur à Symfony, qui la valide et la chiffre au repos ; l'agent et le LLM ne voient que des jetons (`[CUSTOMER_NAME]`, `[SHIPPING_ADDRESS]`…) et une consigne sur l'étape suivante. Les vraies coordonnées n'arrivent que dans l'entité `Order`, à la confirmation. Des tests vérifient que ni les messages vus par le LLM, ni le State du graphe, ni les appels vers Symfony ne contiennent de coordonnées en clair.
- **Filtre déterministe + reranking LLM** : Symfony applique en SQL les invariants (stock > 0, budget, vin existant) et retient un pool de 15 vins réels par similarité pgvector. Un sommelier LLM (Google AI Studio, cascade de modèles de secours en cas de 429/503/timeout) classe ce pool et justifie le Top 3 ; il ne peut choisir que des SKU fournis, et les produits renvoyés sont relus en base. Sans clé ou si tous les modèles échouent, un tri local prend le relais. Les incohérences d'appellation/cépage ne sont que des avertissements ; millésime, budget et stock restent bloquants.
- **100 % gratuit** : Groq (principal), OpenRouter (repli) ou Ollama en local ; embeddings locaux (fastembed) avec repli par hachage déterministe pour fonctionner hors ligne.

## Démarrage

Prérequis : Docker, `make`. Pour le développement hors Docker : PHP 8.5 + Composer, `uv`, Node 22 + pnpm.

```bash
cp .env.example .env     # puis renseigner les secrets (GEMINI_API_KEY pour le reranking du sommelier, facultatif)
make keys                # génère SODIUM_PRIVATE_KEY (chiffrement au repos des coordonnées) à copier dans .env
make up                  # build + démarrage
```

Le catalogue vient du jeu de données Kaggle [`dev7halo/wine-information`](https://www.kaggle.com/datasets/dev7halo/wine-information) (non versionné : « Data files © Original Authors », aucun droit de redistribution ; les fixtures de test sont des vins fictifs) :

```bash
# placer wine_info.csv dans backend/data/raw/ puis :
make seed
```

Application : <http://localhost:3100> (API : `:8088`, agent : `:8001`).

## Qualité

| Commande | Contenu |
| --- | --- |
| `make lint` | PHPStan niveau 8, php-cs-fixer, Ruff, Mypy `--strict`, `tsc`, ESLint |
| `make test` | PHPUnit, Pytest (+ asyncio), Vitest |
| `make security` | Trivy (fs + config) et audits composer / uv / pnpm |

## Limites connues

- La dictée vocale passe par le service de reconnaissance du navigateur (Chrome/Edge) : l'audio, donc les coordonnées dictées, sort de notre périmètre. Le texte tapé transite en clair navigateur → Next → Symfony (réseau interne) : prévoir TLS en production.
- La variété des sélections proposées peut encore être améliorée (même vin dans plusieurs sélections).
- Prix du dataset (KRW) convertis à taux fixe (`WINE_KRW_PER_EUR`) ; stocks simulés.
