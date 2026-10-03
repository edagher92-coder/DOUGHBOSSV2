import type { Metadata, Viewport } from "next";
import localFont from "next/font/local";
import { CartHydrator } from "@/components/layout/CartHydrator";
import "./globals.css";

// Self-hosted (OFL) variable fonts: no third-party request, preloaded, with
// automatic size-adjusted fallbacks so swapping in the real face causes no layout shift.
const montserrat = localFont({
  src: "../fonts/montserrat-latin-wght-normal.woff2",
  variable: "--font-montserrat",
  weight: "100 900",
  display: "swap",
});

const playfair = localFont({
  src: [
    { path: "../fonts/playfair-display-latin-wght-normal.woff2", style: "normal" },
    { path: "../fonts/playfair-display-latin-wght-italic.woff2", style: "italic" },
  ],
  variable: "--font-playfair",
  weight: "400 900",
  display: "swap",
});

export const metadata: Metadata = {
  title: { default: "Dough Boss — Lebanese bakery, Sydney", template: "%s · Dough Boss" },
  description: "Crisp base, pillowy crust, oven-hot. Lebanese manoush, pies and more from Dough Boss in Revesby, Bankstown and Roselands.",
};

export const viewport: Viewport = {
  width: "device-width",
  initialScale: 1,
  themeColor: "#070707",
  colorScheme: "dark",
};

export default function RootLayout({ children }: { children: React.ReactNode }) {
  return (
    <html lang="en-AU" className={`${montserrat.variable} ${playfair.variable}`}>
      <body>
        <a
          href="#main"
          className="sr-only focus:not-sr-only focus:fixed focus:left-4 focus:top-4 focus:z-[100] focus:rounded-full focus:bg-ember-500 focus:px-5 focus:py-3 focus:text-sm focus:font-semibold focus:text-ink-950"
        >
          Skip to content
        </a>
        <CartHydrator />
        {children}
      </body>
    </html>
  );
}
