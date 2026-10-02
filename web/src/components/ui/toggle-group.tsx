"use client";

import * as React from "react";
import * as ToggleGroupPrimitive from "@radix-ui/react-toggle-group";
import { cn } from "@/lib/utils";

/** Use type="multiple" for filters and type="single" for segmented choices. */
export const ToggleGroup = React.forwardRef<
  React.ComponentRef<typeof ToggleGroupPrimitive.Root>,
  React.ComponentPropsWithoutRef<typeof ToggleGroupPrimitive.Root>
>(function ToggleGroup({ className, ...props }, ref) {
  return <ToggleGroupPrimitive.Root ref={ref} className={cn("flex flex-wrap gap-2", className)} {...props} />;
});

export const ToggleGroupItem = React.forwardRef<
  React.ComponentRef<typeof ToggleGroupPrimitive.Item>,
  React.ComponentPropsWithoutRef<typeof ToggleGroupPrimitive.Item>
>(function ToggleGroupItem({ className, ...props }, ref) {
  return (
    <ToggleGroupPrimitive.Item
      ref={ref}
      className={cn(
        "inline-flex h-10 items-center gap-2 rounded-full border border-white/15 px-4 text-xs font-semibold uppercase tracking-[0.12em] text-flour/75 transition-colors duration-200",
        "hover:border-white/30 hover:text-flour",
        "data-[state=on]:border-gold-400 data-[state=on]:bg-gold-400/15 data-[state=on]:text-gold-200",
        className,
      )}
      {...props}
    />
  );
});
