"use client";

import Link from "next/link";
import { useState } from "react";
import { useResource } from "@/lib/data/use-resource";
import { useI18n } from "@/lib/i18n/context";
import { cn } from "@/lib/cn";
import { amountTone, expenseAmount, formatDate, formatMoney, formatSignedMoney, todayISO } from "@/lib/format";
import { CashflowChart, type CashflowPoint } from "@/components/charts/cashflow-chart";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Card, FeatureCard } from "@/components/ui/card";
import { Input } from "@/components/ui/input";
import { SetupPrompt } from "@/components/setup-prompt";
import { NavIcon } from "@/components/shell/nav-icons";

interface RecentTransaction {
  id: number;
  date: string;
  description: string | null;
  category: string | null;
  counterparty: string | null;
  amount: number;
  currency: string;
  possible_duplicate: boolean;
}

interface Dashboard {
  month: string;
  balances?: {
    /** `trend` is the account's balance at the end of each of the last six months. */
    accounts: { id: number; name: string; kind: string; balance: number; trend?: number[] }[];
    total: number;
    trend?: number[];
  };
  cashflow?: {
    month: { income: number; expenses: number; net: number };
    trend: CashflowPoint[];
  };
  payables?: { outstanding: number; overdue: number; count: number; overdue_count: number };
  receivables?: {
    outstanding: number;
    overdue: number;
    count: number;
    overdue_count: number;
    by_client: { client_id: number; name: string | null; outstanding: number; count: number }[];
  };
  housing?: {
    monthly_cost: number;
    unpaid_rent: number;
    unpaid_bills: number;
    overdue_bills_count: number;
  };
  recent_transactions?: RecentTransaction[];
  alerts: Record<string, number>;
}

/** Where each alert sends the user to do something about it. */
const ALERT_LINKS: Record<string, string> = {
  overdue_payables: "/payables?overdue=1",
  overdue_receivables: "/receivables?overdue=1",
  duplicate_entries: "/bank",
  unmatched_payments: "/bank",
  missing_invoice_numbers: "/payables",
};

/*
 * Column spans, written out.
 *
 * Every row on this page fills all twelve columns whatever the reader is
 * allowed to see — a section they lack the permission for is absent from the
 * payload entirely (DashboardService), and a row that simply dropped it would
 * leave a hole where a card used to be. So each row shares its twelve columns
 * out between the cards it actually got. The classes are looked up rather than
 * built (`lg:col-span-${n}` never survives Tailwind's scan of the source).
 */
const SPAN: Record<number, string> = {
  2: "lg:col-span-2",
  3: "lg:col-span-3",
  4: "lg:col-span-4",
  5: "lg:col-span-5",
  6: "lg:col-span-6",
  7: "lg:col-span-7",
  8: "lg:col-span-8",
  9: "lg:col-span-9",
  10: "lg:col-span-10",
  12: "lg:col-span-12",
};

/** Twelve columns divided between however many cards a row ended up with. */
function share(present: boolean[], preferred: number[]): number[] {
  const kept = preferred.filter((_, i) => present[i]);
  if (kept.length === 0) return preferred.map(() => 0);

  const total = kept.reduce((sum, n) => sum + n, 0);
  const scaled = kept.map((n) => Math.max(2, Math.round((n / total) * 12)));

  // Rounding rarely lands on twelve exactly; the widest card absorbs the rest.
  const drift = 12 - scaled.reduce((sum, n) => sum + n, 0);
  const widest = scaled.indexOf(Math.max(...scaled));
  scaled[widest] += drift;

  let next = 0;
  return preferred.map((_, i) => (present[i] ? scaled[next++] : 0));
}

function currentMonth(): string {
  return todayISO().slice(0, 7);
}

/** "just now" for the first minute, then a plain clock time. */
function updatedLabel(at: number | null, justNow: string): string {
  if (at === null) return "";

  return Date.now() - at < 60_000
    ? justNow
    : new Date(at).toLocaleTimeString(undefined, { hour: "2-digit", minute: "2-digit" });
}

