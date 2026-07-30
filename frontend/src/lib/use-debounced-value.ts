"use client";

import { useEffect, useState } from "react";

/**
 * How long a live search waits after the last keystroke. Long enough that
 * typing a supplier name is one request rather than fifteen, short enough that
 * the list still feels like it is following along.
 */
export const SEARCH_DEBOUNCE_MS = 300;

/**
 * The value, but only after it has stopped changing.
 *
 * Every search box in the app filters server-side, so binding the request to
 * the raw input state means one query per keystroke — the requests then race,
 * and a slow early one can land after a fast later one and show the wrong
 * results. Debouncing removes both problems at the source.
 *
 * The input itself stays bound to the immediate value, so typing is never laggy.
 */
export function useDebouncedValue<T>(value: T, delay = SEARCH_DEBOUNCE_MS): T {
  const [debounced, setDebounced] = useState(value);

  const isEmpty = value === "" || value === null || value === undefined;

  /*
   * An empty search is a return to the unfiltered list: apply it at once rather
   * than making the user wait to see everything again.
   *
   * This is a render-phase update, not an effect. An effect would paint the old
   * results once before clearing them, and — the reason the linter objects —
   * every such write costs a second render pass. React re-runs this component
   * instead, before anything reaches the screen; the guard means it can only
   * happen when the two have actually drifted apart, so it cannot loop.
   */
  if (isEmpty && debounced !== value) {
    setDebounced(value);
  }

  useEffect(() => {
    if (isEmpty) return;

    const timer = setTimeout(() => setDebounced(value), delay);

    return () => clearTimeout(timer);
  }, [value, delay, isEmpty]);

  return debounced;
}
