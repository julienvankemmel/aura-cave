# syntax=docker/dockerfile:1.7

# --- Stage 1 : dépendances ---
FROM node:22-alpine AS deps
RUN corepack enable
WORKDIR /app
COPY package.json pnpm-lock.yaml ./
RUN --mount=type=cache,target=/root/.local/share/pnpm/store pnpm install --frozen-lockfile

# --- Stage 2 : build Next.js (standalone) ---
FROM node:22-alpine AS build
RUN corepack enable
WORKDIR /app
COPY --from=deps /app/node_modules ./node_modules
COPY . .
ENV NEXT_TELEMETRY_DISABLED=1
RUN pnpm build

# --- Stage 3 : runtime minimal non-root ---
FROM node:22-alpine AS runtime
# Le runtime n'exécute que `node server.js` : npm/npx/corepack (et leurs dépendances) sont retirés.
RUN apk upgrade --no-cache \
    && rm -rf /usr/local/lib/node_modules /usr/local/bin/npm /usr/local/bin/npx /usr/local/bin/corepack /opt/yarn* \
    && addgroup -g 10001 -S appuser && adduser -u 10001 -S -G appuser -H -s /sbin/nologin appuser
WORKDIR /app
ENV NODE_ENV=production NEXT_TELEMETRY_DISABLED=1 PORT=3000 HOSTNAME=0.0.0.0
COPY --from=build --chown=root:root /app/.next/standalone ./
COPY --from=build --chown=root:root /app/.next/static ./.next/static
COPY --from=build --chown=root:root /app/public ./public
USER appuser
EXPOSE 3000
HEALTHCHECK --interval=15s --timeout=3s --retries=3 CMD wget -qO /dev/null http://127.0.0.1:3000/ || exit 1
CMD ["node", "server.js"]