export default function DashboardPage() {
  const { t } = useI18n();
  const [month, setMonth] = useState(currentMonth);

  /*
   * Every figure below is derived from records that are created and edited on
   * other pages. `useResource` refetches on any write in the app and whenever
   * the tab is refocused, so what is shown here cannot lag behind the database
   * — and the timestamp says plainly when it was last read.
   */
  const { data, loading, refreshing, error, updatedAt, refresh } = useResource<{ data: Dashboard }>(
    `/dashboard?month=${month}`,
  );
  const dashboard = data?.data;

  const raised = Object.entries(dashboard?.alerts ?? {}).filter(([, count]) => count > 0);

  /*
   * The hero answers the question the app is opened for — what have we got —
   * and the three tiles beside it are what is owed, what is owing and what the
   * month came to. Everything the eleven tiles used to carry is still on the
   * page; the account balances, the housing figures and the per-client
   * breakdown moved into the panel beside the chart, where they read as a
   * ranked list rather than as eight more boxes of the same size.
   */
  const topSpans = share(
    [!!dashboard?.balances, !!dashboard?.payables, !!dashboard?.receivables, !!dashboard?.cashflow],
    [5, 2, 2, 3],
  );

  const hasSidePanel = !!dashboard?.balances || !!dashboard?.housing || !!dashboard?.receivables;
  const midSpans = share([!!dashboard?.cashflow, hasSidePanel], [8, 4]);
  const bottomSpans = share([!!dashboard?.recent_transactions, true], [7, 5]);

  return (
    <div className="space-y-6">
      {/* The title and greeting moved into the navbar with the breadcrumb, so
          this row now carries only the controls and sits to the right. */}
      <div className="flex flex-wrap items-center justify-end gap-3">
        <div className="flex items-center gap-2">
          {updatedAt && (
            <span className="text-xs text-zinc-500">
              {t("common.updatedAt", { time: updatedLabel(updatedAt, t("common.justNow")) })}
            </span>
          )}
          <Button variant="secondary" className="h-10 px-3" disabled={refreshing} onClick={() => void refresh()}>
            {t("common.refresh")}
          </Button>
          <Input className="w-[10rem]" type="month" value={month} onChange={(e) => setMonth(e.target.value)} />
        </div>
      </div>

      {error && <p className="text-sm text-red-600">{error}</p>}

      {/* Shown until somebody says what shape of company this is. A prompt
          rather than a redirect: an install can be perfectly usable without one,
          and trapping a user on a setup screen is worse than asking twice. */}
      <SetupPrompt />

      {/* Held at reduced opacity while refetching rather than flashing skeletons. */}
      <div className={refreshing ? "opacity-60 transition-opacity" : "transition-opacity"}>
        {loading ? (
          <p className="text-sm text-zinc-500">{t("common.loading")}</p>
        ) : (
          <div className="space-y-6">
            {/* ---- what we have, and the three figures around it ---------- */}
            <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-12">
              {dashboard?.balances && (
                <div className={cn("sm:col-span-2", SPAN[topSpans[0]])}>
                  <BalanceHero
                    total={dashboard.balances.total}
                    accounts={dashboard.balances.accounts}
                    net={dashboard.cashflow?.month.net}
                    trend={dashboard.balances.trend}
                  />
                </div>
              )}

              {dashboard?.payables && (
                <div className={SPAN[topSpans[1]]}>
                  <Tile
                    label={t("dashboard.unpaidSuppliers")}
                    icon="nav.payables"
                    value={formatMoney(dashboard.payables.outstanding)}
                    hint={t("dashboard.overdueHint", { amount: formatMoney(dashboard.payables.overdue) })}
                    href="/payables"
                  />
                </div>
              )}

              {dashboard?.receivables && (
                <div className={SPAN[topSpans[2]]}>
                  <Tile
                    label={t("dashboard.receivables")}
                    icon="nav.receivables"
                    value={formatMoney(dashboard.receivables.outstanding)}
                    hint={t("dashboard.overdueHint", { amount: formatMoney(dashboard.receivables.overdue) })}
                    href="/receivables"
                  />
                </div>
              )}

              {dashboard?.cashflow && (
                <div className={SPAN[topSpans[3]]}>
                  {/*
                    The three figures of one sum, in one card rather than three:
                    income adds, expenses subtract, net is what is left.
                    `expenses` arrives as a positive magnitude — expenseAmount
                    puts back the minus it is rolled into Net with, so a reader
                    never has to guess whether it was added or taken away.
                  */}
                  <NetTile month={dashboard.cashflow.month} />
                </div>
              )}
            </div>

            {/* ---- the trend, and where the money actually sits ----------- */}
            <div className="grid grid-cols-1 gap-4 lg:grid-cols-12">
              {dashboard?.cashflow && (
                <Card className={cn("flex flex-col", SPAN[midSpans[0]])}>
                  <p className="mb-1 text-sm font-semibold text-zinc-900 dark:text-zinc-50">
                    {t("dashboard.cashflowTitle")}
                  </p>
                  <p className="mb-3 text-xs text-zinc-500">{t("dashboard.cashflowHint")}</p>
                  <CashflowChart points={dashboard.cashflow.trend} />
                </Card>
              )}

              {hasSidePanel && (
                <Card className={SPAN[midSpans[1]]}>
                  <p className="text-sm font-semibold text-zinc-900 dark:text-zinc-50">
                    {t("dashboard.moneySits")}
                  </p>

                  {dashboard?.balances && dashboard.balances.accounts.length > 0 && (
                    <div className="mt-4 space-y-3">
                      {dashboard.balances.accounts.map((account) => (
                        <BarRow
                          key={account.id}
                          label={account.name}
                          value={formatMoney(account.balance)}
                          tone={amountTone(account.balance)}
                          share={shareOf(account.balance, dashboard.balances!.accounts.map((a) => a.balance))}
                          fill="bg-brand-yellow"
                        />
                      ))}
                    </div>
                  )}

                  {dashboard?.housing && (
                    <div className="mt-5 border-t border-zinc-900/8 pt-4 dark:border-white/10">
                      <p className="text-[11px] uppercase tracking-[0.1em] text-zinc-500">{t("nav.housing")}</p>
                      <div className="mt-3 space-y-3">
                        {(
                          [
                            ["dashboard.housingCost", dashboard.housing.monthly_cost, undefined],
                            ["dashboard.unpaidRent", dashboard.housing.unpaid_rent, undefined],
                            [
                              "dashboard.unpaidBills",
                              dashboard.housing.unpaid_bills,
                              t("dashboard.overdueBillsHint", { count: dashboard.housing.overdue_bills_count }),
                            ],
                          ] as const
                        ).map(([key, amount, hint]) => (
                          <BarRow
                            key={key}
                            label={t(key)}
                            value={formatMoney(amount)}
                            hint={hint}
                            share={shareOf(amount, [
                              dashboard.housing!.monthly_cost,
                              dashboard.housing!.unpaid_rent,
                              dashboard.housing!.unpaid_bills,
                            ])}
                            fill="bg-brand-orange"
                          />
                        ))}
                      </div>
                    </div>
                  )}

                  {dashboard?.receivables && dashboard.receivables.by_client.length > 0 && (
                    <div className="mt-5 border-t border-zinc-900/8 pt-4 dark:border-white/10">
                      <p className="text-[11px] uppercase tracking-[0.1em] text-zinc-500">
                        {t("dashboard.byClient")}
                      </p>
                      <div className="mt-3 space-y-3">
                        {dashboard.receivables.by_client.map((row) => (
                          <BarRow
                            key={row.client_id}
                            label={row.name ?? "—"}
                            value={formatMoney(row.outstanding)}
                            share={shareOf(
                              row.outstanding,
                              dashboard.receivables!.by_client.map((c) => c.outstanding),
                            )}
                            fill="bg-indigo-500 dark:bg-indigo-300"
                          />
                        ))}
                      </div>
                    </div>
                  )}
                </Card>
              )}
            </div>

            {/* ---- what happened, and what still needs doing -------------- */}
            <div className="grid grid-cols-1 gap-4 lg:grid-cols-12">
              {dashboard?.recent_transactions && (
                <Card className={cn("vui-table scroll-quiet overflow-x-auto p-0", SPAN[bottomSpans[0]])}>
                  <p className="px-[var(--vui-pad-card)] pb-3 pt-[var(--vui-pad-card)] text-sm font-semibold text-zinc-900 dark:text-zinc-50">
                    {t("dashboard.recentTransactions")}
                  </p>
                  <table className="w-full min-w-[560px] text-sm">
                    <thead>
                      <tr>
                        <th>{t("dashboard.date")}</th>
                        <th>{t("dashboard.description")}</th>
                        <th>{t("dashboard.counterparty")}</th>
                        <th className="text-right">{t("dashboard.amount")}</th>
                      </tr>
                    </thead>
                    <tbody>
                      {dashboard.recent_transactions.length === 0 ? (
                        <tr>
                          <td colSpan={4} className="py-8 text-center text-zinc-500">
                            {t("dashboard.noTransactions")}
                          </td>
                        </tr>
                      ) : (
                        dashboard.recent_transactions.map((transaction) => (
                          <tr key={transaction.id} className="text-zinc-800 dark:text-zinc-200">
                            <td>{formatDate(transaction.date)}</td>
                            <td>
                              <span className="flex flex-wrap items-center gap-2">
                                <span>{transaction.description ?? "—"}</span>
                                {transaction.possible_duplicate && (
                                  <Badge tone="amber">{t("dashboard.possibleDuplicate")}</Badge>
                                )}
                              </span>
                            </td>
                            <td className="text-zinc-600 dark:text-zinc-300">
                              {transaction.counterparty ?? "—"}
                            </td>
                            <td className={`text-right tabular-nums ${amountTone(transaction.amount)}`}>
                              {formatSignedMoney(transaction.amount, transaction.currency)}
                            </td>
                          </tr>
                        ))
                      )}
                    </tbody>
                  </table>
                </Card>
              )}

              {/*
                The alerts used to be a full-width band of chips above the fold.
                As a panel they hold the same rows, keep this row filled, and —
                when nothing is wrong — say so, which a band that simply vanished
                could not: an absent alert list and a clean one look identical.
              */}
              <Card className={SPAN[bottomSpans[1]]}>
                <p className="text-sm font-semibold text-zinc-900 dark:text-zinc-50">{t("dashboard.alerts")}</p>

                {raised.length === 0 ? (
                  <div className="mt-4 flex items-center gap-3 text-sm text-zinc-500">
                    <span className="grid h-9 w-9 shrink-0 place-items-center rounded-[var(--vui-r-button)] bg-zinc-900/[0.05] text-zinc-600 dark:bg-white/[0.08] dark:text-zinc-300">
                      <svg viewBox="0 0 24 24" aria-hidden className="h-4 w-4" fill="none" stroke="currentColor" strokeWidth={2} strokeLinecap="round">
                        <path d="M5 13l4 4L19 7" />
                      </svg>
                    </span>
                    {t("dashboard.allClear")}
                  </div>
                ) : (
                  <ul className="mt-2">
                    {raised.map(([key, count]) => (
                      <li key={key} className="border-t border-zinc-900/5 first:border-t-0 dark:border-white/8">
                        <Link
                          href={ALERT_LINKS[key] ?? "/dashboard"}
                          className="focus-ink -mx-2 flex items-center gap-3 rounded-[var(--vui-r-button)] px-2 py-3 transition-colors hover:bg-zinc-900/[0.04] dark:hover:bg-white/[0.06]"
                        >
                          <span className="grid h-9 min-w-9 shrink-0 place-items-center rounded-[var(--vui-r-button)] bg-amber-400/25 px-1.5 text-sm font-semibold tabular-nums text-amber-900 dark:bg-amber-400/20 dark:text-amber-200">
                            {count}
                          </span>
                          <span className="text-sm text-zinc-800 dark:text-zinc-200">
                            {t(`dashboardAlert.${key}`)}
                          </span>
                        </Link>
                      </li>
                    ))}
                  </ul>
                )}
              </Card>
            </div>
          </div>
        )}
      </div>
    </div>
  );
}

