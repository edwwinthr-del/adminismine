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
    <div className="flex min-h-screen items-center justify-center bg-zinc-100 p-4 dark:bg-zinc-950">
      <div className="w-full max-w-sm">
        <div className="mb-6 flex items-center justify-center gap-2">
          <div className="h-9 w-9 rounded-md bg-indigo-600" />
          <span className="text-xl font-semibold text-zinc-900 dark:text-zinc-50">AdminisMine</span>
        </div>
        <Card>
          <h1 className="text-lg font-semibold text-zinc-900 dark:text-zinc-50">{t("login.title")}</h1>
          <p className="mt-1 text-sm text-zinc-500">{t("login.subtitle")}</p>
          <form onSubmit={onSubmit} className="mt-5 space-y-4">
            <div>
              <label className="mb-1 block text-sm font-medium text-zinc-700 dark:text-zinc-300">
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
              <label className="mb-1 block text-sm font-medium text-zinc-700 dark:text-zinc-300">
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
            {error && <p className="text-sm text-red-600">{error}</p>}
            <Button type="submit" className="w-full" disabled={submitting}>
              {submitting ? t("login.signingIn") : t("login.signIn")}
            </Button>
          </form>
        </Card>
        <div className="mt-4 flex justify-center gap-3">
          {locales.map((l) => (
            <button
              key={l}
              onClick={() => setLocale(l)}
              className={l === locale ? "text-xs font-semibold text-indigo-600" : "text-xs text-zinc-500"}
            >
              {LOCALE_LABELS[l]}
            </button>
          ))}
        </div>
      </div>
    </div>
  );
}
