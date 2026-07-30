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
    <div className="flex flex-wrap items-center justify-between gap-3 border-t border-zinc-100 px-4 py-3 dark:border-zinc-800">
      <p className="text-xs text-zinc-500">
        {t("pagination.showing", { from: from ?? 0, to: to ?? 0, total })}
      </p>

      {lastPage > 1 && (
        <div className="flex items-center gap-2">
          <Button
            variant="secondary"
            className="h-8 px-3"
            disabled={disabled || page <= 1}
            onClick={() => onPageChange(page - 1)}
          >
            {t("pagination.previous")}
          </Button>
          <span className="text-xs tabular-nums text-zinc-500">
            {t("pagination.page", { page, pages: lastPage })}
          </span>
          <Button
            variant="secondary"
            className="h-8 px-3"
            disabled={disabled || page >= lastPage}
            onClick={() => onPageChange(page + 1)}
          >
            {t("pagination.next")}
          </Button>
        </div>
      )}
    </div>
  );
}
