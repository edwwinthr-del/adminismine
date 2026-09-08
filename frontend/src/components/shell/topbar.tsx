"use client";

import { useState, useSyncExternalStore } from "react";
import Link from "next/link";
import { usePathname } from "next/navigation";
import { useAuth } from "@/lib/auth/context";
import { useI18n } from "@/lib/i18n/context";
import { LOCALE_LABELS, type Locale } from "@/lib/i18n/dictionaries";
import { NAV } from "@/lib/nav";
import { useSidenav } from "@/lib/sidenav";
import { cn } from "@/lib/cn";
import { Select } from "@/components/ui/select";
import { ChangePasswordModal } from "./change-password-modal";
import { NotificationBell } from "./notification-bell";
import { ThemeToggle } from "./theme-toggle";

/**
 * The navbar, rebuilt in the Vision UI idiom.
 *
 * The template's own numbers, from `examples/Navbars/DashboardNavbar/styles.js`:
 * a bar that floats 12px down rather than sitting flush, 75px tall, at the card
 * radius, over a 42px backdrop blur — and lit by an **inset** white hairline
 * (`inset 0 0 1px 1px rgba(255,255,255,.9)`) rather than outlined by a border.
 * That inset edge is what makes it read as a pane of glass lifted off the page.
 *
 * Left is a breadcrumb trail, which the template has and this app did not.
 * Right keeps every control that was already here — language, theme,
 * notifications, account — restyled, none removed.
 *
 * **Deliberately not copied:** the template also prints the page title in the
 * navbar. Every page in this app already renders its own heading, so doing that
 * now would show the title twice. It moves up here as each page is migrated.
 */
/*
 * The bar is transparent at the top of the page and becomes glass as soon as
 * the page moves — the template's own rule, from `DashboardNavbar`:
 *
 *     setTransparentNavbar(dispatch, fixedNavbar && window.scrollY === 0)
 *
 * with its gradient, `blur(42px)`, shadow and border all switched on together
 * by that one flag. At rest there is nothing behind the bar to separate it
 * from, so the frosting is not doing anything; it earns its place the moment
 * content starts passing underneath.
 *
 * Read through `useSyncExternalStore` rather than a `useEffect`: the server
 * snapshot is the un-scrolled state, and a page restored mid-scroll paints
 * frosted on the first frame instead of flashing clear and then settling.
 */
function subscribeToScroll(onChange: () => void) {
  window.addEventListener("scroll", onChange, { passive: true });
  return () => window.removeEventListener("scroll", onChange);
}

