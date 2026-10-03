const aud = new Intl.NumberFormat("en-AU", { style: "currency", currency: "AUD" });

/** Integer cents → "$12.50". Never call with a null price — handle "TBC" at the call site. */
export function formatMoney(cents: number): string {
  if (!Number.isInteger(cents)) throw new Error(`formatMoney expects integer cents, got ${cents}`);
  return aud.format(cents / 100);
}

/** "+ $2.00", "+ $0.00" → "included", used for modifier price deltas. */
export function formatDelta(cents: number | null): string {
  if (cents === null) return "price TBC";
  if (cents === 0) return "no extra cost";
  return `${cents > 0 ? "+" : "−"} ${formatMoney(Math.abs(cents))}`;
}
