import { expect, test } from "@playwright/test";
import { expectNoHorizontalOverflow, significantIssues, watchConsole } from "./helpers";

// The public teaser is generic by direction (docs/site/teaser-direction.md): it says only that
// something is coming, and makes no product, price, size, dietary, ingredient, date or location claim.
test.describe("coming-soon teaser and waitlist", () => {
  test.beforeEach(async ({ page }) => {
    await page.goto("/?renderer=poster#coming-soon");
  });

  test("the section is generic: no price, no product wording, no product cards, no pack sizer, no interest picker", async ({
    page,
  }) => {
    const section = page.getByTestId("coming-soon-section");
    await expect(section).toBeVisible();
    await expect(section.getByRole("heading", { name: /something exciting is coming/i })).toBeVisible();

    const text = await section.innerText();
    expect(text, "no price on the teaser").not.toMatch(/\$\s?\d/);
    expect(text, "no product, pack, dietary or date wording").not.toMatch(
      /mini|pizza|pack|bites|pieces|halal|vegan|vegetarian|gluten|ingredient|launch(es|ing)? (on|in)|\b\d{1,2}(st|nd|rd|th)?\s+(jan|feb|mar|apr|may|jun|jul|aug|sep|oct|nov|dec)/i,
    );

    await expect(page.locator('[data-testid^="minis-card-"]')).toHaveCount(0);
    await expect(page.getByTestId("pack-slider")).toHaveCount(0);
    await expect(page.getByTestId("pack-summary")).toHaveCount(0);
    // No product-specific interest chips: the only buttons in the form are the submit and the consent box.
    await expect(page.getByTestId("waitlist-form").getByRole("button")).toHaveCount(1);
  });

  test("the header navigation points to the neutral anchor", async ({ page }) => {
    await page.goto("/?renderer=poster");
    const link = page.getByRole("navigation", { name: /primary/i }).getByRole("link", { name: /coming soon/i });
    await expect(link).toHaveAttribute("href", "#coming-soon");
    await expect(page.locator('a[href="#minis"]')).toHaveCount(0);
  });

  test("the waitlist validates on the client and shows no success when the form is invalid", async ({ page }) => {
    const { issues } = watchConsole(page);
    const form = page.getByTestId("waitlist-form");
    await form.getByTestId("waitlist-submit").click();
    await expect(form.getByRole("alert").first()).toBeVisible();
    await expect(page.getByTestId("waitlist-success")).toHaveCount(0);
    expect(significantIssues(issues)).toEqual([]);
  });

  test("consent is a separate checkbox and starts unticked; submitting without it is refused", async ({ page }) => {
    const form = page.getByTestId("waitlist-form");
    const consent = form.getByRole("checkbox", { name: /i agree/i });
    await expect(consent).not.toBeChecked();

    await form.getByTestId("waitlist-name").fill("Test Customer");
    await form.getByTestId("waitlist-email").fill(`e2e+noconsent${Date.now()}@example.com`);
    await form.getByTestId("waitlist-submit").click();
    await expect(form.getByRole("alert").filter({ hasText: /tick the box/i })).toBeVisible();
    await expect(page.getByTestId("waitlist-success")).toHaveCount(0);
  });

  test("a valid signup is confirmed by the server, and the honeypot is invisible to people", async ({ page }) => {
    const form = page.getByTestId("waitlist-form");
    const honeypot = form.locator('input[name="company"]');
    await expect(honeypot).toHaveAttribute("tabindex", "-1");
    await expect(honeypot).toHaveAttribute("aria-hidden", "true");

    await form.getByTestId("waitlist-name").fill("Test Customer");
    await form.getByTestId("waitlist-email").fill(`e2e+${Date.now()}@example.com`);
    await form.getByRole("checkbox", { name: /i agree/i }).check();
    await form.getByTestId("waitlist-submit").click();
    await expect(page.getByTestId("waitlist-success")).toBeVisible({ timeout: 15_000 });
    await expectNoHorizontalOverflow(page);
  });

  test("the form can be completed by keyboard alone", async ({ page }) => {
    const form = page.getByTestId("waitlist-form");
    await form.getByTestId("waitlist-name").focus();
    await page.keyboard.type("Keyboard Customer");
    await page.keyboard.press("Tab");
    await page.keyboard.type(`e2e+kbd${Date.now()}@example.com`);
    // Mobile (optional), store (optional), then the consent checkbox.
    await page.keyboard.press("Tab");
    await page.keyboard.press("Tab");
    await page.keyboard.press("Tab");
    await expect(form.getByRole("checkbox", { name: /i agree/i })).toBeFocused();
    await page.keyboard.press("Space");
    await page.keyboard.press("Tab");
    await expect(form.getByTestId("waitlist-submit")).toBeFocused();
    await page.keyboard.press("Enter");
    await expect(page.getByTestId("waitlist-success")).toBeVisible({ timeout: 15_000 });
  });

  test("no horizontal overflow at the teaser", async ({ page }) => {
    await expectNoHorizontalOverflow(page);
  });
});
