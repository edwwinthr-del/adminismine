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
