"use client";

import * as React from "react";
import * as CheckboxPrimitive from "@radix-ui/react-checkbox";
import { Check } from "lucide-react";
import { cn } from "@/lib/utils";

/** Pair with a <label htmlFor={id}> — the visible box is 24px but the label gives the full tap target. */
export const Checkbox = React.forwardRef<
  React.ComponentRef<typeof CheckboxPrimitive.Root>,
  React.ComponentPropsWithoutRef<typeof CheckboxPrimitive.Root>
>(function Checkbox({ className, ...props }, ref) {
  return (
    <CheckboxPrimitive.Root
      ref={ref}
      className={cn(
        "grid size-6 shrink-0 place-items-center rounded-md border border-white/30 bg-ink-950 transition-colors duration-150",
        "hover:border-gold-400/70 data-[state=checked]:border-gold-400 data-[state=checked]:bg-gold-400 data-[state=checked]:text-ink-950",
        "aria-[invalid=true]:border-chili-500",
        className,
      )}
      {...props}
    >
      <CheckboxPrimitive.Indicator>
        <Check aria-hidden className="size-4" strokeWidth={3} />
      </CheckboxPrimitive.Indicator>
    </CheckboxPrimitive.Root>
  );
});
