import { expect, test } from "@playwright/test";
import {
  expectNoHorizontalOverflow,
  installLayoutShiftObserver,
  readCls,
  significantIssues,
  watchConsole,
} from "./helpers";

test.describe("home page and hero", () => {
  test("renders the shell and hero with no console errors, no overflow and negligible layout shift", async ({ page }) => {
    const { issues } = watchConsole(page);
    await installLayoutShiftObserver(page);
    await page.goto("/?renderer=poster");

    await expect(page.getByRole("heading", { level: 1 })).toContainText(/crisp base/i);
    await expect(page.getByTestId("hero")).toHaveAttribute("data-renderer", "poster");
    await expect(page.getByTestId("demo-banner")).toBeVisible();
    await expectNoHorizontalOverflow(page);

    // Let fonts, images and the cart hydration settle before judging layout stability.
    await page.waitForLoadState("networkidle");
    expect(await readCls(page), "cumulative layout shift").toBeLessThan(0.05);
    expect(significantIssues(issues)).toEqual([]);
  });

  test("the skip link is the first tab stop and moves focus to the main content", async ({ page }) => {
    await page.goto("/?renderer=poster");
    await page.keyboard.press("Tab");
    const skip = page.getByRole("link", { name: /skip to content/i });
    await expect(skip).toBeFocused();
    await page.keyboard.press("Enter");
    await expect(page).toHaveURL(/#main$/);
  });

  test("the Explore toggle blows the pizza apart and back, by keyboard", async ({ page }) => {
    await page.goto("/?renderer=poster");
    const hero = page.getByTestId("hero");
    const explore = page.getByTestId("hero-explore");

    await expect(hero).toHaveAttribute("data-state", "assembled");
    await expect(explore).toHaveAttribute("aria-pressed", "false");

    await explore.focus();
    await page.keyboard.press("Enter");
    await expect(hero).toHaveAttribute("data-state", "exploded");
    await expect(explore).toHaveAttribute("aria-pressed", "true");

    await page.keyboard.press("Enter");
    await expect(hero).toHaveAttribute("data-state", "assembled");
  });

  test("every renderer tier can be forced and reports itself", async ({ page }) => {
    for (const tier of ["poster", "sprites", "webgl"] as const) {
      await page.goto(`/?renderer=${tier}`);
      // Software WebGL in headless Chromium is slow to start: allow generous time, but the tier must stick.
      await expect(page.getByTestId("hero")).toHaveAttribute("data-renderer", tier, { timeout: 45_000 });
    }
  });

  test("visitors who ask for reduced motion get the static poster tier and an unpinned hero", async ({ browser }) => {
    const context = await browser.newContext({ reducedMotion: "reduce" });
    const page = await context.newPage();
    await page.goto("/");
    await expect(page.getByTestId("hero")).toHaveAttribute("data-renderer", "poster");
    // Not pinned: the hero is no taller than a couple of viewports, so the page is not a scroll runway.
    const heroHeight = await page.getByTestId("hero").evaluate((el) => el.getBoundingClientRect().height);
    const viewport = page.viewportSize()?.height ?? 800;
    expect(heroHeight).toBeLessThan(viewport * 1.6);
    await context.close();
  });

  test("the primary call to action goes to the ordering section", async ({ page }) => {
    await page.goto("/?renderer=poster");
    await page.getByTestId("hero-order-cta").click();
    await expect(page).toHaveURL(/#order$/);
    await expect(page.getByTestId("store-picker")).toBeVisible();
  });
});
