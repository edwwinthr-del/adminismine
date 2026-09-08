"use client";

import { createContext, useCallback, useContext, useEffect, useState } from "react";

/**
 * Whether the navigation rail is collapsed.
 *
 * Lifted out of the sidebar because two things drive it: the rail's own
 * collapse control, and the navbar's menu button — which is where the template
 * puts it. Keeping the state in the sidebar would have meant the navbar could
 * not reach it.
 *
 * The template re-collapses on every resize below its `xl` breakpoint. That is
 * kept, but only until someone chooses for themselves: being re-expanded on
 * every resize after deliberately collapsing is the half of that behaviour
 * nobody wants.
 */

/** MUI's `xl`, which is where the template switches to the mini rail. */
const MINI_BELOW = 1200;

const STORAGE_KEY = "gm_sidenav_mini";

interface SidenavContextValue {
  mini: boolean;
  toggle: () => void;
}

const SidenavContext = createContext<SidenavContextValue | null>(null);

export function SidenavProvider({ children }: { children: React.ReactNode }) {
  const [mini, setMini] = useState(false);
  /** Once someone has chosen, the viewport stops overriding them. */
  const [chosen, setChosen] = useState(false);

  useEffect(() => {
    try {
      const saved = window.localStorage.getItem(STORAGE_KEY);
      if (saved === "1" || saved === "0") {
        setMini(saved === "1");
        setChosen(true);

        return;
      }
    } catch {
      // Site data blocked; fall through to the viewport rule.
    }

    setMini(window.innerWidth < MINI_BELOW);
  }, []);

  useEffect(() => {
    if (chosen) return;

    const onResize = () => setMini(window.innerWidth < MINI_BELOW);
    window.addEventListener("resize", onResize);

    return () => window.removeEventListener("resize", onResize);
  }, [chosen]);

  const toggle = useCallback(() => {
    setChosen(true);
    setMini((was) => {
      const next = !was;
      try {
        window.localStorage.setItem(STORAGE_KEY, next ? "1" : "0");
      } catch {
        // Not remembered, still applied for this session.
      }

      return next;
    });
  }, []);

  return <SidenavContext.Provider value={{ mini, toggle }}>{children}</SidenavContext.Provider>;
}

export function useSidenav(): SidenavContextValue {
  const context = useContext(SidenavContext);

  if (context === null) {
    throw new Error("useSidenav must be used inside SidenavProvider");
  }

  return context;
}
