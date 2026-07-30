"use client";

import { useCallback, useEffect, useId, useMemo, useRef, useState } from "react";
import { apiFetch } from "@/lib/api";
import { cn } from "@/lib/cn";
import { useI18n } from "@/lib/i18n/context";
import { useDebouncedValue } from "@/lib/use-debounced-value";

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

  const containerRef = useRef<HTMLDivElement>(null);
  const inputRef = useRef<HTMLInputElement>(null);
  /** Guards against a slow early response overwriting a fast later one. */
  const requestId = useRef(0);

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

  useEffect(() => {
    if (!open) return;

    const onPointerDown = (event: MouseEvent) => {
      if (!containerRef.current?.contains(event.target as Node)) {
        setOpen(false);
        setTerm("");
      }
    };

    document.addEventListener("mousedown", onPointerDown);

    return () => document.removeEventListener("mousedown", onPointerDown);
  }, [open]);

  function choose(option: LookupOption | null) {
    setSelected(option);
    onChange(option?.value ?? null, option);
    setOpen(false);
    setTerm("");
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

    if (event.key === "Escape" && open) {
      event.preventDefault();
      setOpen(false);
      setTerm("");
    }
  }

  const label = selected?.label ?? "";

  return (
    <div ref={containerRef} className={cn("relative", className)}>
      <input
        id={inputId}
        ref={inputRef}
        type="text"
        role="combobox"
        aria-expanded={open}
        aria-controls={`${inputId}-listbox`}
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
        onKeyDown={onKeyDown}
        className="h-10 w-full rounded-md border border-zinc-300 bg-white px-3 pr-8 text-sm text-zinc-900 outline-none transition-colors focus:border-indigo-500 focus:ring-2 focus:ring-indigo-500/20 disabled:opacity-50 dark:border-zinc-700 dark:bg-zinc-900 dark:text-zinc-100"
      />

      {/* The real value, so a plain form submit still validates. */}
      <input type="hidden" required={required} value={value ?? ""} readOnly />

      {value !== null && !disabled && (
        <button
          type="button"
          aria-label={t("select.clear")}
          onClick={() => choose(null)}
          className="absolute right-2 top-1/2 -translate-y-1/2 rounded p-1 text-zinc-400 transition-colors hover:text-zinc-700 dark:hover:text-zinc-200"
        >
          ✕
        </button>
      )}

      {open && (
        <ul
          id={`${inputId}-listbox`}
          role="listbox"
          className="absolute z-20 mt-1 max-h-72 w-full overflow-y-auto rounded-md border border-zinc-200 bg-white py-1 shadow-lg dark:border-zinc-700 dark:bg-zinc-900"
        >
          {emptyLabel && (
            <li>
              <button
                type="button"
                onClick={() => choose(null)}
                className="w-full px-3 py-2 text-left text-sm text-zinc-500 transition-colors hover:bg-zinc-50 dark:hover:bg-zinc-800"
              >
                {emptyLabel}
              </button>
            </li>
          )}

          {loading && options.length === 0 && (
            <li className="px-3 py-2 text-sm text-zinc-500">{t("common.loading")}</li>
          )}

          {!loading && error && <li className="px-3 py-2 text-sm text-red-600">{t("select.failed")}</li>}

          {!loading && !error && options.length === 0 && (
            <li className="px-3 py-2 text-sm text-zinc-500">{t("select.noMatches")}</li>
          )}

          {options.map((option, index) => (
            <li key={option.value} role="option" aria-selected={option.value === value}>
              <button
                type="button"
                onMouseEnter={() => setHighlighted(index)}
                onClick={() => choose(option)}
                className={cn(
                  "flex w-full flex-col items-start gap-0.5 px-3 py-2 text-left text-sm transition-colors",
                  index === highlighted ? "bg-zinc-100 dark:bg-zinc-800" : "",
                  option.value === value ? "font-medium text-indigo-700 dark:text-indigo-300" : "text-zinc-800 dark:text-zinc-200",
                )}
              >
                <span>{option.label}</span>
                {option.hint && <span className="text-xs text-zinc-500">{option.hint}</span>}
              </button>
            </li>
          ))}

          {/* Says the list is cut short rather than implying it is complete. */}
          {hasMore && (
            <li className="border-t border-zinc-100 px-3 py-2 text-xs text-zinc-500 dark:border-zinc-800">
              {t("select.refine")}
            </li>
          )}
        </ul>
      )}
    </div>
  );
}
