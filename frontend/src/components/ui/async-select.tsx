"use client";

import { useCallback, useEffect, useId, useLayoutEffect, useMemo, useRef, useState } from "react";
import { createPortal } from "react-dom";
import { apiFetch } from "@/lib/api";
import { cn } from "@/lib/cn";
import { useI18n } from "@/lib/i18n/context";
import { useEscapeLayer } from "@/lib/overlay-layers";
import { useDebouncedValue } from "@/lib/use-debounced-value";

/** Tallest the option list is allowed to be, and the least it will settle for. */
const MAX_LIST_HEIGHT = 288;
const MIN_LIST_HEIGHT = 120;

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
  const listRef = useRef<HTMLUListElement>(null);
  /** Guards against a slow early response overwriting a fast later one. */
  const requestId = useRef(0);
  /** Where to draw the list, in viewport coordinates — see {@link place}. */
  const [box, setBox] = useState<{ top: number; left: number; width: number; height: number } | null>(null);

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
      const target = event.target as Node;

      // The list lives in <body>, so "outside" has to mean outside both parts —
      // otherwise clicking an option closes the field before the click lands on
      // it, and nothing is ever selected.
      if (containerRef.current?.contains(target) || listRef.current?.contains(target)) return;

      setOpen(false);
      setTerm("");
    };

    document.addEventListener("mousedown", onPointerDown);

    return () => document.removeEventListener("mousedown", onPointerDown);
  }, [open]);

  // Escape closes the list and stops there. It used to reach the dialog behind
  // the field as well, so dismissing a dropdown threw away the form — see
  // lib/overlay-layers.
  useEscapeLayer(open, () => {
    setOpen(false);
    setTerm("");
  });

  /**
   * Keep the list on its field, in viewport coordinates.
   *
   * The list is drawn into <body> rather than next to the input, because inside
   * a modal the input sits in a scrolling box (`overflow-y-auto`) that clipped
   * the list the moment the field was anywhere near the bottom — the options
   * were there, cut in half. Drawn from the body it can also flip above the
   * field when there is more room up than down, instead of being squeezed.
   *
   * The cost of leaving the field is that the list no longer moves with it, and
   * the anchor moves for reasons that fire no event to listen for: the dialog
   * holding it is dragged by its header, an error line appears above it, an
   * async label resolves and rewraps a row. So it is measured every frame while
   * open — one `getBoundingClientRect` on one element, for as long as a
   * dropdown is on screen — and the state is only written when the numbers
   * actually move, so a still field costs no renders.
   */
  useLayoutEffect(() => {
    if (!open) return;

    let frame = 0;

    function place() {
      const anchor = containerRef.current;

      if (anchor) {
        const rect = anchor.getBoundingClientRect();
        const gap = 4;
        const below = window.innerHeight - rect.bottom - gap;
        const above = rect.top - gap;
        const flip = below < Math.min(MAX_LIST_HEIGHT, above) && above > below;
        const height = Math.min(MAX_LIST_HEIGHT, Math.max(flip ? above : below, MIN_LIST_HEIGHT));

        // With room on neither side the floor above wins and the list would
        // hang off the edge it was placed against — the very thing the portal
        // was for. Pinning it inside the window instead lets it overlap the
        // field, which is the lesser of the two.
        const top = flip
          ? Math.max(gap, rect.top - gap - height)
          : Math.max(gap, Math.min(rect.bottom + gap, window.innerHeight - gap - height));

        setBox((current) =>
          current &&
          current.top === top &&
          current.left === rect.left &&
          current.width === rect.width &&
          current.height === height
            ? current
            : { top, left: rect.left, width: rect.width, height },
        );
      }

      frame = requestAnimationFrame(place);
    }

    place();

    return () => cancelAnimationFrame(frame);
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

    // Escape is not handled here: it belongs to the topmost layer on screen,
    // which the effect above registers this list as while it is open.
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
        // Focus alone is not enough to reopen it: after picking an option the
        // field keeps focus, so clicking it again to choose a different one
        // fired nothing and the list stayed shut.
        onMouseDown={() => setOpen(true)}
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

      {open && box && typeof document !== "undefined" && createPortal(
        <ul
          ref={listRef}
          id={`${inputId}-listbox`}
          role="listbox"
          style={{ top: box.top, left: box.left, width: box.width, maxHeight: box.height }}
          className="fixed z-[60] overflow-y-auto rounded-md border border-zinc-200 bg-white py-1 shadow-lg dark:border-zinc-700 dark:bg-zinc-900"
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
        </ul>,
        document.body,
      )}
    </div>
  );
}
