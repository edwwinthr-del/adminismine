"use client";

import Link from "next/link";
import { usePathname } from "next/navigation";
import { useAuth } from "@/lib/auth/context";
import { useI18n } from "@/lib/i18n/context";
import { NAV } from "@/lib/nav";
import { cn } from "@/lib/cn";

export function Sidebar({ company }: { company: string }) {
  const { t } = useI18n();
  const { hasPermission } = useAuth();
  const pathname = usePathname();

  return (
    <aside className="hidden w-64 shrink-0 flex-col border-r border-zinc-200 bg-white md:flex dark:border-zinc-800 dark:bg-zinc-950">
      <div className="flex h-16 items-center gap-2 border-b border-zinc-200 px-5 dark:border-zinc-800">
        <div className="h-8 w-8 rounded-md bg-indigo-600" />
        <span className="truncate font-semibold text-zinc-900 dark:text-zinc-50">{company}</span>
      </div>
      <nav className="flex-1 space-y-6 overflow-y-auto px-3 py-4">
        {NAV.map((group) => {
          const items = group.items.filter((i) => i.permission === null || hasPermission(i.permission));
          if (items.length === 0) return null;
          return (
            <div key={group.key}>
              <p className="mb-1 px-2 text-xs font-semibold uppercase tracking-wider text-zinc-400">
                {t(group.key)}
              </p>
              <ul className="space-y-0.5">
                {items.map((item) => {
                  const label = t(item.key);
                  if (!item.enabled) {
                    return (
                      <li key={item.key}>
                        <span className="flex cursor-default items-center justify-between rounded-md px-2 py-2 text-sm text-zinc-400">
                          {label}
                          <span className="rounded bg-zinc-100 px-1.5 py-0.5 text-[10px] dark:bg-zinc-800">
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
                        className={cn(
                          "flex items-center rounded-md px-2 py-2 text-sm font-medium transition-colors",
                          active
                            ? "bg-indigo-50 text-indigo-700 dark:bg-indigo-950 dark:text-indigo-300"
                            : "text-zinc-700 hover:bg-zinc-100 dark:text-zinc-300 dark:hover:bg-zinc-800",
                        )}
                      >
                        {label}
                      </Link>
                    </li>
                  );
                })}
              </ul>
            </div>
          );
        })}
      </nav>
    </aside>
  );
}
