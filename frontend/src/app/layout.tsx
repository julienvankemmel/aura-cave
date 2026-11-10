import type { Metadata, Viewport } from "next";
import type { ReactNode } from "react";

import "./globals.css";

export const metadata: Metadata = {
  title: "AuraCave AI — sommelier virtuel",
  description: "Trouvez le vin idéal pour votre repas : accords vérifiés, commande sans exposer vos données personnelles.",
};

export const viewport: Viewport = { themeColor: "#f5f4f1" };

export default function RootLayout({ children }: { children: ReactNode }) {
  return (
    <html lang="fr">
      <body>{children}</body>
    </html>
  );
}
