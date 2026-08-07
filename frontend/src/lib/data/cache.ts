/**
 * A small read-through cache for GET requests, with one rule that keeps every
 * screen honest: **anything that writes invalidates everything that reads.**
 *
 * The problem this solves is the dashboard going stale. Its numbers are derived
 * from records that are created and edited on a dozen other pages, so any
 * hand-maintained "this mutation affects these widgets" map would be wrong the
 * first time a module was added. Instead `apiFetch` reports every non-GET
 * request here, the cache generation moves, and whatever is on screen refetches.
 * Screens that are not mounted are simply dropped, so the cost is bounded by
 * what the user is actually looking at rather than by how much they have visited.
 *
 * Deliberately not a general-purpose query library: no mutations-as-data, no
 * optimistic writes, no infinite queries. It exists to make "what is on screen
 * matches the database" true by construction.
 */

/** How long a cached response is served without a background refetch. */
export const STALE_AFTER_MS = 30_000;

type Listener = () => void;

interface Entry {
  data?: unknown;
  error?: unknown;
  /** In-flight request, so N components asking for one key make one call. */
  promise?: Promise<unknown>;
  fetchedAt: number;
  generation: number;
  listeners: Set<Listener>;
  /**
   * Survive having no listeners, instead of being dropped on unmount.
   *
   * Dropping is right for the big, filtered lists each page owns: they are large,
   * specific to filters the user has since left behind, and cheap to be wrong
   * about. It is wrong for the small shared catalogues — the active workers, the
   * active worksites — that a dozen pages all put in a dropdown. Those were
   * re-fetched once per page, on every visit, because navigating away always
   * unmounted the last listener.
   *
   * This is not a second staleness rule: a kept entry is still invalidated by
   * `markMutated`, so the next mount refetches it. It only stops the entry being
   * thrown away between one page and the next.
   */
  keepAlive?: boolean;
}

const entries = new Map<string, Entry>();

/**
 * Bumped by every write. An entry from an older generation is stale no matter
 * how recently it was fetched.
 */
let generation = 0;

const generationListeners = new Set<Listener>();

function entryFor(key: string): Entry {
  let entry = entries.get(key);

  if (!entry) {
    entry = { fetchedAt: 0, generation: -1, listeners: new Set() };
    entries.set(key, entry);
  }

  return entry;
}

export function peek(key: string): { data?: unknown; error?: unknown; fetchedAt: number } | undefined {
  const entry = entries.get(key);

  return entry && entry.fetchedAt > 0 ? { data: entry.data, error: entry.error, fetchedAt: entry.fetchedAt } : undefined;
}

export function isStale(key: string, staleAfter = STALE_AFTER_MS): boolean {
  const entry = entries.get(key);

  if (!entry || entry.fetchedAt === 0) return true;

  return entry.generation !== generation || Date.now() - entry.fetchedAt > staleAfter;
}

export function subscribe(key: string, listener: Listener, keepAlive = false): () => void {
  const entry = entryFor(key);
  entry.listeners.add(listener);

  if (keepAlive) entry.keepAlive = true;

  return () => {
    entry.listeners.delete(listener);

    // Nothing is watching this key any more. Keeping it would only serve a
    // stale first paint the next time the page is opened — unless it is one of
    // the shared catalogues, which are worth holding on to between pages.
    if (entry.listeners.size === 0 && !entry.promise && !entry.keepAlive) {
      entries.delete(key);
    }
  };
}

/** Notified when a write happens, so mounted resources can revalidate. */
export function subscribeToWrites(listener: Listener): () => void {
  generationListeners.add(listener);

  return () => {
    generationListeners.delete(listener);
  };
}

export function currentGeneration(): number {
  return generation;
}

/**
 * Fetch a key, reusing an in-flight request for the same key.
 *
 * @param force ignore the cached value and go to the network
 */
export function load<T>(key: string, fetcher: (key: string) => Promise<T>, force = false): Promise<T> {
  const entry = entryFor(key);

  if (entry.promise) {
    if (!force) {
      return entry.promise as Promise<T>;
    }

    // A write landed while this request was in flight, so its answer may predate
    // the change. Wait for it, then ask again — otherwise the screen would settle
    // on data that is already known to be stale.
    return entry.promise
      .catch(() => undefined)
      .then(() => load(key, fetcher, true)) as Promise<T>;
  }

  if (!force && !isStale(key)) {
    return Promise.resolve(entry.data as T);
  }

  const startedAt = generation;

  const promise = fetcher(key)
    .then((data) => {
      entry.data = data;
      entry.error = undefined;
      entry.fetchedAt = Date.now();
      // Stamped with the generation the request *started* in: a write that
      // landed mid-flight leaves this result stale, which is correct — it may
      // not include the change.
      entry.generation = startedAt;

      return data;
    })
    .catch((error: unknown) => {
      entry.error = error;
      entry.fetchedAt = Date.now();
      entry.generation = startedAt;

      throw error;
    })
    .finally(() => {
      entry.promise = undefined;
      entry.listeners.forEach((listener) => listener());
    });

  entry.promise = promise;

  return promise as Promise<T>;
}

/**
 * Record that something was written. Every mounted resource revalidates and
 * every unmounted one is discarded.
 *
 * Called from `apiFetch` for any non-GET request, so a new module gets correct
 * refresh behaviour without having to remember to ask for it.
 */
export function markMutated(): void {
  generation += 1;

  for (const [key, entry] of entries) {
    // A kept entry stays, but the generation bump above has already made it
    // stale, so the next component to mount on it refetches. Holding the data
    // is never how a screen goes out of date; serving it without revalidating
    // would be.
    if (entry.listeners.size === 0 && !entry.promise && !entry.keepAlive) {
      entries.delete(key);
    }
  }

  generationListeners.forEach((listener) => listener());
}

/** Drop everything. Used on sign-out so the next user starts clean. */
export function clearCache(): void {
  entries.clear();
  generation += 1;
  generationListeners.forEach((listener) => listener());
}
