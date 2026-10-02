import { expect, test } from "@playwright/test";
import {
  expectNoHorizontalOverflow,
  expectNoSeriousA11yViolations,
  readJsonLd,
  significantIssues,
  watchConsole,
} from "./helpers";

const CATERING_ROUTES = [
  "/catering",
  "/catering/corporate",
  "/catering/office-breakfast",
  "/catering/events",
  "/catering/minis",
] as const;
const LOCATION_ROUTES = ["/locations/revesby", "/locations/bankstown", "/locations/roselands"] as const;

test.describe("on-site SEO fundamentals for every marketing route", () => {
  for (const route of [...CATERING_ROUTES, ...LOCATION_ROUTES]) {
    test(`${route}: one h1, unique title and description, canonical, social tags, valid JSON-LD, no placeholders`, async ({ page }) => {
      const { issues } = watchConsole(page);
      const response = await page.goto(route);
      expect(response?.status()).toBe(200);

      await expect(page.locator("h1")).toHaveCount(1);
      const title = await page.title();
      expect(title.length, `title length for ${route}: "${title}"`).toBeLessThanOrEqual(60);
      expect(title.length).toBeGreaterThan(10);

      const description = await page.locator('meta[name="description"]').getAttribute("content");
      expect(description?.length ?? 0).toBeGreaterThan(50);
      expect(description?.length ?? 0).toBeLessThanOrEqual(155);

      await expect(page.locator('link[rel="canonical"]')).toHaveAttribute("href", new RegExp(`${route}$`));
      await expect(page.locator('meta[property="og:title"]')).toHaveCount(1);
      await expect(page.locator('meta[property="og:image"]')).toHaveCount(1);

      const jsonLd = await readJsonLd(page);
      expect(jsonLd.length, "at least one JSON-LD block").toBeGreaterThan(0);

      const text = await page.locator("main").innerText();
      expect(text, "placeholder text must never reach customers").not.toMatch(/\[CONFIRM|TODO|lorem ipsum|TBC/i);
      await expectNoHorizontalOverflow(page);
      expect(significantIssues(issues)).toEqual([]);
    });
  }

  test("titles and descriptions are unique across the marketing routes", async ({ page }) => {
    const titles = new Set<string>();
    const descriptions = new Set<string>();
    for (const route of [...CATERING_ROUTES, ...LOCATION_ROUTES]) {
      await page.goto(route);
      titles.add(await page.title());
      descriptions.add((await page.locator('meta[name="description"]').getAttribute("content")) ?? "");
    }
    expect(titles.size).toBe(CATERING_ROUTES.length + LOCATION_ROUTES.length);
    expect(descriptions.size).toBe(CATERING_ROUTES.length + LOCATION_ROUTES.length);
  });

  test("location pages publish the verified address, phone and hours of their own store only", async ({ page }) => {
    await page.goto("/locations/bankstown");
    const text = await page.locator("main").innerText();
    expect(text).toContain("462 Chapel Rd");
    expect(text).toMatch(/\(02\) 8764 6783/);
    expect(text).not.toContain("Selems Parade");
    await expect(page.locator('a[href="tel:+61287646783"]').first()).toBeVisible();

    const graph = JSON.stringify(await readJsonLd(page));
    expect(graph).toContain('"Bakery"');
    expect(graph).not.toMatch(/priceRange|aggregateRating|"review"/);
  });

  test("unknown location slugs are 404, not an invented page", async ({ page }) => {
    const response = await page.goto("/locations/atlantis");
    expect(response?.status()).toBe(404);
  });

  test("sitemap, robots and manifest are served and consistent", async ({ request }) => {
    const sitemap = await (await request.get("/sitemap.xml")).text();
    for (const route of [...CATERING_ROUTES, ...LOCATION_ROUTES]) expect(sitemap).toContain(route);
    const robots = await (await request.get("/robots.txt")).text();
    expect(robots).toMatch(/Sitemap:/i);
    expect(robots).toMatch(/Disallow:\s*\/api\//);
    const manifest = await request.get("/manifest.webmanifest");
    expect(manifest.ok()).toBe(true);
  });
});

test.describe("catering enquiry form", () => {
  test("a corporate lead can be submitted in two steps and gets a reference", async ({ page }) => {
    await page.goto("/catering/corporate");
    const form = page.getByTestId("catering-enquiry-form");
    await expect(form).toBeVisible();

    await expect(form.getByTestId("enquiry-step-1")).toBeVisible();
    await form.getByRole("combobox", { name: /guests|headcount/i }).selectOption({ index: 3 });
    await form.getByRole("button", { name: /next|continue/i }).click();

    await expect(form.getByTestId("enquiry-step-2")).toBeVisible();
    // Empty required fields must fail with an announced error, not silently.
    await form.getByTestId("enquiry-submit").click();
    await expect(form.getByRole("alert").first()).toBeVisible();

    await form.getByLabel(/^name/i).fill("Test Buyer");
    await form.getByLabel(/email/i).fill(`e2e+${Date.now()}@example.com`);
    await form.getByLabel(/phone/i).fill("0412 345 678");
    await form.getByRole("checkbox", { name: /agree|contact/i }).check();
    await form.getByTestId("enquiry-submit").click();
    await expect(page.getByTestId("enquiry-success")).toContainText(/DB-Q-[0-9A-HJKMNP-TV-Z]{6}/, { timeout: 15_000 });
  });

  test("marketing attribution from the landing URL travels with the lead but never the query string", async ({ page }) => {
    await page.goto("/catering/office-breakfast?utm_source=google&utm_medium=cpc&utm_campaign=corporate-office-breakfast-bankstown&gclid=TESTCLICK123");
    const stored = await page.evaluate(() => JSON.stringify(Object.fromEntries(Object.entries(localStorage))));
    expect(stored).toContain("corporate-office-breakfast-bankstown");
    expect(stored).not.toContain("?utm_source");
  });
});

test.describe("accessibility (axe, WCAG 2.2 AA) on the key pages", () => {
  for (const route of ["/?renderer=poster", "/catering", "/catering/corporate", "/locations/bankstown"]) {
    test(`${route} has no serious or critical violations`, async ({ page }) => {
      await page.goto(route);
      await page.waitForLoadState("networkidle");
      await expectNoSeriousA11yViolations(page, route);
    });
  }
});
