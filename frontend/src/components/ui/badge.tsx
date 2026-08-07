import { cn } from "@/lib/cn";

type Tone = "gray" | "green" | "amber" | "red" | "indigo";

/*
 * The reference states status as a solid pill rather than a tinted label. These
 * are pitched a little softer than the reference's fully saturated fills,
 * because a financial table shows a status on every row — at that density,
 * solid colour on hundreds of rows stops meaning "look here". `amber` is the
 * brand orange, which is the one that does get the loud treatment.
 */
const tones: Record<Tone, string> = {
  gray: "bg-zinc-900/[0.06] text-zinc-600 ring-1 ring-inset ring-zinc-900/5 dark:bg-white/10 dark:text-zinc-300 dark:ring-white/10",
  green: "bg-emerald-500/15 text-emerald-700 ring-1 ring-inset ring-emerald-600/20 dark:bg-emerald-400/15 dark:text-emerald-300 dark:ring-emerald-400/25",
  amber: "bg-brand-orange text-ink ring-1 ring-inset ring-black/5",
  red: "bg-red-500/15 text-red-700 ring-1 ring-inset ring-red-600/20 dark:bg-red-400/15 dark:text-red-300 dark:ring-red-400/25",
  indigo: "bg-ink text-zinc-50 ring-1 ring-inset ring-white/10 dark:bg-white/90 dark:text-ink",
};

export function Badge({ tone = "gray", children }: { tone?: Tone; children: React.ReactNode }) {
  return (
    <span
      className={cn(
        "inline-flex items-center gap-1 rounded-full px-2.5 py-0.5 text-xs font-medium whitespace-nowrap",
        tones[tone],
      )}
    >
      {children}
    </span>
  );
}
