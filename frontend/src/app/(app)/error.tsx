"use client";

import { useEffect } from "react";
import Link from "next/link";
import { Button } from "@/components/ui/button";
import { Card } from "@/components/ui/card";
import { useI18n } from "@/lib/i18n/context";

/**
 * The fallback for any screen inside the app shell that throws while rendering.
 *
 * The app had no error boundary of any kind, so a single unrenderable value took
 * the whole page down to a blank white screen with no way back — and the value
 * did not have to be exotic: a bank movement whose currency code was not a real
 * currency made `Intl.NumberFormat` throw, which white-screened both /bank and
 * /dashboard for every user, unfixable from the UI because the UI was gone.
 * That particular cause is fixed server-side now, but the missing floor under
 * every other screen was the more serious half.
 *
 * `unstable_retry` is Next 16's rename of `reset`: it re-fetches and re-renders
 * the segment rather than only clearing the error state, which is what a
 * transient failure actually needs.
 */
export default function AppError({
  error,
  unstable_retry,
}: {
  error: Error & { digest?: string };
  unstable_retry: () => void;
}) {
  const { t } = useI18n();

  useEffect(() => {
    // The digest is the only handle on the server-side detail, so it goes to the
    // console where a developer can pair it with the log.
    console.error(error);
  }, [error]);

  return (
    <div className="flex min-h-[60vh] items-center justify-center p-6">
      <Card raised className="max-w-md space-y-4 text-center">
        <h1 className="text-2xl font-light text-zinc-900 dark:text-zinc-50">{t("error.title")}</h1>
        <p className="text-sm text-zinc-600 dark:text-zinc-300">{t("error.body")}</p>

        <div className="flex flex-wrap justify-center gap-2 pt-1">
          <Button onClick={() => unstable_retry()}>{t("error.retry")}</Button>
          <Link href="/dashboard">
            <Button variant="secondary">{t("error.home")}</Button>
          </Link>
        </div>

        {error.digest ? (
          <p className="pt-1 font-mono text-xs text-zinc-400">
            {t("error.reference")}: {error.digest}
          </p>
        ) : null}
      </Card>
    </div>
  );
}
