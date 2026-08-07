"use client";

import { useState } from "react";
import { useAuth } from "@/lib/auth/context";
import { useI18n } from "@/lib/i18n/context";
import { LOCALE_LABELS, type Locale } from "@/lib/i18n/dictionaries";
import { Select } from "@/components/ui/select";
import { ChangePasswordModal } from "./change-password-modal";
import { NotificationBell } from "./notification-bell";

/**
 * Floats on the page wash with no bar of its own, the way the reference puts
 * its controls straight onto the background. The account menu is a frosted pill
 * rather than a row of loose text links.
 */
export function Topbar() {
  const { locale, locales, setLocale, t } = useI18n();
  const { user, logout, setLocaleRemote } = useAuth();
  const [changingPassword, setChangingPassword] = useState(false);
  const [menuOpen, setMenuOpen] = useState(false);

  function onLocaleChange(next: Locale) {
    setLocale(next);
    void setLocaleRemote(next);
  }

  const initials = (user?.name ?? "")
    .split(" ")
    .filter(Boolean)
    .slice(0, 2)
    .map((part) => part[0]?.toUpperCase())
    .join("");

  return (
    <header className="flex h-20 items-center justify-end gap-2.5 px-4 md:px-6">
      <Select
        aria-label={t("common.language")}
        value={locale}
        onChange={(e) => onLocaleChange(e.target.value as Locale)}
        className="w-[8.5rem]"
      >
        {locales.map((l) => (
          <option key={l} value={l}>
            {LOCALE_LABELS[l]}
          </option>
        ))}
      </Select>

      <NotificationBell />

      <div className="relative">
        <button
          onClick={() => setMenuOpen((open) => !open)}
          onBlur={() => window.setTimeout(() => setMenuOpen(false), 150)}
          aria-expanded={menuOpen}
          aria-haspopup="menu"
          className="control-surface focus-ink flex h-10 items-center gap-2.5 rounded-full pl-1.5 pr-3 transition-colors hover:bg-white/80 dark:hover:bg-white/10"
        >
          <span className="grid h-7 w-7 shrink-0 place-items-center rounded-full bg-ink text-[11px] font-semibold text-brand-yellow">
            {initials || "?"}
          </span>
          <span className="hidden text-left leading-tight sm:block">
            <span className="block max-w-[10rem] truncate text-[13px] font-medium text-zinc-800 dark:text-zinc-100">
              {user?.name}
            </span>
            <span className="block max-w-[10rem] truncate text-[11px] text-zinc-500">{user?.roles?.[0]}</span>
          </span>
          <svg viewBox="0 0 24 24" className="h-3.5 w-3.5 text-zinc-500" fill="none" stroke="currentColor" strokeWidth="2">
            <path strokeLinecap="round" strokeLinejoin="round" d="M19 9l-7 7-7-7" />
          </svg>
        </button>

        {menuOpen && (
          <div
            role="menu"
            className="surface-strong absolute right-0 z-30 mt-2 w-56 overflow-hidden rounded-3xl p-1.5"
          >
            {/*
              Gated on nothing but being signed in. Every role reaches this,
              because an account's password belongs to the account holder — the
              reset on the Users screen is for someone who has already lost access.
            */}
            <button
              role="menuitem"
              onMouseDown={(e) => e.preventDefault()}
              onClick={() => {
                setMenuOpen(false);
                setChangingPassword(true);
              }}
              className="flex w-full items-center rounded-2xl px-3.5 py-2.5 text-left text-sm text-zinc-700 transition-colors hover:bg-zinc-900/5 dark:text-zinc-200 dark:hover:bg-white/10"
            >
              {t("account.changePassword")}
            </button>
            <button
              role="menuitem"
              onMouseDown={(e) => e.preventDefault()}
              onClick={() => void logout()}
              className="flex w-full items-center rounded-2xl px-3.5 py-2.5 text-left text-sm text-red-600 transition-colors hover:bg-red-500/10 dark:text-red-400"
            >
              {t("common.logout")}
            </button>
          </div>
        )}
      </div>

      {changingPassword && <ChangePasswordModal onClose={() => setChangingPassword(false)} />}
    </header>
  );
}
