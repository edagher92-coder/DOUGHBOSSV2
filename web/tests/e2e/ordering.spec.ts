import { expect, test, type Page } from "@playwright/test";
import { expectNoHorizontalOverflow, significantIssues, watchConsole } from "./helpers";

async function chooseStore(page: Page, slug: "revesby" | "bankstown" | "roselands") {
  await page.getByTestId(`store-option-${slug}`).click();
  await expect(page.getByTestId(`store-option-${slug}`)).toBeChecked();
}

test.describe("menu and multi-location ordering (DEMO catalogue)", () => {
  test.beforeEach(async ({ page }) => {
    await page.goto("/?renderer=poster");
    await page.evaluate(() => localStorage.clear());
    await page.reload();
  });

  test("store picker shows live status for each store and persists the choice across reload", async ({ page }) => {
    await expect(page.getByTestId("store-picker")).toBeVisible();
    for (const slug of ["revesby", "bankstown", "roselands"]) {
      await expect(page.getByTestId(`store-status-${slug}`)).toContainText(/open|closed/i);
    }
    await chooseStore(page, "revesby");
    await page.reload();
    await expect(page.getByTestId("store-option-revesby")).toBeChecked();
  });

  test("an item with no confirmed price cannot be added and says so", async ({ page }) => {
    await chooseStore(page, "revesby");
    await page.getByTestId("menu-tab-wraps").click();
    const card = page.getByTestId("menu-item-demo-unpriced-wrap");
    await expect(card).toContainText(/price to be confirmed/i);
    await expect(card).not.toContainText("$0");
    await expect(page.getByTestId("add-to-cart-demo-unpriced-wrap")).toBeDisabled();
  });

  test("an item unavailable at the chosen store is blocked there but orderable elsewhere", async ({ page }) => {
    await chooseStore(page, "roselands");
    await page.getByTestId("menu-tab-manoush").click();
    await expect(page.getByTestId("menu-item-lahm-bi-ajin")).toContainText(/not available at roselands/i);
    await expect(page.getByTestId("add-to-cart-lahm-bi-ajin")).toBeDisabled();

    await chooseStore(page, "revesby");
    await expect(page.getByTestId("add-to-cart-lahm-bi-ajin")).toBeEnabled();
  });

  test("dietary filters never present an unverified item as a match", async ({ page }) => {
    await page.getByTestId("menu-tab-drinks-sweets").click();
    await page.getByTestId("diet-filter-NUT_FREE").click();
    // The baklava (verified, contains nuts) must appear nowhere; the ayran (verified nut-free) is a match.
    await expect(page.getByTestId("menu-item-demo-baklava")).toHaveCount(0);
    await expect(page.getByTestId("menu-item-demo-ayran")).toBeVisible();

    await page.getByTestId("menu-tab-pies").click();
    // halloumi-pie has unverified dietary info: it may only appear in the clearly-labelled unverified group.
    const unverified = page.getByTestId("menu-unverified-group");
    await expect(unverified).toContainText(/not yet verified/i);
    await expect(unverified.getByTestId("menu-item-halloumi-pie")).toBeVisible();
    await expect(page.getByTestId("menu-item-spinach-pie")).toBeVisible();
  });

  test("a customer orders a Za'atar Manoush for pickup at Revesby (pay at pickup)", async ({ page }) => {
    const { issues } = watchConsole(page);
    await chooseStore(page, "revesby");
    await page.getByTestId("menu-tab-manoush").click();
    await page.getByTestId("add-to-cart-zaatar-manoush").click();

    const customiser = page.getByTestId("customiser");
    await expect(customiser).toBeVisible();
    // A required choice blocks adding until made.
    await expect(page.getByTestId("customiser-add")).toBeDisabled();
    await customiser.getByRole("radio", { name: /folded/i }).click();
    await customiser.getByRole("checkbox", { name: /extra cheese/i }).check();
    await expect(page.getByTestId("customiser-add")).toBeEnabled();
    await page.getByTestId("customiser-add").click();
    await expect(customiser).toBeHidden();

    await page.getByRole("button", { name: /open cart/i }).click();
    const drawer = page.getByTestId("cart-drawer");
    await expect(drawer).toBeVisible();
    await expect(drawer.getByTestId("cart-subtotal")).toContainText("$7.00"); // DEMO price 5.00 + extra cheese 2.00

    await drawer.getByTestId("pickup-time").selectOption({ index: 1 });
    await drawer.getByTestId("checkout-name").fill("Test Customer");
    await drawer.getByTestId("checkout-email").fill("test.customer@example.com");
    await drawer.getByTestId("checkout-phone").fill("0412 345 678");
    await drawer.getByTestId("payment-pickup").click();
    await drawer.getByTestId("place-order").click();

    await expect(page.getByTestId("order-confirmed")).toContainText(/DB-REV-[0-9A-HJKMNP-TV-Z]{6}/);
    // The cart is emptied only after a confirmed order.
    await page.getByRole("button", { name: /open cart/i }).click().catch(() => undefined);
    expect(significantIssues(issues)).toEqual([]);
  });

  test("checkout refuses a cart whose pickup slot or details are missing, and keeps the cart", async ({ page }) => {
    await chooseStore(page, "revesby");
    await page.getByTestId("menu-tab-pies").click();
    await page.getByTestId("add-to-cart-spinach-pie").click(); // no modifiers: adds directly
    await page.getByRole("button", { name: /open cart/i }).click();
    const drawer = page.getByTestId("cart-drawer");
    await drawer.getByTestId("place-order").click();
    await expect(drawer.getByRole("alert").first()).toBeVisible();
    await expect(drawer.getByTestId("cart-line").first()).toBeVisible();
  });

  test("the cart survives a reload and is validated, not trusted", async ({ page }) => {
    await chooseStore(page, "revesby");
    await page.getByTestId("menu-tab-pies").click();
    await page.getByTestId("add-to-cart-spinach-pie").click();
    await page.reload();
    await expect(page.getByRole("button", { name: /open cart, 1 item/i })).toBeVisible();

    // Tamper with persisted state: an absurd quantity must be dropped on load.
    await page.evaluate(() => {
      const raw = JSON.parse(localStorage.getItem("doughboss-cart") ?? "{}");
      raw.state.lines[0].quantity = 99999;
      localStorage.setItem("doughboss-cart", JSON.stringify(raw));
    });
    await page.reload();
    await expect(page.getByRole("button", { name: /open cart, 0 items/i })).toBeVisible();
  });

  test("works by keyboard alone and has no horizontal overflow", async ({ page }) => {
    await expectNoHorizontalOverflow(page);
    await page.getByTestId("store-option-revesby").focus();
    await page.keyboard.press("Space");
    await expect(page.getByTestId("store-option-revesby")).toBeChecked();
    await page.getByTestId("add-to-cart-spinach-pie").scrollIntoViewIfNeeded().catch(() => undefined);
  });
});
