"use client";

import { useCallback, useEffect, useId, useMemo, useRef, useState } from "react";
import { createPortal } from "react-dom";
import { apiFetch } from "@/lib/api";
import { cn } from "@/lib/cn";
import { useI18n } from "@/lib/i18n/context";

import { useDebouncedValue } from "@/lib/use-debounced-value";
import { DROPDOWN_OPTION, DROPDOWN_PANEL, useDropdownAnchor } from "./use-dropdown-anchor";

export interface LookupOption {
  value: number;
  label: string;
  hint?: string;
  meta?: Record<string, unknown>;
}

/**
 * Bridge for the forms that keep ids as strings (because `<select>` values are
 * strings): "" means "nothing selected", which this field expresses as null.
 */
export function numeric(value: string | number | null | undefined): number | null {
  if (value === null || value === undefined || value === "") return null;

  const parsed = Number(value);

  return Number.isFinite(parsed) ? parsed : null;
}

interface AsyncSelectProps {
  /** Lookup resource, e.g. "suppliers" or "payable-invoices". */
  resource: string;
  value: number | null;
  onChange: (value: number | null, option: LookupOption | null) => void;
  /** Extra query parameters for the lookup (filters such as `active_only`). */
  params?: Record<string, string | number | boolean | null | undefined>;
  placeholder?: string;
  /** Shown as the first entry when the field may be left unset. */
  emptyLabel?: string;
  disabled?: boolean;
  required?: boolean;
  className?: string;
  id?: string;
}

/**
 * A dropdown that searches the server instead of holding every row.
 *
 * A plain `<select>` has to be given the whole table up front, which is why the
 * forms that point at suppliers, invoices or bank movements got slower every
 * month the company operated. This asks for twenty matching rows at a time,
 * debounced, and only once the field is opened — so the cost of a form no
 * longer grows with the size of the data behind it.
 *
 * The value a record already holds is passed to the API as `include`, so an old
 * record opened for editing can always name its own selection even when that
 * row would not appear in the current search or filter.
 */
