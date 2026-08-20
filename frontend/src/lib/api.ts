import { notifySessionExpired } from "./auth/session";
import { clearCache, markMutated } from "./data/cache";
import { clearToken, getToken } from "./token";

const BASE = process.env.NEXT_PUBLIC_API_URL ?? "http://localhost:8000/api";

export class ApiError extends Error {
  status: number;
  errors?: Record<string, string[]>;

  constructor(status: number, message: string, errors?: Record<string, string[]>) {
    super(message);
    this.status = status;
    this.errors = errors;
  }
}

/**
 * The message to show a user for a failed request.
 *
 * Prefers the first field error, because that is the one that names the value
 * the server actually refused ("Amount exceeds the remaining balance (400.00)")
 * rather than the generic "The given data was invalid."
 */
export function errorMessage(error: unknown, fallback = "Error"): string {
  if (!(error instanceof ApiError)) return fallback;

  const first = error.errors ? Object.values(error.errors)[0]?.[0] : undefined;

  return first ?? error.message;
}

/**
 * End the session the server has just refused.
 *
 * All three steps matter and only the first was happening: drop the credential,
 * drop everything it fetched (otherwise the previous user's payables and
 * dashboard stay on screen, served from cache), and tell the auth provider so
 * the shell returns to the login screen instead of rendering an app whose every
 * request now fails.
 */
function endSession(): void {
  clearToken();
  clearCache();
  notifySessionExpired();
}

interface RequestOptions extends Omit<RequestInit, "body"> {
  /** When provided, the value is JSON-encoded and the content-type header is set. */
  json?: unknown;
  body?: BodyInit | null;
  /**
   * Whether a successful write invalidates the read cache. On by default, and it
   * should stay on for anything the user asked for — that rule is what keeps the
   * dashboard agreeing with the module a record was just edited in.
   *
   * The exception it exists for is the background notification scan, which runs
   * on a timer rather than because anyone did something. It reports whether it
   * changed anything and calls `markMutated()` itself when it did, so a scan that
   * finds nothing no longer refetches every mounted screen every few minutes.
   */
  revalidate?: boolean;
}

export async function apiFetch<T = unknown>(path: string, options: RequestOptions = {}): Promise<T> {
  const { json, headers, body, revalidate = true, ...rest } = options;
  const token = getToken();
  const method = (rest.method ?? "GET").toUpperCase();

  const response = await fetch(`${BASE}${path}`, {
    ...rest,
    headers: {
      Accept: "application/json",
      ...(json !== undefined ? { "Content-Type": "application/json" } : {}),
      ...(token ? { Authorization: `Bearer ${token}` } : {}),
      ...headers,
    },
    body: json !== undefined ? JSON.stringify(json) : body,
  });

  if (response.status === 401) {
    endSession();
  }

  const contentType = response.headers.get("content-type") ?? "";
  const data = contentType.includes("application/json") ? await response.json() : null;

  if (!response.ok) {
    const message =
      (data && typeof data.message === "string" && data.message) || `Request failed (${response.status})`;
    throw new ApiError(response.status, message, data?.errors);
  }

  // Every successful write invalidates the read cache. Doing it here, rather
  // than at each call site, is what stops the dashboard (and any other screen
  // derived from the same records) from drifting out of date after an edit
  // made somewhere else — including in modules written later.
  if (method !== "GET" && method !== "HEAD" && revalidate) {
    markMutated();
  }

  return data as T;
}

/**
 * Fetch a file from the API. Private files are served through authenticated
 * routes, so a plain <a href> would not carry the bearer token.
 */
export async function apiDownload(path: string): Promise<{ blob: Blob; filename: string | null }> {
  const token = getToken();

  const response = await fetch(`${BASE}${path}`, {
    headers: {
      ...(token ? { Authorization: `Bearer ${token}` } : {}),
    },
  });

  if (response.status === 401) {
    endSession();
  }

  if (!response.ok) {
    throw new ApiError(response.status, `Download failed (${response.status})`);
  }

  const disposition = response.headers.get("content-disposition") ?? "";
  const match = /filename\*?=(?:UTF-8'')?"?([^";]+)"?/i.exec(disposition);

  return { blob: await response.blob(), filename: match ? decodeURIComponent(match[1]) : null };
}

/** Save a fetched file to disk through a temporary object URL. */
export async function downloadToDisk(path: string, fallbackName: string): Promise<void> {
  const { blob, filename } = await apiDownload(path);
  const url = URL.createObjectURL(blob);
  const link = document.createElement("a");

  link.href = url;
  link.download = filename ?? fallbackName;
  document.body.appendChild(link);
  link.click();
  link.remove();
  URL.revokeObjectURL(url);
}
