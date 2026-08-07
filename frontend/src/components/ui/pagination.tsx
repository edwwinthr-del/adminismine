"use client";

import { Button } from "./button";
import { useI18n } from "@/lib/i18n/context";

/** The `meta` block Laravel's paginator returns. */
export interface PageMeta {
  current_page: number;
  last_page: number;
  per_page: number;
  total: number;
  from: number | null;
  to: number | null;
}

/**
 * Server-side paging controls.
 *
 * The lists used to ask for `per_page=100..200` and render the lot, which is
 * fine until a table has real history behind it — the request, the JSON and the
 * DOM all grow together. Pages ask for one screenful and say how much more
 * there is, so a table's cost stops depending on how long the company has been
 * running.
 */
export function Pagination({
  meta,
  onPageChange,
  disabled,
}: {
  meta: PageMeta | null | undefined;
  onPageChange: (page: number) => void;
  disabled?: boolean;
}) {
  const { t } = useI18n();

  if (!meta || meta.total === 0) return null;

  const { current_page: page, last_page: lastPage, total, from, to } = meta;

  return (
    /*
     * The footer of a table card, so it carries the same tinted band the report
     * totals row does — otherwise the count floats in the card's bottom padding
     * looking like a stray caption rather than part of the table.
     */
    <div className="flex flex-wrap items-center justify-between gap-3 border-t border-zinc-900/8 bg-zinc-900/[0.02] px-5 py-3.5 dark:border-white/10 dark:bg-white/[0.03]">
      <p className="text-xs uppercase tracking-[0.08em] text-zinc-500">
        {t("pagination.showing", { from: from ?? 0, to: to ?? 0, total })}
      </p>

      {lastPage > 1 && (
        <div className="flex items-center gap-1.5">
          <Button
            variant="secondary"
            className="h-8 w-8 px-0"
            aria-label={t("pagination.previous")}
            disabled={disabled || page <= 1}
            onClick={() => onPageChange(page - 1)}
          >
            <Chevron className="rotate-90" />
          </Button>
          <span className="px-2 text-xs tabular-nums text-zinc-600 dark:text-zinc-300">
            {t("pagination.page", { page, pages: lastPage })}
          </span>
          <Button
            variant="secondary"
            className="h-8 w-8 px-0"
            aria-label={t("pagination.next")}
            disabled={disabled || page >= lastPage}
            onClick={() => onPageChange(page + 1)}
          >
            <Chevron className="-rotate-90" />
          </Button>
        </div>
      )}
    </div>
  );
}

/** One chevron, rotated per direction, so prev/next cannot drift apart. */
function Chevron({ className }: { className?: string }) {
  return (
    <svg
      viewBox="0 0 24 24"
      className={`h-4 w-4 ${className ?? ""}`}
      fill="none"
      stroke="currentColor"
      strokeWidth={2}
      strokeLinecap="round"
      strokeLinejoin="round"
      aria-hidden
    >
      <path d="M19 9l-7 7-7-7" />
    </svg>
  );
}
