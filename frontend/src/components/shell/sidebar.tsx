"use client";

import Link from "next/link";
import { usePathname } from "next/navigation";
import { useAuth } from "@/lib/auth/context";
import { useI18n } from "@/lib/i18n/context";
import { NAV } from "@/lib/nav";
import { cn } from "@/lib/cn";
import { NavIcon } from "./nav-icons";

/**
 * The reference design uses an icon-only rail. This navigation carries 26
 * destinations across six groups, in three languages — icons alone cannot
 * separate Payables from Receivables from Loans, and a tooltip is not a label.
 * So the rail keeps its labels and takes the reference's *visual* language
 * instead: a floating frosted panel, thin outline icons, and the white rounded
 * pill with a soft shadow marking where you are.
 */
export function Sidebar({ company }: { company: string }) {
  const { t } = useI18n();
  const { hasPermission } = useAuth();
  const pathname = usePathname();

  return (
    /*
     * Sticky and its own scroller: the navigation is taller than a laptop
     * viewport, and letting it scroll with the page would mean scrolling a long
     * table just to reach Settings.
     */
    <aside className="sticky top-0 hidden h-screen w-64 shrink-0 py-4 pl-4 md:flex">
      <div className="surface flex w-full flex-col overflow-hidden rounded-[1.75rem] py-5">
        <div className="flex items-center gap-3 px-5 pb-5">
          <Logo />
          <span className="truncate text-[15px] font-semibold tracking-tight text-zinc-900 dark:text-zinc-50">
            {company}
          </span>
        </div>

        <nav className="scroll-quiet flex-1 space-y-5 overflow-y-auto px-3 pb-1">
          {NAV.map((group) => {
            const items = group.items.filter((i) => i.permission === null || hasPermission(i.permission));
            if (items.length === 0) return null;

            return (
              <div key={group.key}>
                <p className="mb-1.5 px-3 text-[10px] font-semibold uppercase tracking-[0.12em] text-zinc-400">
                  {t(group.key)}
                </p>
                <ul className="space-y-0.5">
                  {items.map((item) => {
                    const label = t(item.key);

                    if (!item.enabled) {
                      return (
                        <li key={item.key}>
                          <span className="flex cursor-default items-center gap-3 rounded-2xl px-3 py-2 text-sm text-zinc-400">
                            <NavIcon name={item.key} className="h-[18px] w-[18px] shrink-0 opacity-60" />
                            <span className="truncate">{label}</span>
                            <span className="ml-auto rounded-full bg-zinc-900/5 px-1.5 py-0.5 text-[10px] dark:bg-white/10">
                              {t("common.soon")}
                            </span>
                          </span>
                        </li>
                      );
                    }

                    const active = pathname === item.href || pathname.startsWith(`${item.href}/`);

                    return (
                      <li key={item.key}>
                        <Link
                          href={item.href}
                          aria-current={active ? "page" : undefined}
                          className={cn(
                            "focus-ink flex items-center gap-3 rounded-2xl px-3 py-2 text-sm transition-all duration-150",
                            active
                              ? "bg-white font-medium text-zinc-900 shadow-[0_1px_2px_rgb(13_12_11/0.06),0_6px_16px_-8px_rgb(13_12_11/0.25)] dark:bg-white/12 dark:text-zinc-50"
                              : "text-zinc-600 hover:bg-white/55 hover:text-zinc-900 dark:text-zinc-400 dark:hover:bg-white/8 dark:hover:text-zinc-100",
                          )}
                        >
                          <NavIcon name={item.key} className="h-[18px] w-[18px] shrink-0" />
                          <span className="truncate">{label}</span>
                        </Link>
                      </li>
                    );
                  })}
                </ul>
              </div>
            );
          })}
        </nav>
      </div>
    </aside>
  );
}

/** The reference's mark: two facing half-discs, an hourglass in silhouette. */
function Logo() {
  return (
    <span className="grid h-9 w-9 shrink-0 place-items-center rounded-2xl bg-ink text-brand-yellow">
      <svg viewBox="0 0 24 24" className="h-5 w-5" fill="currentColor" aria-hidden>
        <path d="M4 3h16a8 8 0 0 1-8 8 8 8 0 0 1-8-8Z" />
        <path d="M20 21H4a8 8 0 0 1 8-8 8 8 0 0 1 8 8Z" />
      </svg>
    </span>
  );
}
