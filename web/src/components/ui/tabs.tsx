"use client";

import * as React from "react";
import * as TabsPrimitive from "@radix-ui/react-tabs";
import { cn } from "@/lib/utils";

export const Tabs = TabsPrimitive.Root;

/** Horizontally scrollable pill row — swipeable on phones, wraps nowhere (no layout shift). */
export const TabsList = React.forwardRef<
  React.ComponentRef<typeof TabsPrimitive.List>,
  React.ComponentPropsWithoutRef<typeof TabsPrimitive.List>
>(function TabsList({ className, ...props }, ref) {
  return (
    <TabsPrimitive.List
      ref={ref}
      className={cn("no-scrollbar -mx-5 flex snap-x gap-2 overflow-x-auto px-5 py-1 sm:mx-0 sm:px-0", className)}
      {...props}
    />
  );
});

export const TabsTrigger = React.forwardRef<
  React.ComponentRef<typeof TabsPrimitive.Trigger>,
  React.ComponentPropsWithoutRef<typeof TabsPrimitive.Trigger>
>(function TabsTrigger({ className, ...props }, ref) {
  return (
    <TabsPrimitive.Trigger
      ref={ref}
      className={cn(
        "h-11 shrink-0 snap-start whitespace-nowrap rounded-full border border-white/15 px-5 text-xs font-semibold uppercase tracking-[0.14em] text-flour/75 transition-colors duration-200",
        "hover:border-white/30 hover:text-flour",
        "data-[state=active]:border-ember-500 data-[state=active]:bg-ember-500 data-[state=active]:text-ink-950",
        className,
      )}
      {...props}
    />
  );
});

export const TabsContent = React.forwardRef<
  React.ComponentRef<typeof TabsPrimitive.Content>,
  React.ComponentPropsWithoutRef<typeof TabsPrimitive.Content>
>(function TabsContent({ className, ...props }, ref) {
  return <TabsPrimitive.Content ref={ref} className={cn("mt-6 outline-none", className)} {...props} />;
});
