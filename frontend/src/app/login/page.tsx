"use client";

import { useEffect, useState } from "react";
import { useRouter } from "next/navigation";
import { ApiError } from "@/lib/api";
import { useAuth } from "@/lib/auth/context";
import { useI18n } from "@/lib/i18n/context";
import { LOCALE_LABELS } from "@/lib/i18n/dictionaries";
import { Button } from "@/components/ui/button";
import { Card } from "@/components/ui/card";
import { Input } from "@/components/ui/input";

export default function LoginPage() {
  const { login, user, loading } = useAuth();
  const { t, locale, locales, setLocale } = useI18n();
  const router = useRouter();

  /*
   * Empty, not pre-filled. These fields used to ship the seeded Super Admin's
   * email and password — the ones in the README — straight into the production
   * bundle, so anyone who opened /login was one click from full access if that
   * account still had its default password. Convenience during the first week
   * of development; a handed-over credential afterwards.
   */
  const [email, setEmail] = useState("");
  const [password, setPassword] = useState("");
  const [error, setError] = useState<string | null>(null);
  const [submitting, setSubmitting] = useState(false);

  useEffect(() => {
    if (!loading && user) router.replace("/dashboard");
  }, [loading, user, router]);

  async function onSubmit(e: React.FormEvent) {
    e.preventDefault();
    setSubmitting(true);
    setError(null);
    try {
      await login(email, password);
      router.replace("/dashboard");
    } catch (err) {
      setError(err instanceof ApiError ? err.message : t("login.error"));
    } finally {
      setSubmitting(false);
    }
  }

  /*
   * The template's CoverLayout: a poster panel holding half the screen with the
   * product name set in wide letter-spaced caps, and the form on the other half.
   * Below `lg` the panel is dropped entirely and the form centres — which is
   * what the template does too, rather than stacking a decorative panel above a
   * login form on a phone.
   */
  return (
    <div className="app-ambient flex min-h-screen">
      <aside
        className="relative hidden w-1/2 shrink-0 flex-col items-center justify-center overflow-hidden p-10 lg:flex"
        style={{ background: "var(--vui-cover)" }}
        aria-hidden
      >
        <span className="mb-8 grid h-16 w-16 place-items-center rounded-[var(--vui-r-xl)] bg-brand-yellow text-ink shadow-[var(--vui-shadow-lg)]">
          <svg viewBox="0 0 24 24" className="h-9 w-9" fill="currentColor">
            <path d="M4 3h16a8 8 0 0 1-8 8 8 8 0 0 1-8-8Z" />
            <path d="M20 21H4a8 8 0 0 1 8-8 8 8 0 0 1 8 8Z" />
          </svg>
        </span>

        <p className="text-center text-sm font-medium tracking-[0.5em] text-zinc-400">
          {t("login.premotto")}
        </p>
        <p className="mt-3 text-center text-4xl font-bold tracking-[0.32em] text-brand-yellow">
          ADMINISMINE
        </p>
      </aside>

      <div className="flex flex-1 items-center justify-center p-4">
        <div className="w-full max-w-sm">
          {/* The mark rides with the form once the poster panel is gone. */}
          <div className="mb-7 flex items-center justify-center gap-3 lg:hidden">
            <span className="grid h-11 w-11 place-items-center rounded-[var(--vui-r-button)] bg-ink text-brand-yellow">
              <svg viewBox="0 0 24 24" className="h-6 w-6" fill="currentColor" aria-hidden>
                <path d="M4 3h16a8 8 0 0 1-8 8 8 8 0 0 1-8-8Z" />
                <path d="M20 21H4a8 8 0 0 1 8-8 8 8 0 0 1 8 8Z" />
              </svg>
            </span>
            <span className="text-[1.75rem] font-light tracking-tight text-zinc-900 dark:text-zinc-50">
              AdminisMine
            </span>
          </div>

          <Card raised className="p-7">
            <h1 className="text-2xl font-light tracking-tight text-zinc-900 dark:text-zinc-50">
              {t("login.title")}
            </h1>
            <p className="mt-1.5 text-sm text-zinc-500">{t("login.subtitle")}</p>
            <form onSubmit={onSubmit} className="mt-6 space-y-4">
              <Field label={t("login.email")}>
                <Input
                  type="email"
                  value={email}
                  onChange={(e) => setEmail(e.target.value)}
                  autoComplete="username"
                  required
                />
              </Field>
              <Field label={t("login.password")}>
                <Input
                  type="password"
                  value={password}
                  onChange={(e) => setPassword(e.target.value)}
                  autoComplete="current-password"
                  required
                />
              </Field>
              {error && <p className="text-sm text-red-600">{error}</p>}
              <Button type="submit" className="w-full" disabled={submitting}>
                {submitting ? t("login.signingIn") : t("login.signIn")}
              </Button>
            </form>
          </Card>

          <div className="mt-5 flex justify-center gap-1.5">
            {locales.map((l) => (
              <button
                key={l}
                onClick={() => setLocale(l)}
                aria-pressed={l === locale}
                className={
                  l === locale
                    ? "rounded-full bg-ink px-3 py-1 text-xs font-medium text-zinc-50"
                    : "rounded-full px-3 py-1 text-xs text-zinc-500 transition-colors hover:bg-white/60 dark:hover:bg-white/10"
                }
              >
                {LOCALE_LABELS[l]}
              </button>
            ))}
          </div>
        </div>
      </div>
    </div>
  );
}

/**
 * A labelled field, wrapped the way the template wraps its sign-in inputs.
 *
 * Vision UI puts every auth field inside a `GradientBorder` — a 1px radial
 * gradient that is bright along the middle of each edge and fades at the
 * corners. `.vui-edge` is the same effect drawn as a mask ring, so the wrapper
 * is a plain div rather than a second component.
 */
function Field({ label, children }: { label: string; children: React.ReactNode }) {
  return (
    <div>
      <label className="mb-1.5 block px-1 text-[11px] font-medium uppercase tracking-[0.08em] text-zinc-500">
        {label}
      </label>
      <div className="vui-edge rounded-[var(--vui-r-lg)]">{children}</div>
    </div>
  );
}
