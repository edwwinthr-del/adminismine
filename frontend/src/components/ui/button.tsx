import { forwardRef, type ButtonHTMLAttributes } from "react";
import { cn } from "@/lib/cn";

type Variant = "primary" | "secondary" | "ghost" | "danger";

interface ButtonProps extends ButtonHTMLAttributes<HTMLButtonElement> {
  variant?: Variant;
}

/*
 * Pill-shaped, per the reference design. The primary action is the brand yellow
 * carrying near-black content — in the reference that yellow appears about once
 * per screen, on the thing the user came to do, so `primary` is worth keeping
 * scarce. Everything else is a quiet frosted control.
 */
const variants: Record<Variant, string> = {
  primary: "bg-brand-yellow text-ink shadow-[var(--vui-shadow-button)]",
  secondary:
    "control-surface text-zinc-800 shadow-[var(--vui-shadow-button)] hover:bg-white/80 dark:text-zinc-100 dark:hover:bg-white/10",
  ghost: "text-zinc-600 hover:bg-white/60 dark:text-zinc-300 dark:hover:bg-white/10",
  danger: "bg-red-500 text-white shadow-[var(--vui-shadow-button)] hover:bg-red-600",
};

/**
 * Vision UI's button, in this app's colours.
 *
 * The template's own metrics: a 12px radius rather than a pill, 12/24 padding
 * on a 40px minimum, and the label at 12px **bold** — small and heavy rather
 * than medium and roomy, which is what makes its buttons read as controls
 * instead of as tags.
 *
 * The interaction is the part worth having. Vision UI grows every button
 * `scale(1.02)` on hover over `all 150ms ease-in`, so the whole control lifts
 * toward the pointer instead of just changing colour. Kept, with a matching
 * settle on press, and disabled under `prefers-reduced-motion` by the global
 * rule in globals.css.
 */
export const Button = forwardRef<HTMLButtonElement, ButtonProps>(function Button(
  { className, variant = "primary", ...props },
  ref,
) {
  return (
    <button
      ref={ref}
      className={cn(
        "focus-ink inline-flex min-h-10 items-center justify-center gap-2 px-6 py-3 text-xs font-bold",
        "rounded-[var(--vui-r-button)] transition-all duration-150 ease-in",
        "hover:scale-[1.02] active:scale-[0.99]",
        "disabled:pointer-events-none disabled:opacity-45",
        variants[variant],
        className,
      )}
      {...props}
    />
  );
});
