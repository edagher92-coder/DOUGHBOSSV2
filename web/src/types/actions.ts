/**
 * Result contracts for the server actions. They live in a plain module (not in
 * the "use server" files) so client components can import the types freely.
 *
 * Both unions are discriminated on `ok`. A failure always carries a
 * customer-safe `message` — never a stack trace, SQL, provider error body or
 * any other internals — plus machine-readable detail for the UI to use.
 */

export type ActionFieldErrors = Record<string, string>;

export type WaitlistFailureCode = "VALIDATION" | "RATE_LIMITED" | "STORAGE_UNAVAILABLE" | "UNKNOWN";

export type WaitlistResult =
  | {
      ok: true;
      /** false when the email was already on the list (we merged their interests instead). */
      created: boolean;
    }
  | {
      ok: false;
      code: WaitlistFailureCode;
      message: string;
      fieldErrors?: ActionFieldErrors;
      retryAfterSeconds?: number;
    };

export type CheckoutFailureCode =
  | "VALIDATION"
  | "RATE_LIMITED"
  | "CATALOGUE_UNAVAILABLE"
  | "STORE_UNAVAILABLE"
  | "INVALID_PICKUP"
  | "CART_INVALID"
  | "PAYMENT_UNAVAILABLE"
  | "STORAGE_UNAVAILABLE"
  | "PAYMENT_PROVIDER_ERROR"
  | "UNKNOWN";

export type CheckoutResult =
  | {
      /** Card payment: send the browser to Stripe Checkout. The order exists but is unpaid until the webhook confirms it. */
      ok: true;
      kind: "redirect";
      orderNumber: string;
      url: string;
    }
  | {
      /** Pay at pickup (only when the business has switched it on): the order is placed and confirmed. */
      ok: true;
      kind: "confirmed";
      orderNumber: string;
      pickupAtIso: string;
      storeName: string;
      totalCents: number;
    }
  | {
      ok: false;
      code: CheckoutFailureCode;
      message: string;
      fieldErrors?: ActionFieldErrors;
      /** Per-line problems keyed by the client's lineId, e.g. an item that became unavailable. */
      lineErrors?: Record<string, string>;
    };

/** Which payment routes the server has switched on — computed on the server, passed to the client as props. */
export interface PaymentOptions {
  stripe: boolean;
  payAtPickup: boolean;
}
