import AxeBuilder from "@axe-core/playwright";
import { expect, type Page } from "@playwright/test";

/** Collects console errors/warnings and uncaught exceptions for the life of a page. */
export function watchConsole(page: Page): { issues: string[] } {
  const issues: string[] = [];
  page.on("console", (msg) => {
    if (msg.type() === "error" || msg.type() === "warning") issues.push(`[${msg.type()}] ${msg.text()}`);
  });
  page.on("pageerror", (err) => issues.push(`[pageerror] ${err.message}`));
  return { issues };
}

/** The dev server prints benign noise (HMR, favicon in some setups); filter only what we have explicitly accepted. */
export function significantIssues(issues: string[]): string[] {
  return issues.filter(
    (i) =>
      !/\[HMR\]|Download the React DevTools|Fast Refresh|webpack-hmr|\/_next\/webpack-hmr/i.test(i) &&
      // Software-WebGL in headless Chromium logs GPU stall notices; they are not app defects.
      !/GL Driver Message|GPU stall due to ReadPixels|WebGL: INVALID_OPERATION: texImage2D: ArrayBufferView not big enough/i.test(i),
  );
}

export async function expectNoHorizontalOverflow(page: Page): Promise<void> {
  const { scrollWidth, clientWidth } = await page.evaluate(() => ({
    scrollWidth: document.documentElement.scrollWidth,
    clientWidth: document.documentElement.clientWidth,
  }));
  expect(scrollWidth, "page must not scroll horizontally").toBeLessThanOrEqual(clientWidth);
}

/** Cumulative layout shift over the page's life so far (needs to be installed before navigation). */
export async function installLayoutShiftObserver(page: Page): Promise<void> {
  await page.addInitScript(() => {
    (window as unknown as { __cls: number }).__cls = 0;
    new PerformanceObserver((list) => {
      for (const entry of list.getEntries() as PerformanceEntry[] & { hadRecentInput?: boolean; value?: number }[]) {
        const e = entry as unknown as { hadRecentInput: boolean; value: number };
        if (!e.hadRecentInput) (window as unknown as { __cls: number }).__cls += e.value;
      }
    }).observe({ type: "layout-shift", buffered: true });
  });
}

export async function readCls(page: Page): Promise<number> {
  return page.evaluate(() => (window as unknown as { __cls?: number }).__cls ?? 0);
}

/** Axe scan; fails on serious or critical violations and prints each one legibly. */
export async function expectNoSeriousA11yViolations(page: Page, label: string, exclude: string[] = []): Promise<void> {
  // @axe-core/playwright bundles its own Playwright types; the runtime object is identical, so cast across the duplicate declaration.
  let builder = new AxeBuilder({ page: page as never }).withTags(["wcag2a", "wcag2aa", "wcag21a", "wcag21aa", "wcag22aa"]);
  for (const selector of exclude) builder = builder.exclude(selector);
  const { violations } = await builder.analyze();
  const serious = violations.filter((v) => v.impact === "serious" || v.impact === "critical");
  const report = serious
    .map((v) => `${v.impact}: ${v.id} — ${v.help}\n   ${v.nodes.slice(0, 3).map((n) => n.target.join(" ")).join("\n   ")}`)
    .join("\n");
  expect(serious, `${label}: axe found serious/critical violations\n${report}`).toEqual([]);
}

/** Every JSON-LD block on the page, parsed (throws if any block is invalid JSON). */
export async function readJsonLd(page: Page): Promise<unknown[]> {
  const blocks = await page.locator('script[type="application/ld+json"]').allTextContents();
  return blocks.map((text) => JSON.parse(text) as unknown);
}
