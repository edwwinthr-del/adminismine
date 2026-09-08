"use client";

import Link from "next/link";
import { useAuth } from "@/lib/auth/context";
import { useI18n } from "@/lib/i18n/context";
import { useCompanyProfile } from "@/lib/modules";
import { Card } from "@/components/ui/card";

/**
 * Ask, once, what shape of company this install is.
 *
 * A prompt rather than a redirect. An install with no profile works perfectly
 * well — every setting a profile would write has a sensible default — so there
 * is nothing here worth trapping somebody on a setup screen for. It disappears
 * as soon as a profile is applied.
 *
 * Only shown to whoever could act on it: telling a warehouse clerk the company
 * has not been set up is just noise on their dashboard.
 */
export function SetupPrompt() {
  const { t } = useI18n();
  const { hasPermission } = useAuth();
  const { profile, loaded } = useCompanyProfile();

  // Nothing until the answer is in: "not set up" and "not asked yet" look the
  // same from here, and guessing wrong flashes a setup prompt at a company that
  // has been running for a year.
  if (!loaded || profile !== null || !hasPermission("company.profile.manage")) return null;

  return (
    <Card className="flex flex-wrap items-center justify-between gap-3 p-4">
      <p className="text-sm text-zinc-600 dark:text-zinc-300">{t("setup.prompt")}</p>
      <Link
        href="/setup"
        className="rounded-full bg-ink px-4 py-1.5 text-sm font-medium text-brand-yellow"
      >
        {t("setup.promptAction")}
      </Link>
    </Card>
  );
}