export function AsyncSelect({
  resource,
  value,
  onChange,
  params,
  placeholder,
  emptyLabel,
  disabled,
  required,
  className,
  id,
}: AsyncSelectProps) {
  const { t } = useI18n();
  const generatedId = useId();
  const inputId = id ?? generatedId;

  const [open, setOpen] = useState(false);
  const [term, setTerm] = useState("");
  const [options, setOptions] = useState<LookupOption[]>([]);
  const [selected, setSelected] = useState<LookupOption | null>(null);
  const [loading, setLoading] = useState(false);
  const [hasMore, setHasMore] = useState(false);
  const [highlighted, setHighlighted] = useState(0);
  const [error, setError] = useState(false);

  const inputRef = useRef<HTMLInputElement>(null);

  /** Guards against a slow early response overwriting a fast later one. */
  const requestId = useRef(0);

  const close = useCallback(() => {
    setOpen(false);
    setTerm("");
  }, []);

  // Placement, outside-click and Escape are the same problem this control and
  // the plain Select both have, and are solved once in the hook.
  const { containerRef, listRef, box } = useDropdownAnchor(open, close);

  const debouncedTerm = useDebouncedValue(term);

  /*
   * Callers pass `params` as an object literal, so its identity changes on every
   * render of the parent. Depending on it directly would make the effects below
   * re-run forever; they depend on the serialized value instead, which only
   * changes when a filter actually changes.
   */
  const paramKey = useMemo(() => JSON.stringify(params ?? {}), [params]);
  const stableParams = useMemo(() => JSON.parse(paramKey) as Record<string, unknown>, [paramKey]);

  const query = useCallback(
    (search: string, include: number | null): string => {
      const query = new URLSearchParams();

      if (search) query.set("search", search);
      if (include !== null) query.append("include[]", String(include));

      for (const [name, raw] of Object.entries(stableParams)) {
        if (raw === null || raw === undefined || raw === "" || raw === false) continue;
        query.set(name, String(raw === true ? 1 : raw));
      }

      return `/lookups/${resource}?${query.toString()}`;
    },
    [resource, stableParams],
  );

  const fetchOptions = useCallback(
    async (search: string, include: number | null) => {
      const ticket = ++requestId.current;
      setLoading(true);

      try {
        const res = await apiFetch<{ data: LookupOption[]; meta: { has_more: boolean } }>(
          query(search, include),
        );

        // A response from a superseded keystroke is dropped rather than shown.
        if (ticket !== requestId.current) return;

        setOptions(res.data);
        setHasMore(res.meta?.has_more ?? false);
        setError(false);
      } catch {
        if (ticket !== requestId.current) return;
        setOptions([]);
        setError(true);
      } finally {
        if (ticket === requestId.current) setLoading(false);
      }
    },
    [query],
  );

  // Resolve the label of the value the field arrives with. One request, and
  // only when the value is not already known.
  useEffect(() => {
    if (value === null) {
      setSelected(null);

      return;
    }

    if (selected?.value === value) return;

    let cancelled = false;

    void apiFetch<{ data: LookupOption[] }>(query("", value))
      .then((res) => {
        if (cancelled) return;
        setSelected(res.data.find((option) => option.value === value) ?? null);
      })
      .catch(() => {
        /* the field still works; it just cannot show the stored label */
      });

    return () => {
      cancelled = true;
    };
    // `selected` is intentionally not a dependency: it is what this sets.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [value, query]);

  // Options are loaded when the field is opened, and again as the user types —
  // never on mount, so a form with ten of these makes no requests until used.
  useEffect(() => {
    if (!open) return;

    void fetchOptions(debouncedTerm, value);
  }, [open, debouncedTerm, value, fetchOptions]);

  function choose(option: LookupOption | null) {
    setSelected(option);
    onChange(option?.value ?? null, option);
    close();
  }

  function onKeyDown(event: React.KeyboardEvent) {
    if (event.key === "ArrowDown" || event.key === "ArrowUp") {
      event.preventDefault();

      if (!open) {
        setOpen(true);

        return;
      }

      setHighlighted((current) => {
        const next = event.key === "ArrowDown" ? current + 1 : current - 1;

        return Math.max(0, Math.min(options.length - 1, next));
      });

      return;
    }

    if (event.key === "Enter" && open) {
      event.preventDefault();
      const option = options[highlighted];
      if (option) choose(option);

      return;
    }

    // Escape is not handled here: it belongs to the topmost layer on screen,
    // which the effect above registers this list as while it is open.
  }

  const label = selected?.label ?? "";

  /** Screen readers follow the highlight through this, not through styling. */
  const optionId = (index: number) => `${inputId}-option-${index}`;

  return (
    <div ref={containerRef} className={cn("relative", className)}>
      <input
        id={inputId}
        ref={inputRef}
        type="text"
        role="combobox"
        aria-expanded={open}
        aria-controls={`${inputId}-listbox`}
        aria-activedescendant={open && options.length > 0 ? optionId(highlighted) : undefined}
        aria-autocomplete="list"
        autoComplete="off"
        disabled={disabled}
        // Required is enforced on the hidden value, not the search text.
        value={open ? term : label}
        placeholder={placeholder ?? t("select.search")}
        onChange={(event) => {
          setTerm(event.target.value);
          setHighlighted(0);
          if (!open) setOpen(true);
        }}
        onFocus={() => setOpen(true)}
        // Focus alone is not enough to reopen it: after picking an option the
        // field keeps focus, so clicking it again to choose a different one
        // fired nothing and the list stayed shut.
        onMouseDown={() => setOpen(true)}
        onKeyDown={onKeyDown}
        className="control-surface focus-ink h-10 w-full rounded-[var(--vui-r-lg)] px-3 pr-10 text-sm text-zinc-900 transition-shadow placeholder:text-zinc-500 disabled:opacity-50 dark:text-zinc-100 dark:placeholder:text-zinc-400"
      />

      {/* The real value, so a plain form submit still validates. */}
      <input type="hidden" required={required} value={value ?? ""} readOnly />

      {/*
        A chevron when there is nothing to clear. Without it an empty field is
        indistinguishable from a text input — the only thing that said "this
        opens" used to be the clear button, which by definition is absent until
        something is already chosen.
      */}
      {value === null && !disabled && (
        <svg
          viewBox="0 0 24 24"
          aria-hidden
          className="pointer-events-none absolute right-3.5 top-1/2 h-4 w-4 -translate-y-1/2 text-zinc-400"
          fill="none"
          stroke="currentColor"
          strokeWidth={2}
          strokeLinecap="round"
          strokeLinejoin="round"
        >
          <path d="M19 9l-7 7-7-7" />
        </svg>
      )}

      {value !== null && !disabled && (
        <button
          type="button"
          aria-label={t("select.clear")}
          onClick={() => choose(null)}
          className="absolute right-2.5 top-1/2 grid h-6 w-6 -translate-y-1/2 place-items-center rounded-full text-zinc-400 transition-colors hover:bg-zinc-900/[0.06] hover:text-zinc-700 dark:hover:bg-white/10 dark:hover:text-zinc-200"
        >
          <svg viewBox="0 0 24 24" aria-hidden className="h-3.5 w-3.5" fill="none" stroke="currentColor" strokeWidth={2.5} strokeLinecap="round">
            <path d="M6 6l12 12M18 6L6 18" />
          </svg>
        </button>
      )}

      {open && box && typeof document !== "undefined" && createPortal(
        <ul
          ref={listRef}
          id={`${inputId}-listbox`}
          role="listbox"
          style={{ top: box.top, left: box.left, width: box.width, maxHeight: box.height }}
          className={DROPDOWN_PANEL}
        >
          {emptyLabel && (
            <li
              role="option"
              aria-selected={value === null}
              onClick={() => choose(null)}
              className="w-full cursor-pointer rounded-xl px-3 py-2 text-left text-sm text-zinc-500 transition-colors hover:bg-zinc-900/[0.06] dark:hover:bg-white/10"
            >
              {emptyLabel}
            </li>
          )}

          {loading && options.length === 0 && (
            <li className="px-3 py-2 text-sm text-zinc-500">{t("common.loading")}</li>
          )}

          {!loading && error && <li className="px-3 py-2 text-sm text-red-600">{t("select.failed")}</li>}

          {!loading && !error && options.length === 0 && (
            <li className="px-3 py-2 text-sm text-zinc-500">{t("select.noMatches")}</li>
          )}

          {/*
            The row is the option rather than a button inside one: ARIA forbids
            an interactive control inside `role="option"`, and it was the button
            that got announced instead of the option. Keyboard handling is on the
            input and reaches these through `aria-activedescendant`.
          */}
          {options.map((option, index) => (
            <li
              key={option.value}
              id={optionId(index)}
              role="option"
              aria-selected={option.value === value}
              onMouseEnter={() => setHighlighted(index)}
              onClick={() => choose(option)}
              className={cn(
                DROPDOWN_OPTION,
                "cursor-pointer",
                // Hover and keyboard highlight are the same state, so they
                // look the same rather than competing.
                index === highlighted ? "bg-zinc-900/[0.06] dark:bg-white/10" : "",
                option.value === value
                  ? "font-medium text-zinc-900 dark:text-zinc-50"
                  : "text-zinc-700 dark:text-zinc-300",
              )}
            >
              <span>{option.label}</span>
              {option.hint && <span className="text-xs text-zinc-500">{option.hint}</span>}
            </li>
          ))}

          {/* Says the list is cut short rather than implying it is complete. */}
          {hasMore && (
            <li className="mt-1 border-t border-zinc-900/8 px-3 pb-1 pt-2 text-xs text-zinc-500 dark:border-white/10">
              {t("select.refine")}
            </li>
          )}
        </ul>,
        document.body,
      )}
    </div>
  );
}