/**
 * Whether a series is worth drawing at all.
 *
 * A flat line is a claim ("measured, unchanged") that an account nothing has
 * ever moved through should not be making, and a series that is flat until its
 * last point draws a hard diagonal wedge that reads as a rendering fault. Two
 * distinct values is the floor.
 */
function hasShape(series?: number[]): boolean {
  if (!series || series.length < 3) return false;

  return new Set(series).size >= 2;
}

/** A value's share of the largest figure beside it, for a bar's width. */
function shareOf(value: number, all: number[]): number {
  const largest = Math.max(...all.map((n) => Math.abs(n)), 0);

  return largest === 0 ? 0 : Math.abs(value) / largest;
}

/**
 * The one card the page is opened for.
 *
 * Vision UI gives its dashboard a single loud card and keeps every other figure
 * quiet around it; this is that card, carrying the total, what the month came
 * to, and each account underneath — so the accounts are still readable at a
 * glance without being four more tiles of the same size as everything else.
 */
function BalanceHero({
  total,
  accounts,
  net,
  trend,
}: {
  total: number;
  accounts: { id: number; name: string; balance: number; trend?: number[] }[];
  net?: number;
  /** The total balance at each of the last six month ends. */
  trend?: number[];
}) {
  const { t } = useI18n();

  return (
    <FeatureCard tone="yellow" className="relative h-full overflow-hidden p-[22px]">
      {/*
        The balance itself over six months, behind the figure it belongs to —
        `BankTransaction::accountBalanceHistory()`, run forward from everything
        that happened before the window. Decoration that is also true.
      */}
      {hasShape(trend) && <Sparkline points={trend!} />}

      <div className="relative">
        <p className="text-[11px] uppercase tracking-[0.1em] text-ink/55">{t("dashboard.totalBalance")}</p>

        <div className="flex flex-wrap items-end gap-x-3 gap-y-2">
          <p className="mt-1 text-[2.5rem] font-light leading-none tracking-tight">{formatMoney(total)}</p>
          {net !== undefined && (
            <span className="mb-1 inline-flex items-center gap-1.5 rounded-full bg-ink/10 px-2.5 py-1 text-xs font-semibold">
              <svg
                viewBox="0 0 24 24"
                aria-hidden
                className={cn("h-3 w-3", net < 0 && "rotate-180")}
                fill="none"
                stroke="currentColor"
                strokeWidth={2.5}
                strokeLinecap="round"
              >
                <path d="M12 19V5M6 11l6-6 6 6" />
              </svg>
              {formatSignedMoney(net)} · {t("dashboard.monthlyNet")}
            </span>
          )}
        </div>

        <div className="mt-5 grid gap-2 sm:grid-cols-2 xl:grid-cols-3">
          {accounts.map((account) => (
            <div key={account.id} className="rounded-[var(--vui-r-lg)] bg-ink/[0.08] px-3 py-2.5">
              <p className="truncate text-[10px] uppercase tracking-[0.08em] text-ink/55">{account.name}</p>
              <p className="mt-0.5 text-[17px] font-semibold tabular-nums">{formatMoney(account.balance)}</p>
              {/* An account that has never moved gets no line: a flat rule under
                  a figure says "measured and unchanged", which is a claim of its
                  own and not one the empty case should make. */}
              {hasShape(account.trend) && <MiniLine points={account.trend!} />}
            </div>
          ))}
        </div>
      </div>
    </FeatureCard>
  );
}

