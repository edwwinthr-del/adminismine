"use client";

import { useAuth } from "@/lib/auth/context";
import { useI18n } from "@/lib/i18n/context";
import { LOCALE_LABELS, type Locale } from "@/lib/i18n/dictionaries";
import { NotificationBell } from "./notification-bell";

export function Topbar() {
  const { locale, locales, setLocale, t } = useI18n();
  const { user, logout, setLocaleRemote } = useAuth();

  function onLocaleChange(next: Locale) {
    setLocale(next);
    void setLocaleRemote(next);
  }

  return (
    <header className="flex h-16 items-center justify-end gap-3 border-b border-zinc-200 bg-white px-4 md:px-6 dark:border-zinc-800 dark:bg-zinc-950">
      <select
        aria-label={t("common.language")}
        value={locale}
        onChange={(e) => onLocaleChange(e.target.value as Locale)}
        className="h-9 rounded-md border border-zinc-300 bg-white px-2 text-sm dark:border-zinc-700 dark:bg-zinc-900 dark:text-zinc-100"
      >
        {locales.map((l) => (
          <option key={l} value={l}>
            {LOCALE_LABELS[l]}
          </option>
        ))}
      </select>

      <NotificationBell />

      <div className="hidden text-right leading-tight sm:block">
        <div className="text-sm font-medium text-zinc-800 dark:text-zinc-100">{user?.name}</div>
        <div className="text-xs text-zinc-500">{user?.roles?.[0]}</div>
      </div>

      <button
        onClick={() => void logout()}
        className="text-sm font-medium text-zinc-600 transition-colors hover:text-red-600 dark:text-zinc-300"
      >
        {t("common.logout")}
      </button>
    </header>
  );
}
