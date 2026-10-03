"use client";

import * as React from "react";
import * as DialogPrimitive from "@radix-ui/react-dialog";
import { X } from "lucide-react";
import { cn } from "@/lib/utils";

/**
 * Dialog + Sheet on Radix: focus trap, Escape, scroll lock, aria wiring and
 * focus return come for free. Every content panel MUST contain a DialogTitle
 * (use className="sr-only" to hide it visually) — screen readers announce it.
 */
export const Dialog = DialogPrimitive.Root;
export const DialogTrigger = DialogPrimitive.Trigger;
export const DialogClose = DialogPrimitive.Close;
export const DialogPortal = DialogPrimitive.Portal;

const Overlay = React.forwardRef<
  React.ComponentRef<typeof DialogPrimitive.Overlay>,
  React.ComponentPropsWithoutRef<typeof DialogPrimitive.Overlay>
>(function Overlay({ className, ...props }, ref) {
  return (
    <DialogPrimitive.Overlay
      ref={ref}
      className={cn(
        "fixed inset-0 z-50 bg-black/75 backdrop-blur-sm data-[state=open]:animate-fade-in data-[state=closed]:animate-fade-out",
        className,
      )}
      {...props}
    />
  );
});

function CloseButton({ className }: { className?: string }) {
  return (
    <DialogPrimitive.Close
      aria-label="Close"
      className={cn(
        "absolute right-4 top-4 grid size-10 place-items-center rounded-full border border-white/15 text-flour/80 transition hover:border-gold-400/60 hover:text-flour",
        className,
      )}
    >
      <X aria-hidden className="size-4" />
    </DialogPrimitive.Close>
  );
}

/** Centred modal. */
export const DialogContent = React.forwardRef<
  React.ComponentRef<typeof DialogPrimitive.Content>,
  React.ComponentPropsWithoutRef<typeof DialogPrimitive.Content>
>(function DialogContent({ className, children, ...props }, ref) {
  return (
    <DialogPortal>
      <Overlay />
      <DialogPrimitive.Content
        ref={ref}
        className={cn(
          "card-surface fixed left-1/2 top-1/2 z-50 max-h-[88svh] w-[calc(100%-2rem)] max-w-lg -translate-x-1/2 -translate-y-1/2 overflow-y-auto p-6 shadow-2xl outline-none",
          "data-[state=open]:animate-pop-in data-[state=closed]:animate-fade-out",
          className,
        )}
        {...props}
      >
        {children}
        <CloseButton />
      </DialogPrimitive.Content>
    </DialogPortal>
  );
});

type SheetSide = "right" | "bottom" | "responsive";

const sheetSideClasses: Record<SheetSide, string> = {
  right:
    "inset-y-0 right-0 w-full max-w-md rounded-l-[1.75rem] data-[state=open]:animate-sheet-in-right data-[state=closed]:animate-sheet-out-right",
  bottom:
    "inset-x-0 bottom-0 max-h-[92svh] rounded-t-[1.75rem] data-[state=open]:animate-sheet-in-bottom data-[state=closed]:animate-sheet-out-bottom",
  // Thumb-friendly bottom sheet on phones, side drawer from md up.
  responsive: [
    "inset-x-0 bottom-0 max-h-[92svh] rounded-t-[1.75rem]",
    "max-md:data-[state=open]:animate-sheet-in-bottom max-md:data-[state=closed]:animate-sheet-out-bottom",
    "md:inset-x-auto md:inset-y-0 md:right-0 md:max-h-none md:w-full md:max-w-md md:rounded-l-[1.75rem] md:rounded-tr-none",
    "md:data-[state=open]:animate-sheet-in-right md:data-[state=closed]:animate-sheet-out-right",
  ].join(" "),
};

/** Side drawer / bottom sheet. */
export const SheetContent = React.forwardRef<
  React.ComponentRef<typeof DialogPrimitive.Content>,
  React.ComponentPropsWithoutRef<typeof DialogPrimitive.Content> & { side?: SheetSide }
>(function SheetContent({ className, children, side = "responsive", ...props }, ref) {
  return (
    <DialogPortal>
      <Overlay />
      <DialogPrimitive.Content
        ref={ref}
        className={cn(
          "card-surface fixed z-50 flex flex-col overflow-hidden border-white/12 bg-ink-900 shadow-2xl outline-none",
          sheetSideClasses[side],
          className,
        )}
        {...props}
      >
        {children}
        <CloseButton />
      </DialogPrimitive.Content>
    </DialogPortal>
  );
});

export const DialogTitle = React.forwardRef<
  React.ComponentRef<typeof DialogPrimitive.Title>,
  React.ComponentPropsWithoutRef<typeof DialogPrimitive.Title>
>(function DialogTitle({ className, ...props }, ref) {
  return <DialogPrimitive.Title ref={ref} className={cn("font-serif text-2xl font-semibold leading-tight", className)} {...props} />;
});

export const DialogDescription = React.forwardRef<
  React.ComponentRef<typeof DialogPrimitive.Description>,
  React.ComponentPropsWithoutRef<typeof DialogPrimitive.Description>
>(function DialogDescription({ className, ...props }, ref) {
  return <DialogPrimitive.Description ref={ref} className={cn("text-sm text-muted", className)} {...props} />;
});
