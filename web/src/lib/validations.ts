/**
 * Zod schemas shared by the browser (instant feedback) and the server actions
 * (the real gate). Client validation is a courtesy; the server re-parses
 * everything — `safeParse` on the server is what protects the database.
 */
import { z } from "zod";
import { MAX_CART_LINES, MAX_LINE_QUANTITY } from "./pricing";

export const STORE_SLUGS = ["revesby", "bankstown", "roselands"] as const;
export const PAYMENT_METHODS = ["SQUARE", "PAY_AT_SHOP"] as const;

/** The exact wording a subscriber agrees to — stored with their consent timestamp. */
export const WAITLIST_CONSENT_TEXT =
  "I agree to Dough Boss contacting me about what's coming and early access. I can unsubscribe at any time.";

const noControlChars = (s: string) => !/[\u0000-\u001f\u007f]/.test(s);

const personName = z
  .string()
  .trim()
  .min(2, "Please enter your name")
  .max(80, "That name is a bit long")
  .refine(noControlChars, "Please remove unusual characters");

const email = z
  .string()
  .trim()
  .toLowerCase()
  .max(254, "That email is too long")
  .pipe(z.email("Enter a valid email address"));

/** Accepts spaces, dashes, brackets and a leading +; normalises to digits (+ kept). 8–15 digits. */
const phone = z
  .string()
  .trim()
  .transform((s) => s.replace(/[\s\-().]/g, ""))
  .refine((s) => /^\+?\d{8,15}$/.test(s), "Enter a valid phone number");

const optionalPhone = z
  .union([z.literal(""), phone])
  .optional()
  .transform((v) => (v ? v : undefined));

export const waitlistSchema = z.object({
  name: personName,
  email,
  phone: optionalPhone,
  storeSlug: z.enum(STORE_SLUGS).optional(),
  consent: z.literal(true, { error: "Please tick the box so we’re allowed to contact you" }),
  /** Honeypot: real people never see or fill this. Bots do. */
  company: z.string().max(0).optional(),
});
export type WaitlistInput = z.input<typeof waitlistSchema>;
export type WaitlistData = z.output<typeof waitlistSchema>;

export const cartLineInputSchema = z.object({
  itemSlug: z.string().min(1).max(80),
  quantity: z.number().int().min(1).max(MAX_LINE_QUANTITY),
  selections: z.record(z.string().max(60), z.array(z.string().max(60)).max(10)),
  note: z.string().trim().max(140).optional(),
});

export const checkoutSchema = z.object({
  storeSlug: z.enum(STORE_SLUGS),
  /** UTC ISO instant of the chosen pickup slot. Re-validated against store hours on the server. */
  pickupAt: z.iso.datetime(),
  customer: z.object({ name: personName, email, phone }),
  notes: z.string().trim().max(300).optional(),
  paymentMethod: z.enum(PAYMENT_METHODS),
  lines: z.array(cartLineInputSchema).min(1, "Your cart is empty").max(MAX_CART_LINES, "That’s a lot of lines — please call the store"),
});
export type CheckoutInput = z.input<typeof checkoutSchema>;
export type CheckoutData = z.output<typeof checkoutSchema>;

/** `{ field: "first message" }` for a form, from a failed safeParse. */
export function fieldErrors(error: z.ZodError): Record<string, string> {
  const flat = z.flattenError(error).fieldErrors as Record<string, string[] | undefined>;
  const out: Record<string, string> = {};
  for (const [key, messages] of Object.entries(flat)) {
    if (messages?.[0]) out[key] = messages[0];
  }
  return out;
}
