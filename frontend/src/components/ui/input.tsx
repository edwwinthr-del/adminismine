import { forwardRef, type InputHTMLAttributes } from "react";
import { cn } from "@/lib/cn";

/*
 * Sunken rather than raised, per the reference: a frosted pill with a hairline
 * inner shadow, so a field reads as carved into the surface instead of sitting
 * on it. The focus ring is the brand yellow, defined once in globals.css so
 * every control agrees.
 */
/**
 * True when the caller has set an explicit width (`w-40`, `w-[10rem]`, …).
 *
 * `cn` is a plain join with no tailwind-merge, so a width passed as className
 * does not replace the `w-full` in the base class — both land on the element and
 * the stylesheet's order decides, which meant every `w-[10rem]` field silently
 * rendered full width. Rather than add a merge dependency and change override
 * behaviour everywhere at once, the default is simply dropped when a width is
 * given. `max-w-*` is a different property and never conflicted.
 */
export function hasExplicitWidth(className?: string): boolean {
  return /(?:^|\s)w-(?!full\b)/.test(className ?? "");
}

export const Input = forwardRef<HTMLInputElement, InputHTMLAttributes<HTMLInputElement>>(function Input(
  { className, ...props },
  ref,
) {
  return (
    <input
      ref={ref}
      className={cn(
        // Vision UI's field: a 15px radius rather than a pill, 8/12 padding.
        "control-surface focus-ink h-10 rounded-[var(--vui-r-lg)] px-3 text-sm text-zinc-900 transition-shadow placeholder:text-zinc-500 dark:text-zinc-100 dark:placeholder:text-zinc-400",
        hasExplicitWidth(className) ? undefined : "w-full",
        className,
      )}
      {...props}
    />
  );
});
