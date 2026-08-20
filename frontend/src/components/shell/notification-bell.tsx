"use client";

import Link from "next/link";
import { useCallback, useEffect, useRef, useState } from "react";
import { apiFetch } from "@/lib/api";
import { useAuth } from "@/lib/auth/context";
import { markMutated, subscribeToWrites } from "@/lib/data/cache";
import { useI18n } from "@/lib/i18n/context";
import { formatDate } from "@/lib/format";
import {
  notificationMessage,
  severityTone,
  type AppNotification,
  type NotificationCounts,
} from "@/lib/notifications";
import { cn } from "@/lib/cn";

/** How often the bell re-reads its counts. */
const POLL_MS = 60_000;

/**
 * How often the app asks the server to re-run the scan.
 *
 * The server's own scheduled scan runs once a day, so until this existed a
 * notification raised by something entered this morning did not appear until
 * someone pressed "Re-scan" by hand. Re-running it on a timer is what makes the
 * bell reflect today's records without anyone asking it to.
 */
const SCAN_MS = 180_000;

/**
 * The last scan's timestamp, shared through localStorage so that N open tabs
 * still produce one scan per interval rather than N of them.
 */
const SCAN_STAMP_KEY = "notifications:last-scan";

/**
 * Take the next scan slot, or report that it is not due yet (or that another
 * tab has it). Storage can be unavailable — private windows, a blocked origin —
 * and the scan is harmless to repeat, so a failure to read it scans anyway.
 */
function claimScanSlot(): boolean {
  try {
    const last = Number(window.localStorage.getItem(SCAN_STAMP_KEY) ?? 0);

    if (Number.isFinite(last) && Date.now() - last < SCAN_MS) return false;

    window.localStorage.setItem(SCAN_STAMP_KEY, String(Date.now()));

    return true;
  } catch {
    return true;
  }
}

export function NotificationBell() {
  const { t } = useI18n();
  const { hasPermission } = useAuth();
  const [counts, setCounts] = useState<NotificationCounts>({ unread: 0, open: 0, critical: 0 });
  const [recent, setRecent] = useState<AppNotification[]>([]);
  const [open, setOpen] = useState(false);
  const [loading, setLoading] = useState(false);
  const containerRef = useRef<HTMLDivElement>(null);
  // The scan route is an Admin one, same as the manual button on the
  // notifications page; a user without it just reads what the scan produced.
  const canScan = hasPermission("notifications.configure");

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
   * Re-run the scan on a timer, so notifications appear on their own instead of
   * waiting for the daily scheduled run or for someone to press "Re-scan".
   *
   * Three things keep it from being expensive. A hidden tab does nothing — the
   * scan runs when it is next looked at, since a notification nobody is there to
   * see is not urgent. The slot is shared across tabs, so several open windows
   * still scan once. And the write is reported as revalidate: false: a scan that
   * changed nothing is not a write anyone should see, and only one that created
   * or resolved something invalidates the read cache — which is also what
   * refreshes the counts and the panel below, through the subscription.
   */
  useEffect(() => {
    if (!canScan) return;

    async function scan() {
      if (document.visibilityState !== "visible") return;
      if (!claimScanSlot()) return;

      try {
        const res = await apiFetch<{ data: { created: number; resolved: number } }>("/notifications/scan", {
          method: "POST",
          json: { automatic: true },
          revalidate: false,
        });

        if (res.data.created > 0 || res.data.resolved > 0) markMutated();
      } catch {
        /* the bell must never break the shell */
      }
    }

    const run = () => void scan();

    run();

    const timer = window.setInterval(run, SCAN_MS);
    // Returning to a tab left open past its slot should not wait out another
    // full interval before it re-checks.
    document.addEventListener("visibilitychange", run);

    return () => {
      window.clearInterval(timer);
      document.removeEventListener("visibilitychange", run);
    };
  }, [canScan]);

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
