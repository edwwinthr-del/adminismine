import type { HTMLAttributes } from "react";
import { cn } from "@/lib/cn";

/**
 * A floating translucent panel — the app's default surface.
 *
 * Deliberately not opaque: the ambient wash behind the app shows through, which
 * is what makes a screen read as one surface with things resting on it rather
 * than a grid of white boxes. The `surface` class carries the blur, border and
 * shadow, so light and dark stay in step in one place (globals.css).
 */
export function Card({
  raised,
  className,
  ...props
}: HTMLAttributes<HTMLDivElement> & {
  /** More opaque and a deeper shadow, for a card that stands alone on the page. */
  raised?: boolean;
}) {
  /*
   * `raised` rather than passing `surface-strong` through className: both
   * classes set the same properties, so which one won would come down to their
   * order in the stylesheet, not the order they were written in. One or the
   * other, decided here.
   */
  /*
   * Vision UI's card: a 20px radius, 22px of padding, and the gradient-plus-blur
   * surface carried by `.vui-surface` — plus the lit hairline its GradientBorder
   * draws, reproduced as `.vui-edge`. `raised` keeps its meaning and simply
   * deepens the shadow, since the template has one card treatment rather than
   * two.
   */
  return (
    <div
      className={cn(
        "vui-surface vui-edge rounded-[var(--vui-r-xl)] p-[22px]",
        raised && "shadow-[var(--vui-shadow-lg)]",
        className,
      )}
      {...props}
    />
  );
}

/**
 * A card that is the point of the screen rather than one of several — the big
 * coloured tiles across the top of the reference dashboard.
 *
 * The solid fills are the loudest thing available and are meant to be counted
 * on one hand per screen; `plain` is the same shape in the ordinary frosted
 * surface, for a panel that should sit above its neighbours without shouting.
 */
export function FeatureCard({
  tone = "plain",
  className,
  ...props
}: HTMLAttributes<HTMLDivElement> & { tone?: "yellow" | "orange" | "ink" | "plain" }) {
  const tones = {
    yellow: "bg-brand-yellow text-ink border border-black/5",
    orange: "bg-brand-orange text-ink border border-black/5",
    /*
     * Ink inverts in dark mode. A near-black card is the loudest thing on the
     * sand background and the quietest on a near-black one — it vanished
     * entirely — so in the dark it becomes the pale card instead, which is the
     * same move (maximum contrast against the page) read the other way round.
     */
    ink: "bg-ink text-zinc-50 border border-white/10 dark:bg-zinc-100 dark:text-ink dark:border-black/10",
    plain: "vui-surface",
  } as const;

  return (
    <div
      className={cn(
        "relative overflow-hidden rounded-[var(--vui-r-xl)] p-[22px] shadow-[var(--vui-shadow-lg)]",
        tones[tone],
        className,
      )}
      {...props}
    />
  );
}
