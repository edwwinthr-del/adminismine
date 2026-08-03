export function formatMoney(amount: number | null | undefined, currency = "EUR"): string {
  return new Intl.NumberFormat(undefined, { style: "currency", currency }).format(amount ?? 0);
}

export function formatDate(value?: string | null): string {
  if (!value) return "—";
  const date = new Date(value);
  if (Number.isNaN(date.getTime())) return value;
  return new Intl.DateTimeFormat(undefined, { year: "numeric", month: "short", day: "2-digit" }).format(date);
}

export function todayISO(): string {
  return new Date().toISOString().slice(0, 10);
}

/*
 * Money that has a direction.
 *
 * Direction in this app is the **sign of the amount** — the API stores a
 * movement out of the company as a negative number, and every balance is the
 * signed sum. So a figure only reads correctly when its sign and its colour come
 * from the same place, and that place is here: the dashboard tiles, the bank
 * ledger and the recent-movements table all render through these three helpers
 * rather than each deciding for itself what red means.
 *
 * The one deliberate exception is `expenseAmount()`. Totals like "Total
 * expenses" are published by the API as positive magnitudes — the sum of what
 * went out — because that is how an accountant reads a summary line. Displaying
 * that figure means putting the minus back, and it exists so a caller cannot
 * forget: a magnitude shown without its sign next to a Net that subtracted it is
 * exactly the confusion this replaces.
 */

/** How a signed figure is coloured. Neutral at zero: nothing moved. */
export function amountTone(amount: number | null | undefined): string {
  const value = amount ?? 0;

  if (value > 0) return "text-green-600 dark:text-green-400";
  if (value < 0) return "text-red-600 dark:text-red-400";

  return "text-zinc-400";
}

/**
 * A signed amount, always carrying its sign. `Intl` writes the minus itself;
 * money in is prefixed with `+` so income and expense are distinguishable at a
 * glance and not only by colour — colour alone is not something every reader can
 * use.
 */
export function formatSignedMoney(amount: number | null | undefined, currency = "EUR"): string {
  const value = amount ?? 0;
  const formatted = formatMoney(value, currency);

  return value > 0 ? `+${formatted}` : formatted;
}

/**
 * Turn an expense *magnitude* (as the API reports totals) into the negative
 * figure it represents, for display and colouring.
 */
export function expenseAmount(magnitude: number | null | undefined): number {
  return -Math.abs(magnitude ?? 0);
}