/** The month's three figures in one card: income, expenses, and what is left. */
function NetTile({ month }: { month: { income: number; expenses: number; net: number } }) {
  const { t } = useI18n();
  const outgoing = expenseAmount(month.expenses);

  return (
    <Card className="flex h-full flex-col justify-between p-[17px]">
      <div className="flex items-start justify-between gap-3">
        <div className="min-w-0">
          <p className="text-[11px] uppercase tracking-[0.1em] text-zinc-500">{t("dashboard.monthlyNet")}</p>
          <p className={cn("mt-2 truncate text-[2rem] font-light leading-none tracking-tight", amountTone(month.net))}>
            {formatSignedMoney(month.net)}
          </p>
        </div>
        <IconChip name="nav.exchangeRates" />
      </div>

      <div className="mt-4 flex flex-wrap gap-x-6 gap-y-2">
        <div>
          <p className="text-[11px] text-zinc-500">{t("dashboard.income")}</p>
          <p className={cn("text-sm font-semibold tabular-nums", amountTone(month.income))}>
            {formatSignedMoney(month.income)}
          </p>
        </div>
        <div>
          <p className="text-[11px] text-zinc-500">{t("dashboard.expenses")}</p>
          <p className={cn("text-sm font-semibold tabular-nums", amountTone(outgoing))}>
            {formatSignedMoney(outgoing)}
          </p>
        </div>
      </div>
    </Card>
  );
}

