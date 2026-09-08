"use client";

import { useEffect } from "react";
import { useResource } from "@/lib/data/use-resource";
import { useI18n, type TerminologyOverrides } from "@/lib/i18n/context";

/**
 * Loads the company's own wording into the dictionary.
 *
 * It lives inside the authenticated shell rather than in `I18nProvider` because
 * terminology is company configuration: the login screen has no company yet, and
 * a fetch from there would be a 401 on every page load.
 *
 * Renders nothing. `useResource` caches and revalidates it like any other read,
 * so saving a term in Settings re-renders every screen with the new word without
 * a reload — `apiFetch` invalidates the cache after the write.
 */
export function TerminologyLoader() {
  const { setOverrides } = useI18n();
  const { data } = useResource<{ data: TerminologyOverrides }>("/company-settings/terminology", {
    keepAlive: true,
  });

  const overrides = data?.data;

  useEffect(() => {
    if (overrides) setOverrides(overrides);
  }, [overrides, setOverrides]);

  return null;
}
