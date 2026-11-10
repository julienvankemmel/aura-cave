#!/usr/bin/env bash
# Audit de sécurité : Trivy (fs/secret/misconfig) + audits de dépendances.
# Usage : infra/security-scan.sh [--images]   (--images : build + scan des images Docker)
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT"

for tool in trivy composer uv pnpm; do
  command -v "$tool" >/dev/null 2>&1 || { echo "ERREUR : '$tool' est requis (voir README / CI)." >&2; exit 1; }
done

echo "==> Trivy : fs (vuln, secret, misconfig)"
trivy fs --config trivy.yaml --scanners vuln,secret,misconfig .

echo "==> composer audit (backend)"
(cd backend && composer audit --locked)

echo "==> uv pip check (agent)"
(cd agent && uv sync --frozen && uv pip check)

echo "==> pnpm audit (frontend)"
(cd frontend && pnpm audit --prod --audit-level high)

if [[ "${1:-}" == "--images" ]]; then
  for svc in backend agent frontend; do
    echo "==> Trivy : image $svc"
    docker build -q -t "aura-$svc:scan" -f "infra/docker/$svc.Dockerfile" "$svc"
    trivy image --config trivy.yaml --scanners vuln,secret "aura-$svc:scan"
  done
fi

echo "Audit de sécurité terminé sans vulnérabilité HIGH/CRITICAL corrigeable."
