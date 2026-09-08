"use client";

import { useResource } from "@/lib/data/use-resource";

interface CompanyShape {
  enabled_modules: string[] | null;
  work_structure_levels: string[] | null;
  profile: string | null;
  /**
   * Whether the answer has actually arrived.
   *
   * Without this, "no profile yet" and "still asking" are the same value, and
   * anything keyed on the difference — the setup prompt — fires for a moment on
   * every page load of a company that is perfectly well set up.
   */
  loaded: boolean;
}

/**
 * What this install actually is: which modules it has, and how deep its work
 * structure goes.
 *
 * Read from `/company-settings`, which the shell already fetches for the company
 * name — `useResource` dedupes it, so knowing this costs no extra request. Null
 * means everything: an install that never opened the toggles, and a module added
 * in a later release, are both on rather than silently missing.
 */
function useCompanyShape(): CompanyShape {
  const { data } = useResource<{ data: CompanyShape }>("/company-settings", { keepAlive: true });

  return {
    enabled_modules: data?.data.enabled_modules ?? null,
    work_structure_levels: data?.data.work_structure_levels ?? null,
    profile: data?.data.profile ?? null,
    loaded: data !== undefined,
  };
}

export function useModuleEnabled(): (module?: string) => boolean {
  const { enabled_modules: enabled } = useCompanyShape();

  // An item with no module belongs to the core and is always shown.
  return (module?: string) => module === undefined || enabled === null || enabled.includes(module);
}

/**
 * Whether a level of mine → project → worksite is one this company uses.
 *
 * Display depth, never schema depth: all three levels exist in the database
 * whatever this says, so hiding one changes no record and switching it back on
 * finds everything where it was left.
 */
export function useStructureLevel(): (level?: string) => boolean {
  const { work_structure_levels: levels } = useCompanyShape();

  return (level?: string) =>
    level === undefined || levels === null || level === "worksite" || levels.includes(level);
}

/**
   * Which shape of company this install was set up as.
   *
   * Returns `loaded` alongside, because "not set up" and "not answered yet" are
   * different things and only one of them is worth telling somebody about.
   */
export function useCompanyProfile(): { profile: string | null; loaded: boolean } {
  const { profile, loaded } = useCompanyShape();

  return { profile, loaded };
}