export function Topbar() {
  const { locale, locales, setLocale, t } = useI18n();
  const { user, logout, setLocaleRemote } = useAuth();
  const { mini, toggle } = useSidenav();
  const pathname = usePathname();
  const [changingPassword, setChangingPassword] = useState(false);
  const [menuOpen, setMenuOpen] = useState(false);
  const scrolled = useSyncExternalStore(
    subscribeToScroll,
    () => window.scrollY > 0,
    () => false,
  );

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

  // Where you are, named from the same source the sidebar reads, so a renamed
  // term or a hidden module is reflected here without a second mapping.
  const trail = breadcrumb(pathname);
  /* The page's own name, which the template prints under its trail. */
  const title = trail.length > 0 ? t(trail[trail.length - 1].key) : null;

  return (
    /*
     * `mb-6`, not `mb-2`. A sticky element is held at its `top` offset while
     * flow still reserves only its own height — so the bar is drawn 12px lower
     * than the space kept for it, and anything with less than 12px of margin
     * below starts *above* the bar's visible bottom edge. The dashboard's
     * controls row overlapped it by exactly the 4px that arithmetic predicts.
     */
    <header className="sticky top-3 z-30 mx-4 mb-6 md:mx-6">
      <div
        className="flex min-h-[4.6875rem] flex-col items-start justify-between gap-2 px-4 py-2 md:flex-row md:items-center"
        style={{
          borderRadius: "var(--vui-r-xl)",
          background: scrolled ? "var(--vui-navbar)" : "transparent",
          backdropFilter: scrolled ? "blur(var(--vui-blur-navbar))" : "none",
          WebkitBackdropFilter: scrolled ? "blur(var(--vui-blur-navbar))" : "none",
          border: `1px solid ${scrolled ? "var(--vui-navbar-border)" : "transparent"}`,
          boxShadow: scrolled ? "var(--vui-shadow-navbar)" : "none",
          transition:
            "background var(--vui-dur-standard) var(--vui-ease), box-shadow var(--vui-dur-standard) var(--vui-ease), backdrop-filter var(--vui-dur-standard) var(--vui-ease), border-color var(--vui-dur-standard) var(--vui-ease)",
        }}
      >
        {/* ---- where you are ------------------------------------------ */}
        <div className="flex min-w-0 items-center gap-2">
          {/*
            The template's menu button. Below its xl breakpoint the rail is
            collapsed, so this is how it comes back — and it is the reason the
            collapsed state lives in a context rather than in the sidebar.
          */}
          <button
            type="button"
            onClick={toggle}
            aria-expanded={!mini}
            aria-label={t(mini ? "nav.expand" : "nav.collapse")}
            className="focus-ink -ml-1 hidden shrink-0 rounded-xl p-2 text-zinc-500 transition-colors hover:bg-zinc-900/[0.05] hover:text-zinc-900 md:inline-flex dark:text-zinc-400 dark:hover:bg-white/10 dark:hover:text-zinc-100"
          >
            <svg viewBox="0 0 24 24" aria-hidden className="h-[18px] w-[18px]" fill="none" stroke="currentColor" strokeWidth={1.75} strokeLinecap="round">
              <path d="M3 6h18M3 12h18M3 18h18" />
            </svg>
          </button>

          {/*
            Trail above, page title below — the template's Breadcrumbs component
            renders exactly this pair, and it is why its bar is 75px rather than
            a single row. The title lives here rather than on the page: every
            screen used to open with a 40px heading directly under the bar, which
            read as two competing headers stacked on top of each other.
          */}
          <div className="min-w-0">
            <nav aria-label={t("nav.breadcrumb")} className="min-w-0">
              <ol className="flex min-w-0 items-center gap-1.5 text-xs">
                <li className="shrink-0">
                  <Link
                    href="/dashboard"
                    className="focus-ink grid h-5 w-5 place-items-center rounded-md text-zinc-500 transition-colors hover:bg-zinc-900/[0.05] hover:text-zinc-900 dark:text-zinc-400 dark:hover:bg-white/10 dark:hover:text-zinc-100"
                    aria-label={t("nav.dashboard")}
                  >
                    <svg viewBox="0 0 24 24" aria-hidden className="h-3.5 w-3.5" fill="none" stroke="currentColor" strokeWidth={1.75} strokeLinecap="round" strokeLinejoin="round">
                      <path d="M3 11 12 3l9 8M5 10v10h14V10" />
                    </svg>
                  </Link>
                </li>

                {trail.map((step, index) => (
                  <li key={step.key} className="flex min-w-0 items-center gap-1.5">
                    {/* The muted ink, not a step lighter: the navbar's gradient is
                        only 3.9% white, so a separator sits effectively on the page
                        wash, where zinc-400 measures 2.0:1. */}
                    <span aria-hidden className="text-zinc-500 dark:text-zinc-400">/</span>
                    <span
                      className={cn(
                        "truncate",
                        index === trail.length - 1
                          ? "text-zinc-700 dark:text-zinc-200"
                          : "text-zinc-500 dark:text-zinc-400",
                      )}
                      aria-current={index === trail.length - 1 ? "page" : undefined}
                    >
                      {t(step.key)}
                    </span>
                  </li>
                ))}
              </ol>
            </nav>

            {title && (
              <h1 className="mt-0.5 truncate text-base font-bold tracking-tight text-zinc-900 dark:text-zinc-50">
                {title}
              </h1>
            )}
          </div>
        </div>

        {/* ---- controls ------------------------------------------------ */}
        <div className="flex shrink-0 items-center gap-2.5">
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

          <ThemeToggle />

          <NotificationBell />

          <div className="relative">
            <button
              onClick={() => setMenuOpen((open) => !open)}
              onBlur={() => window.setTimeout(() => setMenuOpen(false), 150)}
              aria-expanded={menuOpen}
              aria-haspopup="menu"
              className="focus-ink flex h-10 items-center gap-2.5 pl-1.5 pr-3 transition-colors hover:bg-zinc-900/[0.05] dark:hover:bg-white/10"
              style={{
                borderRadius: "var(--vui-r-button)",
                boxShadow: "var(--vui-shadow-inset)",
              }}
            >
              <span
                className="grid h-7 w-7 shrink-0 place-items-center bg-ink text-[11px] font-semibold text-brand-yellow"
                style={{ borderRadius: "0.6rem" }}
              >
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
                className="vui-menu vui-edge absolute right-0 z-30 mt-2 w-56 overflow-hidden p-1.5"
                style={{
                  borderRadius: "var(--vui-r-xl)",
                  animation: `vui-menu-in var(--vui-dur-shorter) var(--vui-ease)`,
                }}
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
                  className="flex w-full items-center px-3.5 py-2.5 text-left text-sm text-zinc-700 transition-colors hover:bg-zinc-900/5 dark:text-zinc-200 dark:hover:bg-white/10"
                  style={{ borderRadius: "var(--vui-r-button)" }}
                >
                  {t("account.changePassword")}
                </button>
                <button
                  role="menuitem"
                  onMouseDown={(e) => e.preventDefault()}
                  onClick={() => void logout()}
                  className="flex w-full items-center px-3.5 py-2.5 text-left text-sm text-red-600 transition-colors hover:bg-red-500/10 dark:text-red-400"
                  style={{ borderRadius: "var(--vui-r-button)" }}
                >
                  {t("common.logout")}
                </button>
              </div>
            )}
          </div>
        </div>
      </div>

      {changingPassword && <ChangePasswordModal onClose={() => setChangingPassword(false)} />}
    </header>
  );
}

/**
 * The trail for a path, read out of `lib/nav.ts`.
 *
 * Deriving it from the navigation rather than from the URL segments means a
 * renamed term reads the same here as it does in the sidebar, and a two-segment
 * route does not turn into two meaningless crumbs.
 */
function breadcrumb(pathname: string): { key: string }[] {
  for (const group of NAV) {
    for (const item of group.items) {
      if (pathname === item.href || pathname.startsWith(`${item.href}/`)) {
        return [{ key: group.key }, { key: item.key }];
      }
    }
  }

  return [];
}
