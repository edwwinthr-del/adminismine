"use client";

import {
  Children,
  isValidElement,
  useCallback,
  useEffect,
  useId,
  useMemo,
  useRef,
  useState,
  type ReactNode,
  type SelectHTMLAttributes,
} from "react";
import { createPortal } from "react-dom";
import { cn } from "@/lib/cn";
import { useI18n } from "@/lib/i18n/context";
import { hasExplicitWidth } from "./input";
import { DROPDOWN_OPTION, DROPDOWN_PANEL, useDropdownAnchor } from "./use-dropdown-anchor";

/**
 * A dropdown that draws its own list.
 *
 * It used to be a native `<select>`, styled to match the rest of the app — and
 * the closed control did match. The open list did not and could not: an
 * `<option>` list is drawn by the operating system, takes no CSS, and on Windows
 * renders as a white system menu in the middle of a dark application. That is
 * the one part of a dropdown a user actually looks at while using it.
 *
 * So the list is ours now, and shares its positioning, outside-click and Escape
 * handling with {@link AsyncSelect} through `useDropdownAnchor` — one
 * implementation of the awkward half, two dropdowns.
 *
 * **The API is unchanged on purpose.** Callers still pass `<option>` children
 * and still read `event.target.value` in `onChange`, because 83 call sites do
 * and rewriting them would have made this a refactor of every form in the app
 * rather than a change to one control. The options are read out of the children
 * rather than rendered as elements, and the event handed back is shaped like the
 * one a native select gives — see {@link changeEvent} for how completely.
 */

interface Option {
  value: string;
  label: ReactNode;
  /** The searchable text, for type-ahead — a label is a node, not a string. */
  text: string;
  disabled: boolean;
}

/** Flatten `<option>` children — including those inside a fragment or a map. */
function readOptions(children: ReactNode): Option[] {
  const out: Option[] = [];

  Children.toArray(children).forEach((child) => {
    if (!isValidElement(child)) return;

    if (child.type === "option") {
      const props = child.props as { value?: string | number; children?: ReactNode; disabled?: boolean };
      const label = props.children ?? "";

      out.push({
        value: String(props.value ?? ""),
        label,
        text: typeof label === "string" ? label : String(props.value ?? ""),
        disabled: Boolean(props.disabled),
      });

      return;
    }

    // A fragment or a wrapper: look inside rather than dropping its options.
    const nested = (child.props as { children?: ReactNode }).children;
    if (nested) out.push(...readOptions(nested));
  });

  return out;
}

/**
 * An object shaped like the change event a native `<select>` hands back.
 *
 * The first cut was `{ target: { value } }` and a cast, which every existing
 * call site was happy with because every one of them reads `.value` and stops.
 * The cast is the problem: it tells TypeScript this is a full `ChangeEvent`, so
 * the first handler written to the contract a native select actually offers —
 * `event.preventDefault()`, `event.target.name` — would compile and then throw
 * at runtime. The methods are no-ops because there is nothing to prevent: this
 * is not a DOM event and nothing is listening above it.
 */
function changeEvent(
  value: string,
  name: string | undefined,
  id: string,
): React.ChangeEvent<HTMLSelectElement> {
  const target = { value, name: name ?? "", id, type: "select-one" };

  return {
    target,
    currentTarget: target,
    preventDefault: () => undefined,
    stopPropagation: () => undefined,
    isDefaultPrevented: () => false,
    isPropagationStopped: () => false,
    persist: () => undefined,
    bubbles: true,
    cancelable: false,
    defaultPrevented: false,
    eventPhase: 0,
    isTrusted: false,
    nativeEvent: null,
    timeStamp: Date.now(),
    type: "change",
  } as unknown as React.ChangeEvent<HTMLSelectElement>;
}

