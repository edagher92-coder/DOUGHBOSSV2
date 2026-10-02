import type { NextConfig } from "next";

const securityHeaders = [
  { key: "X-Content-Type-Options", value: "nosniff" },
  { key: "X-Frame-Options", value: "DENY" },
  { key: "Referrer-Policy", value: "strict-origin-when-cross-origin" },
  // Gyro/tilt is used by the hero on a user gesture only; nothing else needs sensors.
  { key: "Permissions-Policy", value: "camera=(), microphone=(), geolocation=(), payment=(self)" },
];

const nextConfig: NextConfig = {
  reactStrictMode: true,
  poweredByHeader: false,
  // three.js ships ESM that Next should transpile for the R3F tree.
  transpilePackages: ["three"],
  experimental: {
    // Tree-shake icon + motion barrels so the hero fallback path stays small.
    optimizePackageImports: ["lucide-react", "motion/react"],
  },
  async headers() {
    return [{ source: "/:path*", headers: securityHeaders }];
  },
};

export default nextConfig;
