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

  const [email, setEmail] = useState("superadmin@test.test");
  const [password, setPassword] = useState("password");
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

  return (
    <div className="app-ambient flex min-h-screen items-center justify-center p-4">
      <div className="w-full max-w-sm">
        <div className="mb-7 flex items-center justify-center gap-3">
          <span className="grid h-11 w-11 place-items-center rounded-2xl bg-ink text-brand-yellow">
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
            <div>
              <label className="mb-1.5 block px-4 text-[11px] font-medium uppercase tracking-[0.08em] text-zinc-500">
                {t("login.email")}
              </label>
              <Input
                type="email"
                value={email}
                onChange={(e) => setEmail(e.target.value)}
                autoComplete="username"
                required
              />
            </div>
            <div>
              <label className="mb-1.5 block px-4 text-[11px] font-medium uppercase tracking-[0.08em] text-zinc-500">
                {t("login.password")}
              </label>
              <Input
                type="password"
                value={password}
                onChange={(e) => setPassword(e.target.value)}
                autoComplete="current-password"
                required
              />
            </div>
            {error && <p className="px-4 text-sm text-red-600">{error}</p>}
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
  );
}
