import { expect, test } from "@playwright/test";
import {
  expectNoHorizontalOverflow,
  installLayoutShiftObserver,
  readCls,
  significantIssues,
  watchConsole,
} from "./helpers";

// The 3D hero is cancelled (docs/site/hero-decision.md): the home route is a plain text hero,
// the generic coming-soon teaser and the locations footer. No canvas, WebGL or sprite hero.
test.describe("home page", () => {
  test("renders the shell with no console errors, no overflow and negligible layout shift", async ({ page }) => {
    const { issues } = watchConsole(page);
    await installLayoutShiftObserver(page);
    await page.goto("/");

    await expect(page.getByRole("heading", { level: 1 })).toContainText(/crisp base/i);
    await expect(page.locator("header")).toBeVisible();
    await expect(page.getByTestId("coming-soon-section")).toBeVisible();
    await expect(page.locator("footer#locations")).toBeVisible();
    await expectNoHorizontalOverflow(page);

    await page.waitForLoadState("networkidle");
    expect(await readCls(page), "cumulative layout shift").toBeLessThan(0.05);
    expect(significantIssues(issues)).toEqual([]);
  });

  test("no canvas, WebGL, sprite or hero test hooks are rendered on public routes", async ({ page }) => {
    for (const route of ["/", "/?renderer=webgl", "/?renderer=sprites"]) {
      await page.goto(route);
      await page.waitForLoadState("networkidle");
      await expect(page.locator("canvas"), `${route}: no <canvas>`).toHaveCount(0);
      await expect(page.getByTestId("hero")).toHaveCount(0);
      await expect(page.getByTestId("demo-banner")).toHaveCount(0);
      await expect(page.getByTestId("hero-explore")).toHaveCount(0);
      const gl = await page.evaluate(() => Array.from(document.images).filter((i) => /\/hero\/frames\//.test(i.currentSrc || i.src)).length);
      expect(gl, `${route}: no hero frame sprites`).toBe(0);
    }
  });

  test("the skip link is the first tab stop and moves focus to the main content", async ({ page }) => {
    await page.goto("/");
    await page.keyboard.press("Tab");
    const skip = page.getByRole("link", { name: /skip to content/i });
    await expect(skip).toBeFocused();
    await page.keyboard.press("Enter");
    await expect(page).toHaveURL(/#main$/);
  });

  test("every in-page navigation anchor resolves to an element", async ({ page }) => {
    await page.goto("/");
    const hrefs = await page.locator('a[href^="#"]').evaluateAll((els) => els.map((e) => e.getAttribute("href") as string));
    expect(hrefs.length).toBeGreaterThan(0);
    for (const href of new Set(hrefs)) {
      await expect(page.locator(href), `${href} has a target`).toHaveCount(1);
    }
    await expect(page.locator('a[href="#order"]')).toHaveCount(0);
  });
});
