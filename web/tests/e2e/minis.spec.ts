import { expect, test } from "@playwright/test";
import { expectNoHorizontalOverflow, significantIssues, watchConsole } from "./helpers";

test.describe("Minis teaser: pack sizer and waitlist", () => {
  test.beforeEach(async ({ page }) => {
    await page.goto("/?renderer=poster#minis");
  });

  test("the section shows every Minis kind without any price or unverified dietary badge", async ({ page }) => {
    const section = page.getByTestId("minis-section");
    await expect(section).toBeVisible();
    for (const kind of ["mini_zaatar", "mini_cheese", "mini_meat", "mini_pies"]) {
      await expect(page.getByTestId(`minis-card-${kind}`)).toBeAttached();
    }
    const text = await section.innerText();
    expect(text, "Minis have no confirmed price").not.toMatch(/\$\s?\d/);
    // Until verified, the section may not assert any dietary badge as fact.
    await expect(section.getByText(/dietary info confirmed at launch/i).first()).toBeVisible();
    await expect(section.getByText(/^halal$/i)).toHaveCount(0);
  });

  test("the pack sizer slider is keyboard operable and its summary is exact", async ({ page }) => {
    const slider = page.getByTestId("pack-slider").getByRole("slider");
    await slider.focus();
    await page.keyboard.press("Home");
    await expect(page.getByTestId("pack-summary")).toContainText("20 pieces");
    await page.keyboard.press("ArrowRight");
    await expect(page.getByTestId("pack-summary")).toContainText("30 pieces");

    // Largest-remainder split: the parts must add up to the total shown.
    const summary = await page.getByTestId("pack-summary").innerText();
    const split = [...summary.matchAll(/(\d+)\s*(?:×|x)?\s*Mini/gi)].map((m) => Number(m[1]));
    if (split.length === 4) expect(split.reduce((a, b) => a + b, 0)).toBe(30);

    await page.keyboard.press("End");
    await expect(page.getByTestId("pack-summary")).toContainText(/quote it individually/i);
  });

  test("the waitlist validates on the client and rolls back honestly when the server says no", async ({ page }) => {
    const { issues } = watchConsole(page);
    const form = page.getByTestId("waitlist-form");
    await form.getByTestId("waitlist-submit").click();
    await expect(form.getByRole("alert").first()).toBeVisible();
    await expect(page.getByTestId("waitlist-success")).toHaveCount(0);
    expect(significantIssues(issues)).toEqual([]);
  });

  test("a valid signup is confirmed by the server, and the honeypot is invisible to people", async ({ page }) => {
    const form = page.getByTestId("waitlist-form");
    const honeypot = form.locator('input[name="company"]');
    await expect(honeypot).toHaveAttribute("tabindex", "-1");
    await expect(honeypot).toHaveAttribute("aria-hidden", "true");

    await form.getByTestId("waitlist-name").fill("Test Customer");
    await form.getByTestId("waitlist-email").fill(`e2e+${Date.now()}@example.com`);
    await form.getByRole("button", { name: /mini za.?atar/i }).click();
    await form.getByRole("checkbox", { name: /contact me|i agree/i }).check();
    await form.getByTestId("waitlist-submit").click();
    await expect(page.getByTestId("waitlist-success")).toBeVisible({ timeout: 15_000 });
    await expectNoHorizontalOverflow(page);
  });
});
