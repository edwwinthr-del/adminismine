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
  primary:
    "bg-brand-yellow text-ink shadow-[0_1px_2px_rgb(13_12_11/0.08),0_8px_20px_-10px_rgb(213_205_20/0.9)] hover:brightness-[1.06] active:brightness-95",
  secondary: "control-surface text-zinc-800 hover:bg-white/80 dark:text-zinc-100 dark:hover:bg-white/10",
  ghost: "text-zinc-600 hover:bg-white/60 dark:text-zinc-300 dark:hover:bg-white/10",
  danger: "bg-red-500 text-white shadow-[0_8px_20px_-10px_rgb(239_68_68/0.9)] hover:bg-red-600",
};

export const Button = forwardRef<HTMLButtonElement, ButtonProps>(function Button(
  { className, variant = "primary", ...props },
  ref,
) {
  return (
    <button
      ref={ref}
      className={cn(
        "focus-ink inline-flex h-10 items-center justify-center gap-2 rounded-full px-5 text-sm font-medium transition-all duration-150 disabled:pointer-events-none disabled:opacity-45",
        variants[variant],
        className,
      )}
      {...props}
    />
  );
});
