import { defineConfig, devices } from "@playwright/test";

/**
 * End-to-end tests run against an ISOLATED dev preview, started separately:
 *
 *   scripts/e2e.sh            # starts the preview, runs the suite, stops it
 *   E2E_BASE_URL=http://127.0.0.1:3100 npx playwright test   # against one you started
 *
 * Why not a Playwright webServer running "next start"? Two reasons:
 *  1. The ordering flow needs the labelled-fake DEMO catalogue (real prices are
 *     unconfirmed), and the app deliberately REFUSES to boot demo data in
 *     production (assertDemoAllowed), which "next start" always is.
 *  2. The in-memory order/waitlist stores are likewise development-only.
 * Performance and production-build checks use a separate non-demo run (see
 * docs/ARCHITECTURE.md → Verification).
 */
const baseURL = process.env.E2E_BASE_URL ?? "http://127.0.0.1:3100";

export default defineConfig({
  testDir: "tests/e2e",
  timeout: 90_000,
  expect: { timeout: 10_000 },
  fullyParallel: false,
  workers: 1,
  reporter: [["list"]],
  use: {
    baseURL,
    trace: "retain-on-failure",
    screenshot: "only-on-failure",
    launchOptions: {
      // Headless CI has no GPU: SwiftShader provides a real (software) WebGL2 context.
      args: ["--use-gl=angle", "--use-angle=swiftshader", "--enable-unsafe-swiftshader", "--ignore-gpu-blocklist"],
    },
  },
  projects: [
    { name: "desktop", use: { ...devices["Desktop Chrome"], viewport: { width: 1360, height: 860 } } },
    { name: "mobile", use: { ...devices["Pixel 7"] } },
  ],
});
