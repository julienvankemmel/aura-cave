import type { NextConfig } from "next";

const isDev = process.env.NODE_ENV !== "production";

// Hôtes autorisés pour les images produits (`attributes.image_url` en HTTPS), ex. IMAGE_HOSTS=cdn.exemple.fr,img.exemple.fr.
// Vide par défaut : seules les images locales sont chargées.
const imageHosts = (process.env.IMAGE_HOSTS ?? "")
  .split(",")
  .map((h) => h.trim())
  .filter((h) => /^[a-z0-9.-]+(:\d+)?$/i.test(h))
  .map((h) => `https://${h}`);

// 'wasm-unsafe-eval' : libsodium s'exécute en WebAssembly. Next.js injecte des scripts inline d'hydratation.
const csp = [
  "default-src 'self'",
  `script-src 'self' 'unsafe-inline' 'wasm-unsafe-eval'${isDev ? " 'unsafe-eval'" : ""}`,
  "style-src 'self' 'unsafe-inline'",
  ["img-src 'self' data:", ...imageHosts].join(" "),
  "font-src 'self'",
  "connect-src 'self'",
  "object-src 'none'",
  "base-uri 'self'",
  "form-action 'self'",
  "frame-ancestors 'none'",
].join("; ");

const nextConfig: NextConfig = {
  output: "standalone",
  poweredByHeader: false,
  async headers() {
    return [
      {
        source: "/:path*",
        headers: [
          { key: "Content-Security-Policy", value: csp },
          { key: "X-Content-Type-Options", value: "nosniff" },
          { key: "X-Frame-Options", value: "DENY" },
          { key: "Referrer-Policy", value: "no-referrer" },
          { key: "Permissions-Policy", value: "camera=(), microphone=(self), geolocation=()" },
        ],
      },
    ];
  },
};

export default nextConfig;
