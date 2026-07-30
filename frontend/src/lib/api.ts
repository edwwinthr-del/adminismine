import { markMutated } from "./data/cache";
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

interface RequestOptions extends Omit<RequestInit, "body"> {
  /** When provided, the value is JSON-encoded and the content-type header is set. */
  json?: unknown;
  body?: BodyInit | null;
}

export async function apiFetch<T = unknown>(path: string, options: RequestOptions = {}): Promise<T> {
  const { json, headers, body, ...rest } = options;
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
    clearToken();
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
  if (method !== "GET" && method !== "HEAD") {
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
    clearToken();
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
