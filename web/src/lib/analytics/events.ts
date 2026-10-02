/**
 * The analytics event taxonomy — one typed list of everything we measure.
 *
 * Design rules (they are enforced by the types, so they cannot be forgotten):
 *  1. NO PERSONAL DATA. Params are slugs, counts, enums and amounts. There is
 *     deliberately no field that could hold an email, phone number, name or
 *     free text, so a developer cannot accidentally ship one to an ad platform.
 *  2. Names follow GA4's recommended events where one exists (add_to_cart,
 *     begin_checkout, generate_lead, select_item…) so GA4, Google Ads and Meta
 *     map them without custom work; the rest are clearly named custom events.
 *  3. Money is integer cents here and converted to GA4's decimal `value` (with
 *     currency AUD) at the one place that sends it.
 *  4. `purchase` is NOT fired from the browser. A redirect to Stripe proves
 *     nothing; the verified payment webhook reports it server-side instead.
 */
import type { StoreSlug } from "@/types/menu";

export type ContactSurface = "header" | "footer" | "store_picker" | "location_page" | "cart" | "catering_page";

export interface EventParams {
  // ── Ordering funnel ────────────────────────────────────────────────
  select_store: { store: StoreSlug };
  view_item: { item_slug: string; item_name: string; category: string };
  add_to_cart: { item_slug: string; item_name: string; quantity: number; value_cents: number };
  remove_from_cart: { item_slug: string; quantity: number };
  begin_checkout: {
    store: StoreSlug;
    value_cents: number;
    item_count: number;
    payment_method: "STRIPE" | "PAY_AT_PICKUP";
  };
  /** Pay-at-pickup order placed (no online payment to verify). Card orders report `purchase` server-side. */
  order_placed: { store: StoreSlug; value_cents: number; item_count: number };

  // ── Lead generation (corporate / catering / Minis) ─────────────────
  generate_lead: {
    form: "catering_enquiry" | "minis_waitlist";
    /** CateringEventType or MinisInterest value, never free text. */
    category?: string;
    guest_band?: string;
    store?: StoreSlug | "none";
  };
  quote_step: { step: 1 | 2 };

  // ── Engagement ─────────────────────────────────────────────────────
  hero_explore: { state: "exploded" | "assembled"; renderer: "webgl" | "sprites" | "poster" };
  pack_size_change: { pieces: number; appetite: "light" | "standard" | "hearty" };
  click_to_call: { store: StoreSlug; surface: ContactSurface };
  get_directions: { store: StoreSlug; surface: ContactSurface };
  cta_click: { cta: string; destination: string };
}

export type EventName = keyof EventParams;

export const EVENT_NAMES = [
  "select_store",
  "view_item",
  "add_to_cart",
  "remove_from_cart",
  "begin_checkout",
  "order_placed",
  "generate_lead",
  "quote_step",
  "hero_explore",
  "pack_size_change",
  "click_to_call",
  "get_directions",
  "cta_click",
] as const satisfies readonly EventName[];