export function Select({
  className,
  children,
  value,
  onChange,
  disabled,
  required,
  id,
  ...props
}: SelectHTMLAttributes<HTMLSelectElement>) {
  const { t } = useI18n();
  const generatedId = useId();
  const fieldId = id ?? generatedId;

  /*
   * The control the user operates is the button, not the hidden `<select>`, so
   * anything describing the control has to land on the button. Spread with the
   * rest of the props it reached an `aria-hidden` element instead, which meant a
   * caller's `aria-label` was announced to nobody. `name`, `form` and the other
   * form-plumbing props stay with the hidden select, which is what submits.
   */
  const {
    "aria-label": ariaLabel,
    "aria-labelledby": ariaLabelledBy,
    "aria-describedby": ariaDescribedBy,
    "aria-invalid": ariaInvalid,
    title,
    ...selectProps
  } = props;

  const [open, setOpen] = useState(false);
  const [highlighted, setHighlighted] = useState(0);
  const buttonRef = useRef<HTMLButtonElement>(null);
  const typed = useRef({ term: "", at: 0 });

  const close = useCallback(() => setOpen(false), []);
  const { containerRef, listRef, box } = useDropdownAnchor(open, close);

  // Walking the children allocates a fresh array every time, and one of these
  // renders per row of the attendance roster — so it is derived once per change
  // of children rather than once per render.
  const options = useMemo(() => readOptions(children), [children]);
  const current = options.find((option) => option.value === String(value ?? ""));

  /** Screen readers follow the highlight through this, not through styling. */
  const optionId = (index: number) => `${fieldId}-option-${index}`;

  /**
   * Open, landing on whatever is already chosen rather than at the top, so a
   * long list does not have to be scrolled back to where you were.
   *
   * Done here rather than in an effect keyed on `open`: which row to highlight
   * is a consequence of the click, not state to be synchronised afterwards.
   */
  function openList() {
    const at = options.findIndex((option) => option.value === String(value ?? ""));
    setHighlighted(at < 0 ? 0 : at);
    setOpen(true);
  }

  // Keep the highlighted row in view when it moves by keyboard.
  useEffect(() => {
    if (!open) return;

    listRef.current?.querySelectorAll("li")[highlighted]?.scrollIntoView({ block: "nearest" });
  }, [open, highlighted, listRef]);

  function choose(option: Option) {
    if (option.disabled) return;

    onChange?.(changeEvent(option.value, selectProps.name, fieldId));
    setOpen(false);
    buttonRef.current?.focus();
  }

  function move(delta: number) {
    setHighlighted((from) => {
      let next = from;

      // Step over anything disabled rather than landing on it.
      for (let i = 0; i < options.length; i++) {
        next = Math.max(0, Math.min(options.length - 1, next + delta));
        if (!options[next]?.disabled) break;
      }

      return next;
    });
  }

  function onKeyDown(event: React.KeyboardEvent) {
    if (event.key === "ArrowDown" || event.key === "ArrowUp") {
      event.preventDefault();
      if (!open) { openList(); return; }
      move(event.key === "ArrowDown" ? 1 : -1);

      return;
    }

    if (event.key === "Home" || event.key === "End") {
      if (!open) return;
      event.preventDefault();
      setHighlighted(event.key === "Home" ? 0 : options.length - 1);

      return;
    }

    if (event.key === "Enter" || event.key === " ") {
      event.preventDefault();
      if (!open) { openList(); return; }
      const option = options[highlighted];
      if (option) choose(option);

      return;
    }

    if (event.key === "Tab" && open) {
      setOpen(false);

      return;
    }

    // Type-ahead, the one native behaviour worth keeping: letters typed within
    // a second of each other jump to the first option starting with them.
    if (event.key.length === 1 && !event.metaKey && !event.ctrlKey && !event.altKey) {
      const now = Date.now();
      typed.current = {
        term: (now - typed.current.at < 1000 ? typed.current.term : "") + event.key.toLowerCase(),
        at: now,
      };

      const at = options.findIndex(
        (option) => !option.disabled && option.text.toLowerCase().startsWith(typed.current.term),
      );

      if (at >= 0) {
        setHighlighted(at);
        if (!open) choose(options[at]);
      }
    }
  }

  // Escape is not handled here: it belongs to the topmost layer on screen,
  // which useDropdownAnchor registers this list as while it is open.

  return (
    <div ref={containerRef} className={cn("relative", hasExplicitWidth(className) ? className : cn("w-full", className))}>
      <button
        id={fieldId}
        ref={buttonRef}
        type="button"
        role="combobox"
        aria-expanded={open}
        aria-controls={`${fieldId}-listbox`}
        aria-haspopup="listbox"
        aria-activedescendant={open ? optionId(highlighted) : undefined}
        aria-label={ariaLabel}
        aria-labelledby={ariaLabelledBy}
        aria-describedby={ariaDescribedBy}
        aria-invalid={ariaInvalid}
        title={title}
        disabled={disabled}
        onClick={() => (open ? setOpen(false) : openList())}
        onKeyDown={onKeyDown}
        className={cn(
          "control-surface focus-ink flex h-10 w-full cursor-pointer items-center justify-between gap-2",
          "rounded-[var(--vui-r-lg)] px-3 text-left text-sm text-zinc-900 transition-shadow disabled:cursor-not-allowed",
          "disabled:opacity-50 dark:text-zinc-100",
        )}
      >
        <span className={cn("truncate", current ? "" : "text-zinc-500 dark:text-zinc-400")}>
          {current?.label ?? t("select.choose")}
        </span>
        <svg
          viewBox="0 0 24 24"
          aria-hidden
          className="h-4 w-4 shrink-0 text-zinc-400"
          fill="none"
          stroke="currentColor"
          strokeWidth={2}
          strokeLinecap="round"
          strokeLinejoin="round"
        >
          <path d="M19 9l-7 7-7-7" />
        </svg>
      </button>

      {/*
        The real value, so a plain form submit still validates and any caller
        reading the DOM still finds a select with the chosen value.
      */}
      <select
        aria-hidden
        tabIndex={-1}
        required={required}
        value={String(value ?? "")}
        onChange={() => undefined}
        className="pointer-events-none absolute h-0 w-0 opacity-0"
        {...selectProps}
      >
        {options.map((option) => (
          <option key={option.value} value={option.value} />
        ))}
      </select>

      {open && box && typeof document !== "undefined" && createPortal(
        <ul
          ref={listRef}
          id={`${fieldId}-listbox`}
          role="listbox"
          style={{ top: box.top, left: box.left, width: box.width, maxHeight: box.height }}
          className={DROPDOWN_PANEL}
        >
          {/*
            The row is the option. It used to hold a <button>, which reads to a
            screen reader as an interactive control inside an option — ARIA
            forbids that, and the button is what got announced rather than the
            option. Keyboard handling lives on the combobox and reaches these
            through `aria-activedescendant`, so nothing here needs focus.
          */}
          {options.map((option, index) => (
            <li
              key={option.value}
              id={optionId(index)}
              role="option"
              aria-selected={option.value === String(value ?? "")}
              aria-disabled={option.disabled || undefined}
              onMouseEnter={() => !option.disabled && setHighlighted(index)}
              onClick={() => choose(option)}
              className={cn(
                DROPDOWN_OPTION,
                option.disabled ? "cursor-not-allowed opacity-40" : "cursor-pointer",
                index === highlighted && !option.disabled ? "bg-zinc-900/[0.06] dark:bg-white/10" : "",
                option.value === String(value ?? "")
                  ? "font-medium text-zinc-900 dark:text-zinc-50"
                  : "text-zinc-700 dark:text-zinc-300",
              )}
            >
              <span>{option.label}</span>
            </li>
          ))}
        </ul>,
        document.body,
      )}
    </div>
  );
}