/** A label, its figure, and a bar showing it against the largest one beside it. */
function BarRow({
  label,
  value,
  hint,
  share,
  fill,
  tone,
}: {
  label: string;
  value: string;
  hint?: string;
  /** 0–1, against the largest figure in the same group. */
  share: number;
  fill: string;
  tone?: string;
}) {
  return (
    <div>
      <div className="flex items-baseline justify-between gap-3 text-sm">
        <span className="truncate text-zinc-700 dark:text-zinc-300">{label}</span>
        <span className={cn("shrink-0 font-semibold tabular-nums", tone ?? "text-zinc-900 dark:text-zinc-50")}>
          {value}
        </span>
      </div>
      <div className="mt-1.5 h-1.5 overflow-hidden rounded-full bg-zinc-900/[0.07] dark:bg-white/10">
        {/*
          A figure of zero still gets a hairline: a bar of no width reads as a
          missing row rather than as an empty one.
        */}
        <span
          className={cn("block h-full rounded-full", fill)}
          style={{ width: `${Math.max(2, Math.round(share * 100))}%` }}
        />
      </div>
      {hint && <p className="mt-1 text-[11px] text-zinc-500">{hint}</p>}
    </div>
  );
}

/** One account's line, inside its chip on the hero: 20px tall, no fill. */
function MiniLine({ points }: { points: number[] }) {
  return (
    <svg
      viewBox="0 0 100 20"
      preserveAspectRatio="none"
      aria-hidden
      className="mt-1.5 h-5 w-full"
    >
      <path
        d={linePath(points, 20, 2)}
        fill="none"
        stroke="rgb(13 12 11 / 0.35)"
        strokeWidth={1.5}
        strokeLinejoin="round"
        strokeLinecap="round"
        vectorEffect="non-scaling-stroke"
      />
    </svg>
  );
}

