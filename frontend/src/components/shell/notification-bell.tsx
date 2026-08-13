"use client";

import Link from "next/link";
import { useCallback, useEffect, useRef, useState } from "react";
import { apiFetch } from "@/lib/api";
import { subscribeToWrites } from "@/lib/data/cache";
import { useI18n } from "@/lib/i18n/context";
import { formatDate } from "@/lib/format";
import {
  notificationMessage,
  severityTone,
  type AppNotification,
  type NotificationCounts,
} from "@/lib/notifications";
import { cn } from "@/lib/cn";

/** How often the bell re-checks; the scan itself runs daily on the server. */
const POLL_MS = 60_000;

export function NotificationBell() {
  const { t } = useI18n();
  const [counts, setCounts] = useState<NotificationCounts>({ unread: 0, open: 0, critical: 0 });
  const [recent, setRecent] = useState<AppNotification[]>([]);
  const [open, setOpen] = useState(false);
  const [loading, setLoading] = useState(false);
  const containerRef = useRef<HTMLDivElement>(null);

  const loadCounts = useCallback(async () => {
    try {
      const res = await apiFetch<{ data: NotificationCounts }>("/notifications/unread-count");
      setCounts(res.data);
    } catch {
      /* the bell must never break the shell */
    }
  }, []);

  const loadRecent = useCallback(async () => {
    setLoading(true);
    try {
      const res = await apiFetch<{ data: AppNotification[]; meta: NotificationCounts }>("/notifications");
      setRecent(res.data.slice(0, 6));
      setCounts(res.meta);
    } catch {
      setRecent([]);
    } finally {
      setLoading(false);
    }
  }, []);


  useEffect(() => {
    void loadCounts();
    const timer = window.setInterval(() => void loadCounts(), POLL_MS);
    return () => window.clearInterval(timer);
  }, [loadCounts]);

  /*
   * The badge is a figure derived from records this app edits, so it follows the
   * same rule as every other one: a write anywhere invalidates it. The poll
   * alone meant "Mark all read" emptied the list while the bell kept showing a
   * red 12 for up to a minute, and a fresh scan's notifications did not appear
   * until the next tick. The interval stays, for the notifications the server
   * raises on its own schedule.
   */
  useEffect(
    () =>
      subscribeToWrites(() => {
        void loadCounts();
        if (open) void loadRecent();
      }),
    [loadCounts, loadRecent, open],
  );

  // Close when clicking outside the panel.
  useEffect(() => {
    if (!open) return;

    function onClick(event: MouseEvent) {
      if (!containerRef.current?.contains(event.target as Node)) setOpen(false);
    }
    document.addEventListener("mousedown", onClick);
    return () => document.removeEventListener("mousedown", onClick);
  }, [open]);

  async function toggle() {
    const next = !open;
    setOpen(next);
    if (!next) return;

    await loadRecent();
  }

  async function markRead(notification: AppNotification) {
    if (notification.status !== "unread") return;
    try {
      // apiFetch's markMutated() fires on this PUT, and the subscription above
      // refreshes both the badge and the open list — so the row stops looking
      // unread while the panel is still on screen.
      await apiFetch(`/notifications/${notification.id}/read`, { method: "PUT" });
    } catch {
      /* navigation matters more than the read flag */
    }
  }

  return (
    <div ref={containerRef} className="relative">
      <button
        onClick={() => void toggle()}
        aria-label={t("notifications.title")}
        className="control-surface focus-ink relative flex h-10 w-10 items-center justify-center rounded-full text-zinc-600 transition-colors hover:bg-white/80 dark:text-zinc-300 dark:hover:bg-white/10"
      >
        <svg
          viewBox="0 0 24 24"
          className="h-[18px] w-[18px]"
          fill="none"
          stroke="currentColor"
          strokeWidth={1.5}
          strokeLinecap="round"
          strokeLinejoin="round"
          aria-hidden
        >
          <path d="M18 9a6 6 0 1 0-12 0c0 6-3 7-3 7h18s-3-1-3-7M13.7 20a2 2 0 0 1-3.4 0" />
        </svg>
        {counts.unread > 0 && (
          <span
            className={cn(
              "absolute -right-0.5 -top-0.5 flex h-[18px] min-w-[18px] items-center justify-center rounded-full px-1 text-[10px] font-semibold ring-2 ring-[--background]",
              counts.critical > 0 ? "bg-red-500 text-white" : "bg-brand-yellow text-ink",
            )}
          >
            {counts.unread > 99 ? "99+" : counts.unread}
          </span>
        )}
      </button>

      {open && (
        <div className="surface-strong absolute right-0 z-50 mt-2 w-80 overflow-hidden rounded-3xl">
          <div className="flex items-center justify-between border-b border-zinc-900/5 px-4 py-3 dark:border-white/10">
            <span className="text-sm font-semibold text-zinc-900 dark:text-zinc-50">
              {t("notifications.title")}
            </span>
            <span className="text-xs text-zinc-500">
              {t("notifications.unreadCount", { count: counts.unread })}
            </span>
          </div>

          <ul className="max-h-80 divide-y divide-zinc-100 overflow-y-auto dark:divide-zinc-800">
            {loading ? (
              <li className="px-4 py-6 text-center text-sm text-zinc-500">{t("common.loading")}</li>
            ) : recent.length === 0 ? (
              <li className="px-4 py-6 text-center text-sm text-zinc-500">{t("notifications.empty")}</li>
            ) : (
              recent.map((notification) => (
                <li key={notification.id}>
                  <Link
                    href={notification.link}
                    onClick={() => {
                      setOpen(false);
                      void markRead(notification);
                    }}
                    className="block px-4 py-3 transition-colors hover:bg-zinc-50 dark:hover:bg-zinc-800"
                  >
                    <span className="flex items-start gap-2">
                      <span
                        aria-hidden
                        className={cn(
                          "mt-1.5 h-2 w-2 shrink-0 rounded-full",
                          severityTone(notification.severity) === "red"
                            ? "bg-red-500"
                            : severityTone(notification.severity) === "amber"
                              ? "bg-amber-500"
                              : "bg-zinc-300 dark:bg-zinc-600",
                        )}
                      />
                      <span className="min-w-0 flex-1">
                        <span
                          className={cn(
                            "block text-sm",
                            notification.status === "unread"
                              ? "font-medium text-zinc-900 dark:text-zinc-50"
                              : "text-zinc-600 dark:text-zinc-300",
                          )}
                        >
                          {notificationMessage(t, notification)}
                        </span>
                        <span className="mt-0.5 block text-xs text-zinc-500">
                          {notification.due_date
                            ? t("notifications.due", { date: formatDate(notification.due_date) })
                            : formatDate(notification.created_at)}
                        </span>
                      </span>
                    </span>
                  </Link>
                </li>
              ))
            )}
          </ul>

          <Link
            href="/notifications"
            onClick={() => setOpen(false)}
            className="block border-t border-zinc-200 px-4 py-3 text-center text-sm font-medium text-indigo-600 transition-colors hover:bg-zinc-50 dark:border-zinc-800 dark:text-indigo-400 dark:hover:bg-zinc-800"
          >
            {t("notifications.viewAll")}
          </Link>
        </div>
      )}
    </div>
  );
}
