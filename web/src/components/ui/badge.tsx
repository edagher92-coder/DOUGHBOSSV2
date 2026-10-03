import * as React from "react";
import { cva, type VariantProps } from "class-variance-authority";
import { cn } from "@/lib/utils";

export const badgeVariants = cva(
  "inline-flex items-center gap-1.5 rounded-full border px-2.5 py-1 text-[0.65rem] font-semibold uppercase leading-none tracking-[0.14em]",
  {
    variants: {
      variant: {
        neutral: "border-white/15 bg-white/5 text-flour/85",
        ember: "border-ember-500/40 bg-ember-500/12 text-ember-300",
        gold: "border-gold-400/40 bg-gold-400/12 text-gold-300",
        success: "border-mint-500/40 bg-mint-500/12 text-[#7ee0a4]",
        warning: "border-amber-400/40 bg-amber-400/10 text-amber-300",
        danger: "border-chili-500/45 bg-chili-500/12 text-[#ff8d84]",
        outline: "border-white/20 text-flour/80",
      },
    },
    defaultVariants: { variant: "neutral" },
  },
);

export interface BadgeProps extends React.HTMLAttributes<HTMLSpanElement>, VariantProps<typeof badgeVariants> {}

export function Badge({ className, variant, ...props }: BadgeProps) {
  return <span className={cn(badgeVariants({ variant }), className)} {...props} />;
}
