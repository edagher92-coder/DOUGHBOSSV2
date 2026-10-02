import * as React from "react";
import { cva, type VariantProps } from "class-variance-authority";
import { LoaderCircle } from "lucide-react";
import { cn } from "@/lib/utils";

/**
 * Pill buttons. NOTE: ember/gold backgrounds take DARK text (ink-950) —
 * white on ember is 2.6:1 and fails WCAG AA. Anchors can borrow the styling:
 * <a className={buttonVariants({ variant: "outline" })} …>.
 */
export const buttonVariants = cva(
  [
    "inline-flex select-none items-center justify-center gap-2 whitespace-nowrap rounded-full font-semibold uppercase tracking-[0.12em]",
    "transition-[transform,background-color,border-color,box-shadow,opacity,filter] duration-200 ease-oven",
    "active:translate-y-px disabled:pointer-events-none disabled:opacity-45 [&_svg]:shrink-0",
  ],
  {
    variants: {
      variant: {
        primary: "bg-ember-500 text-ink-950 shadow-glow hover:bg-ember-400",
        gold: "bg-gold-metal text-ink-950 shadow-gold hover:brightness-110",
        outline: "border border-white/20 text-flour hover:border-gold-400/60 hover:bg-white/5",
        ghost: "text-flour/80 hover:bg-white/8 hover:text-flour",
      },
      size: {
        sm: "h-9 px-4 text-[0.68rem]",
        md: "h-11 px-6 text-xs",
        lg: "h-14 px-8 text-[0.8rem]",
        icon: "h-11 w-11 text-xs",
      },
    },
    defaultVariants: { variant: "primary", size: "md" },
  },
);

export interface ButtonProps
  extends React.ButtonHTMLAttributes<HTMLButtonElement>,
    VariantProps<typeof buttonVariants> {
  /** Shows a spinner, disables the button and sets aria-busy. */
  isLoading?: boolean;
}

export const Button = React.forwardRef<HTMLButtonElement, ButtonProps>(function Button(
  { className, variant, size, isLoading = false, disabled, children, type = "button", ...props },
  ref,
) {
  return (
    <button
      ref={ref}
      type={type}
      disabled={disabled || isLoading}
      aria-busy={isLoading || undefined}
      className={cn(buttonVariants({ variant, size }), className)}
      {...props}
    >
      {isLoading ? <LoaderCircle aria-hidden className="size-4 animate-spin" /> : null}
      {children}
    </button>
  );
});
