"use client";

import { useEffect } from "react";
import { useCartStore } from "@/store/useCartStore";

/** Loads the persisted cart after mount (the store uses skipHydration so SSR and first paint agree). */
export function CartHydrator() {
  useEffect(() => {
    void useCartStore.persist.rehydrate();
  }, []);
  return null;
}