/** A series as an SVG path across a 100-wide box of `height`, inset by `pad`. */
function linePath(points: number[], height: number, pad: number): string {
  const high = Math.max(...points);
  const low = Math.min(...points);
  const span = high - low || 1;
  const step = 100 / (points.length - 1);

  return points
    .map((value, index) => {
      const x = (index * step).toFixed(2);
      const y = (height - pad - ((value - low) / span) * (height - pad * 2)).toFixed(2);

      return `${index === 0 ? "M" : "L"}${x} ${y}`;
    })
    .join(" ");
}

/** The trend as a shape rather than a chart — no axis, no labels, no hover. */
function Sparkline({ points }: { points: number[] }) {
  const path = linePath(points, 32, 4);

  return (
    <svg
      viewBox="0 0 100 32"
      preserveAspectRatio="none"
      aria-hidden
      className="pointer-events-none absolute inset-x-0 bottom-0 h-24 w-full"
    >
      {/* A line, not an area. Filled, a balance that sat at zero and then
          jumped draws a hard triangular wedge across the card — which reads as a
          folded corner rather than as the shape of the year. */}
      <path
        d={path}
        fill="none"
        stroke="rgb(13 12 11 / 0.28)"
        strokeWidth={1.5}
        strokeLinejoin="round"
        strokeLinecap="round"
        vectorEffect="non-scaling-stroke"
      />
    </svg>
  );
}

/**
 * The template's 48px icon tile at the 15px radius with its own shadow.
 *
 * Neutral, not yellow: the fill is the loudest thing on the page and a row of
 * tiles each carrying it made none of them stand out. Yellow is the hero's now.
 */
function IconChip({ name }: { name: string }) {
  return (
    <span className="grid h-10 w-10 shrink-0 place-items-center rounded-[var(--vui-r-lg)] bg-zinc-900/[0.05] text-zinc-600 shadow-[var(--vui-shadow-button)] dark:bg-white/[0.08] dark:text-zinc-300">
      <NavIcon name={name} className="h-[18px] w-[18px]" />
    </span>
  );
}

/**
 * Vision UI's MiniStatisticsCard: label over value, with a rounded icon tile
 * set against them. 17px of padding rather than the card default, which is what
 * keeps a row of these compact enough to scan.
 *
 * The icon is keyed by the same `nav.*` name the sidebar uses, so a tile
 * borrows the glyph of the module its figure comes from and there is no second
 * icon set to maintain.
 */
function Tile({
  label,
  value,
  hint,
  href,
  tone,
  icon,
}: {
  label: string;
  value: string;
  hint?: string;
  href?: string;
  /** A `nav.*` key, rendered through the sidebar's icon set. */
  icon?: string;
  /** Colour for a figure that has a direction; from lib/format's amountTone. */
  tone?: string;
}) {
  const body = (
    <Card
      className={cn(
        "flex h-full flex-col justify-between p-[17px]",
        href && "transition-transform duration-150 hover:-translate-y-0.5",
      )}
    >
      <div className="flex items-start justify-between gap-2">
        <p className="text-[11px] uppercase tracking-[0.1em] text-zinc-500">{label}</p>
        {icon && <IconChip name={icon} />}
      </div>

      <div className="mt-3">
        {/* Proportional figures: tabular-nums would make a standalone value look loose. */}
        <p
          className={cn(
            "truncate text-2xl font-light leading-none tracking-tight",
            tone ?? "text-zinc-900 dark:text-zinc-50",
          )}
        >
          {value}
        </p>
        {hint && <p className="mt-2 text-xs text-zinc-500">{hint}</p>}
      </div>
    </Card>
  );

  return href ? (
    <Link href={href} className="block h-full">
      {body}
    </Link>
  ) : (
    body
  );
}
