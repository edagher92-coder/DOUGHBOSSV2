"use client";

import * as React from "react";
import * as RadioGroupPrimitive from "@radix-ui/react-radio-group";
import { cn } from "@/lib/utils";

export const RadioGroup = React.forwardRef<
  React.ComponentRef<typeof RadioGroupPrimitive.Root>,
  React.ComponentPropsWithoutRef<typeof RadioGroupPrimitive.Root>
>(function RadioGroup({ className, ...props }, ref) {
  return <RadioGroupPrimitive.Root ref={ref} className={cn("grid gap-2", className)} {...props} />;
});

export const RadioGroupItem = React.forwardRef<
  React.ComponentRef<typeof RadioGroupPrimitive.Item>,
  React.ComponentPropsWithoutRef<typeof RadioGroupPrimitive.Item>
>(function RadioGroupItem({ className, ...props }, ref) {
  return (
    <RadioGroupPrimitive.Item
      ref={ref}
      className={cn(
        "grid size-6 shrink-0 place-items-center rounded-full border border-white/30 bg-ink-950 transition-colors duration-150",
        "hover:border-gold-400/70 data-[state=checked]:border-ember-500",
        className,
      )}
      {...props}
    >
      <RadioGroupPrimitive.Indicator className="size-3 rounded-full bg-ember-500" />
    </RadioGroupPrimitive.Item>
  );
});
