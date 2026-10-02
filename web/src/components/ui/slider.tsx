"use client";

import * as React from "react";
import * as SliderPrimitive from "@radix-ui/react-slider";
import { cn } from "@/lib/utils";

export interface SliderProps extends React.ComponentPropsWithoutRef<typeof SliderPrimitive.Root> {
  /** Accessible name for the (single) thumb — sliders without a name are unusable by screen readers. */
  thumbLabel: string;
  /** Human-readable value announced instead of the raw number, e.g. "50 pieces". */
  valueText?: string;
}

export const Slider = React.forwardRef<React.ComponentRef<typeof SliderPrimitive.Root>, SliderProps>(function Slider(
  { className, thumbLabel, valueText, ...props },
  ref,
) {
  return (
    <SliderPrimitive.Root
      ref={ref}
      className={cn("relative flex h-10 w-full touch-none select-none items-center", className)}
      {...props}
    >
      <SliderPrimitive.Track className="relative h-1.5 w-full grow overflow-hidden rounded-full bg-white/12">
        <SliderPrimitive.Range className="absolute h-full bg-gold-metal" />
      </SliderPrimitive.Track>
      <SliderPrimitive.Thumb
        aria-label={thumbLabel}
        aria-valuetext={valueText}
        className="block size-7 rounded-full border-2 border-gold-200 bg-ink-950 shadow-gold transition-transform duration-150 hover:scale-110 focus-visible:scale-110 data-[disabled]:opacity-50"
      />
    </SliderPrimitive.Root>
  );
});
