"use client";

/**
 * The last resort: a failure in the root layout itself, above every provider.
 *
 * This file *replaces* the root layout when it renders, so it has to bring its
 * own <html> and <body> — and it cannot use the app's i18n, theming or UI
 * primitives, because whatever it is standing in for is exactly what failed to
 * mount. That is why the copy here is English-only and the styling is inline,
 * which is the one place in the app where both are the right call rather than
 * an oversight. Everything inside the shell is handled by (app)/error.tsx,
 * which does speak all three languages.
 */
export default function GlobalError({
  error,
  unstable_retry,
}: {
  error: Error & { digest?: string };
  unstable_retry: () => void;
}) {
  return (
    <html lang="en">
      <body
        style={{
          margin: 0,
          minHeight: "100vh",
          display: "flex",
          alignItems: "center",
          justifyContent: "center",
          backgroundColor: "#fafaf9",
          color: "#18181b",
          fontFamily: "system-ui, -apple-system, Segoe UI, sans-serif",
          padding: "1.5rem",
        }}
      >
        <div style={{ maxWidth: "28rem", textAlign: "center" }}>
          <h1 style={{ fontSize: "1.5rem", fontWeight: 300, margin: "0 0 0.75rem" }}>
            AdminisMine could not start
          </h1>
          <p style={{ fontSize: "0.875rem", color: "#52525b", margin: "0 0 1.25rem" }}>
            The application failed to load. Nothing you entered has been changed.
          </p>
          <button
            type="button"
            onClick={() => unstable_retry()}
            style={{
              height: "2.5rem",
              padding: "0 1.25rem",
              borderRadius: "9999px",
              border: "none",
              backgroundColor: "#d5cd14",
              color: "#0d0c0b",
              fontSize: "0.875rem",
              fontWeight: 500,
              cursor: "pointer",
            }}
          >
            Try again
          </button>
          {error.digest ? (
            <p style={{ marginTop: "1.25rem", fontSize: "0.75rem", color: "#a1a1aa" }}>
              Reference: {error.digest}
            </p>
          ) : null}
        </div>
      </body>
    </html>
  );
}
