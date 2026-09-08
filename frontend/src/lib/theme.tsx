"use client";

import { createContext, useCallback, useContext, useEffect, useState } from "react";

/**
 * Light, dark, or whatever the machine is set to.
 *
 * Both palettes have been in `globals.css` from the start; what was missing was
 * a way to disagree with the operating system. On a laptop set to dark the app
 * had exactly one theme and no control that admitted the other existed.
 *
 * `system` is the default and stays the default — it is the right answer for
 * most people most of the time, and it follows the OS live rather than only at
 * load. Choosing light or dark is a deliberate override that outlives the
 * session.
 */
export type ThemePreference = "system" | "light" | "dark";

/** What actually gets painted, once `system` has been resolved. */
export type ResolvedTheme = "light" | "dark";

export const THEME_PREFERENCES: ThemePreference[] = ["system", "light", "dark"];

/** Shared with the inline script in `layout.tsx` — both must agree. */
export const THEME_STORAGE_KEY = "gm_theme";

interface ThemeContextValue {
  /** What the user chose. */
  preference: ThemePreference;
  /** What that resolves to right now. */
  theme: ResolvedTheme;
  setPreference: (next: ThemePreference) => void;
}

const ThemeContext = createContext<ThemeContextValue | null>(null);

function systemTheme(): ResolvedTheme {
  if (typeof window === "undefined") return "light";

  return window.matchMedia("(prefers-color-scheme: dark)").matches ? "dark" : "light";
}

function storedPreference(): ThemePreference {
  if (typeof window === "undefined") return "system";

  try {
    const saved = window.localStorage.getItem(THEME_STORAGE_KEY);

    return saved === "light" || saved === "dark" ? saved : "system";
  } catch {
    // Private mode, or site data blocked. The default is a fine answer.
    return "system";
  }
}

/**
 * Applies the theme to `<html>`.
 *
 * `data-theme` is always a resolved `light` or `dark`, never `system` and never
 * absent — that is what lets `globals.css` carry one dark block instead of an
 * attribute rule and a `prefers-color-scheme` rule that could drift apart.
 */
function paint(theme: ResolvedTheme) {
  document.documentElement.dataset.theme = theme;
}

export function ThemeProvider({ children }: { children: React.ReactNode }) {
  /*
   * Seeded from what the inline script already put on <html>, not from a
   * hardcoded default: the script ran before paint, so re-deriving here would
   * mean one render at the wrong theme on every load.
   */
  const [preference, setPreferenceState] = useState<ThemePreference>("system");
  const [theme, setTheme] = useState<ResolvedTheme>("light");

  useEffect(() => {
    const saved = storedPreference();
    setPreferenceState(saved);
    setTheme(saved === "system" ? systemTheme() : saved);
  }, []);

  // Following the OS means following it while the app is open — someone whose
  // machine flips at sunset should not have to reload.
  useEffect(() => {
    if (preference !== "system") return;

    const query = window.matchMedia("(prefers-color-scheme: dark)");
    const onChange = () => {
      const next = query.matches ? "dark" : "light";
      setTheme(next);
      paint(next);
    };

    query.addEventListener("change", onChange);

    return () => query.removeEventListener("change", onChange);
  }, [preference]);

  const setPreference = useCallback((next: ThemePreference) => {
    setPreferenceState(next);

    const resolved = next === "system" ? systemTheme() : next;
    setTheme(resolved);
    paint(resolved);

    try {
      // "system" is stored as the absence of a choice, so there is one
      // representation of "we have not chosen" rather than two.
      if (next === "system") {
        window.localStorage.removeItem(THEME_STORAGE_KEY);
      } else {
        window.localStorage.setItem(THEME_STORAGE_KEY, next);
      }
    } catch {
      // The theme still applies for this session; it just will not be
      // remembered. Not worth failing a click over.
    }
  }, []);

  return (
    <ThemeContext.Provider value={{ preference, theme, setPreference }}>
      {children}
    </ThemeContext.Provider>
  );
}

export function useTheme(): ThemeContextValue {
  const context = useContext(ThemeContext);

  if (context === null) {
    throw new Error("useTheme must be used inside ThemeProvider");
  }

  return context;
}
