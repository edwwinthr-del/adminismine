"use client";

import { useCallback, useEffect, useState } from "react";
import { ApiError, apiFetch } from "@/lib/api";
import { isStale, load, peek, subscribe, subscribeToWrites, STALE_AFTER_MS } from "./cache";

export interface ResourceState<T> {
  data: T | undefined;
  /** True only while there is nothing to show yet; a refresh keeps the old data visible. */
  loading: boolean;
  /** True while revalidating over data already on screen. */
  refreshing: boolean;
  error: string | null;
  /** When the data on screen was fetched — the answer to "is this current?". */
  updatedAt: number | null;
  refresh: () => Promise<void>;
}

interface Options {
  /** Skip the request entirely (e.g. the user lacks the permission behind it). */
  enabled?: boolean;
  /** How long a response is served before a background refetch. */
  staleAfter?: number;
  /** Refetch when the tab is focused again. On by default. */
  revalidateOnFocus?: boolean;
  /**
   * Keep the cached value when this component unmounts, instead of dropping it.
   *
   * For the small shared catalogues behind dropdowns, which several pages each
   * want and which would otherwise be re-fetched once per page per visit. Writes
   * still invalidate it. Not for a page's own filtered list — those should be
   * dropped, which is the default.
   */
  keepAlive?: boolean;
}

/**
 * Read an API path, cached and kept in sync.
 *
 * Three things it guarantees, which the hand-written `useEffect(load)` in each
 * page did not:
 *
 * - **After a write, it is right.** Any non-GET request anywhere in the app
 *   invalidates the cache, and every mounted resource refetches — which is what
 *   makes the dashboard agree with the module a record was just edited in.
 * - **Coming back to a tab shows current data.** Refocusing revalidates, so a
 *   screen left open overnight is not read as today's numbers.
 * - **It does not flicker.** A refresh keeps the previous data on screen and
 *   reports `refreshing`, instead of dropping back to a loading state.
 */
export function useResource<T>(path: string | null, options: Options = {}): ResourceState<T> {
  const {
    enabled = true,
    staleAfter = STALE_AFTER_MS,
    revalidateOnFocus = true,
    keepAlive = false,
  } = options;
  const key = enabled ? path : null;

  const [, forceRender] = useState(0);
  const [refreshing, setRefreshing] = useState(false);
  const rerender = useCallback(() => forceRender((tick) => tick + 1), []);

  const fetcher = useCallback((target: string) => apiFetch<T>(target), []);

  const run = useCallback(
    async (force: boolean) => {
      if (!key) return;

      const hadData = peek(key)?.data !== undefined;
      if (hadData) setRefreshing(true);

      try {
        await load<T>(key, fetcher, force);
      } catch {
        // Surfaced through the entry's `error`; rethrowing here would only
        // produce an unhandled rejection.
      } finally {
        setRefreshing(false);
        rerender();
      }
    },
    [key, fetcher, rerender],
  );

  useEffect(() => {
    if (!key) return;

    const unsubscribe = subscribe(key, rerender, keepAlive);

    if (isStale(key, staleAfter)) {
      void run(false);
    }

    return unsubscribe;
  }, [key, staleAfter, keepAlive, run, rerender]);

  // A write anywhere means what is on screen may no longer be true.
  useEffect(() => {
    if (!key) return;

    return subscribeToWrites(() => {
      void run(true);
    });
  }, [key, run]);

  useEffect(() => {
    if (!key || !revalidateOnFocus) return;

    const revalidate = () => {
      if (document.visibilityState === "visible" && isStale(key, staleAfter)) {
        void run(true);
      }
    };

    window.addEventListener("focus", revalidate);
    document.addEventListener("visibilitychange", revalidate);

    return () => {
      window.removeEventListener("focus", revalidate);
      document.removeEventListener("visibilitychange", revalidate);
    };
  }, [key, staleAfter, revalidateOnFocus, run]);

  const entry = key ? peek(key) : undefined;
  const error = entry?.error;

  return {
    data: entry?.data as T | undefined,
    loading: Boolean(key) && entry?.data === undefined && error === undefined,
    refreshing,
    error: error ? (error instanceof ApiError ? error.message : "Error") : null,
    updatedAt: entry?.fetchedAt ?? null,
    refresh: useCallback(() => run(true), [run]),
  };
}


/** Build `/path?a=1&b=2`, dropping empty values so the cache key stays stable. */
export function withQuery(path: string, params: Record<string, string | number | boolean | null | undefined>): string {
  const query = new URLSearchParams();

  // Sorted, so the same filters always produce the same key whatever order the
  // caller happened to list them in.
  for (const name of Object.keys(params).sort()) {
    const value = params[name];

    if (value === null || value === undefined || value === "" || value === false) continue;

    query.set(name, String(value === true ? 1 : value));
  }

  const suffix = query.toString();

  return suffix ? `${path}?${suffix}` : path;
}
