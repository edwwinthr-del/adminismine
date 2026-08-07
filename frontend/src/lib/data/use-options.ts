"use client";

import { useResource } from "./use-resource";

/**
 * The dropdown option lists that several modules all need.
 *
 * Seven pages independently fetched `/employees?status=active&per_page=200` and
 * four fetched `/worksites?active_only=1`, each from its own `useEffect` — so the
 * same rows were re-requested on every mount of every page. Going through
 * `useResource` makes them one cache entry per URL, shared and deduped across
 * every page and modal that asks (see lib/data/cache.ts), and still invalidated
 * by any write.
 *
 * Writing each URL exactly once also stops the copies drifting: "active workers"
 * has to mean the same thing on the housing page and the loans page.
 *
 * These preload a bounded list and are meant for short catalogues. A field that
 * needs to search a growing table should use `<AsyncSelect resource="…" />`
 * against `/api/lookups/{resource}` instead, which pages a query server-side.
 */

export interface EmployeeOption {
  id: number;
  full_name: string;
  job_role?: string | null;
}

export interface WorksiteOption {
  id: number;
  name: string;
}

export interface UserOption {
  id: number;
  name: string;
  email: string;
}

export interface NamedOption {
  id: number;
  name: string;
}

interface OptionsState<T> {
  options: T[];
  loading: boolean;
}

/**
 * A list that is safe to render straight into a `<select>`: it is never
 * undefined, and a failed request yields an empty list rather than an error the
 * caller has to handle — a dropdown that cannot load its choices should not take
 * the page down with it, which is what the `.catch(() => setX([]))` in each of
 * these effects was doing by hand.
 */
function options<T>(data: { data: T[] } | undefined, loading: boolean): OptionsState<T> {
  return { options: data?.data ?? [], loading };
}

/** Active workers, for assignment dropdowns. */
export function useEmployeeOptions(enabled = true): OptionsState<EmployeeOption> {
  const { data, loading } = useResource<{ data: EmployeeOption[] }>(
    "/employees?status=active&per_page=200",
    { enabled, keepAlive: true },
  );

  return options(data, loading);
}

/** Active worksites — where people clock in (mine → project → worksite). */
export function useWorksiteOptions(enabled = true): OptionsState<WorksiteOption> {
  const { data, loading } = useResource<{ data: WorksiteOption[] }>(
    "/worksites?active_only=1",
    { enabled, keepAlive: true },
  );

  return options(data, loading);
}

/**
 * Login accounts, for linking a master to the user who signs in as them.
 * Gate on `users.manage`: pass `false` and no request is made at all.
 */
export function useUserOptions(enabled = true): OptionsState<UserOption> {
  const { data, loading } = useResource<{ data: UserOption[] }>("/users?active_only=1", { enabled, keepAlive: true });

  return options(data, loading);
}

export function useSupplierOptions(enabled = true): OptionsState<NamedOption> {
  const { data, loading } = useResource<{ data: NamedOption[] }>("/suppliers?per_page=200", { enabled, keepAlive: true });

  return options(data, loading);
}

export function useClientOptions(enabled = true): OptionsState<NamedOption> {
  const { data, loading } = useResource<{ data: NamedOption[] }>("/clients?per_page=200", { enabled, keepAlive: true });

  return options(data, loading);
}
