"use client";

import { useEffect, useRef, useState, useTransition } from "react";
import { joinWaitlist } from "@/app/actions/waitlist";
import { Button } from "@/components/ui/button";
import { Checkbox } from "@/components/ui/checkbox";
import { Field, Input, fieldProps } from "@/components/ui/field";
import { track } from "@/lib/analytics/track";
import { WAITLIST_CONSENT_TEXT, fieldErrors, waitlistSchema } from "@/lib/validations";
import type { Store } from "@/types/menu";

/**
 * The public teaser. Deliberately generic (docs/site/teaser-direction.md): it says only
 * that something is coming. No product, price, size, dietary, ingredient, date or
 * location claim, and no product-specific interest picker. Consent is its own
 * unticked checkbox, and success is shown only after the server confirms the signup.
 */
export const TEASER_HEADLINE = "Something exciting is coming";
export const TEASER_BODY = "Be first to know. Join the VIP first-look list.";

type Status = { kind: "idle" } | { kind: "success"; created: boolean } | { kind: "error"; message: string };

export function ComingSoonSection({ stores }: { stores: Store[] }) {
  const sectionRef = useRef<HTMLElement>(null);
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [consent, setConsent] = useState(false);
  const [status, setStatus] = useState<Status>({ kind: "idle" });
  const [pending, startTransition] = useTransition();

  useEffect(() => {
    const el = sectionRef.current;
    if (!el || typeof IntersectionObserver === "undefined") return;
    const io = new IntersectionObserver(
      (entries) => {
        if (entries.some((e) => e.isIntersecting)) {
          track("coming_soon_view", { surface: "home" });
          io.disconnect();
        }
      },
      { threshold: 0.4 },
    );
    io.observe(el);
    return () => io.disconnect();
  }, []);

  function onSubmit(event: React.FormEvent<HTMLFormElement>) {
    event.preventDefault();
    const form = new FormData(event.currentTarget);
    const input = {
      name: String(form.get("name") ?? ""),
      email: String(form.get("email") ?? ""),
      phone: String(form.get("phone") ?? ""),
      storeSlug: String(form.get("storeSlug") ?? "") || undefined,
      company: String(form.get("company") ?? ""),
      consent,
    };
    const parsed = waitlistSchema.safeParse(input);
    if (!parsed.success) {
      setErrors(fieldErrors(parsed.error));
      setStatus({ kind: "idle" });
      return;
    }
    setErrors({});
    startTransition(async () => {
      const result = await joinWaitlist(input);
      if (result.ok) {
        track("waitlist_submit", { store: parsed.data.storeSlug ?? "none" });
        setStatus({ kind: "success", created: result.created });
      } else {
        setErrors(result.fieldErrors ?? {});
        setStatus({ kind: "error", message: result.message });
      }
    });
  }

  return (
    <section
      ref={sectionRef}
      id="coming-soon"
      data-testid="coming-soon-section"
      aria-labelledby="coming-soon-heading"
      className="container-page py-24"
    >
      <p className="eyebrow">Coming soon</p>
      <h2 id="coming-soon-heading" className="mt-4 font-serif text-4xl font-bold sm:text-5xl">
        {TEASER_HEADLINE}
      </h2>
      <p className="mt-4 max-w-xl text-flour/80">{TEASER_BODY}</p>

      {status.kind === "success" ? (
        <p role="status" data-testid="waitlist-success" className="mt-8 max-w-xl rounded-2xl border border-gold-400/40 p-5">
          {status.created ? "You’re on the list. We’ll be in touch." : "You’re already on the list. We’ve updated your details."}
        </p>
      ) : (
        <form data-testid="waitlist-form" noValidate onSubmit={onSubmit} className="mt-8 grid max-w-xl gap-5">
          <Field id="waitlist-name" label="Name" error={errors.name}>
            <Input data-testid="waitlist-name" name="name" autoComplete="name" {...fieldProps("waitlist-name", errors.name)} />
          </Field>
          <Field id="waitlist-email" label="Email" error={errors.email}>
            <Input
              data-testid="waitlist-email"
              name="email"
              type="email"
              autoComplete="email"
              {...fieldProps("waitlist-email", errors.email)}
            />
          </Field>
          <Field id="waitlist-phone" label="Mobile (optional)" error={errors.phone}>
            <Input
              data-testid="waitlist-phone"
              name="phone"
              type="tel"
              autoComplete="tel"
              {...fieldProps("waitlist-phone", errors.phone)}
            />
          </Field>
          <Field id="waitlist-store" label="Preferred store (optional)" error={errors.storeSlug}>
            <select
              data-testid="waitlist-store"
              name="storeSlug"
              defaultValue=""
              className="h-12 w-full rounded-2xl border border-white/15 bg-ink-950 px-4 text-base text-flour"
              {...fieldProps("waitlist-store", errors.storeSlug)}
            >
              <option value="">No preference</option>
              {stores.map((s) => (
                <option key={s.slug} value={s.slug}>
                  {s.name}
                </option>
              ))}
            </select>
          </Field>

          {/* Honeypot: invisible to people and assistive tech, tempting to bots. */}
          <input
            type="text"
            name="company"
            tabIndex={-1}
            aria-hidden="true"
            autoComplete="off"
            className="absolute -left-[9999px] h-0 w-0 opacity-0"
          />

          <div className="grid gap-2">
            <div className="flex items-start gap-3">
              <Checkbox
                id="waitlist-consent"
                data-testid="waitlist-consent"
                checked={consent}
                onCheckedChange={(v) => setConsent(v === true)}
                aria-invalid={errors.consent ? true : undefined}
                aria-describedby={errors.consent ? "waitlist-consent-error" : undefined}
              />
              <label htmlFor="waitlist-consent" className="text-sm text-flour/80">
                {WAITLIST_CONSENT_TEXT}
              </label>
            </div>
            {errors.consent ? (
              <p id="waitlist-consent-error" role="alert" className="text-sm text-[#ff8d84]">
                {errors.consent}
              </p>
            ) : null}
          </div>

          {status.kind === "error" ? (
            <p role="alert" className="text-sm text-[#ff8d84]">
              {status.message}
            </p>
          ) : null}

          <Button type="submit" data-testid="waitlist-submit" isLoading={pending}>
            Join the list
          </Button>
        </form>
      )}
    </section>
  );
}
