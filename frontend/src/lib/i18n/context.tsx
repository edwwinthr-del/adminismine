"use client";

import { createContext, useCallback, useContext, useEffect, useMemo, useState } from "react";
import { dictionaries, LOCALES, type Locale } from "./dictionaries";

/** What this company calls things, by term then language. */
export type TerminologyOverrides = Record<string, Partial<Record<Locale, string>>>;

interface I18nValue {
  locale: Locale;
  locales: Locale[];
  setLocale: (locale: Locale) => void;
  t: (key: string, vars?: Record<string, string | number>) => string;
  /**
   * The company's own wording for the domain nouns, layered over the built-in
   * dictionary. Loaded inside the authenticated shell (see `TerminologyLoader`)
   * rather than here, because it is company configuration and the login screen
   * has no company yet.
   */
  overrides: TerminologyOverrides;
  setOverrides: (overrides: TerminologyOverrides) => void;
}

const I18nContext = createContext<I18nValue | null>(null);
const STORAGE_KEY = "gm_locale";

export function I18nProvider({ children }: { children: React.ReactNode }) {
  const [locale, setLocaleState] = useState<Locale>("en");
  const [overrides, setOverrides] = useState<TerminologyOverrides>({});

  useEffect(() => {
    const saved = window.localStorage.getItem(STORAGE_KEY) as Locale | null;
    if (saved && LOCALES.includes(saved)) {
      setLocaleState(saved);
    }
  }, []);

  useEffect(() => {
    document.documentElement.lang = locale;
  }, [locale]);

  const setLocale = useCallback((next: Locale) => {
    setLocaleState(next);
    window.localStorage.setItem(STORAGE_KEY, next);
  }, []);

  const t = useCallback(
    (key: string, vars?: Record<string, string | number>) => {
      /*
       * The company's own word first, then this language, then English, then the
       * key itself. An override is per language, so renaming a term in Serbian
       * only leaves English and Turkish reading the built-in wording — which is
       * the honest result, not a fallback to somebody else's language.
       */
      let value = overrides[key]?.[locale] ?? dictionaries[locale][key] ?? dictionaries.en[key] ?? key;

      if (vars) {
        for (const [name, replacement] of Object.entries(vars)) {
          value = value.replace(`{${name}}`, String(replacement));
        }
      }
      return value;
    },
    [locale, overrides],
  );

  const value = useMemo(
    () => ({ locale, locales: LOCALES, setLocale, t, overrides, setOverrides }),
    [locale, setLocale, t, overrides],
  );

  return <I18nContext.Provider value={value}>{children}</I18nContext.Provider>;
}

export function useI18n(): I18nValue {
  const ctx = useContext(I18nContext);
  if (!ctx) throw new Error("useI18n must be used within I18nProvider");
  return ctx;
}
