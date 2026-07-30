"use client";

import Link from "next/link";
import { useState } from "react";
import { useAuth } from "@/lib/auth/context";
import { useResource } from "@/lib/data/use-resource";
import { useI18n } from "@/lib/i18n/context";
import { formatDate, formatMoney, todayISO } from "@/lib/format";
import { CashflowChart, type CashflowPoint } from "@/components/charts/cashflow-chart";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Card } from "@/components/ui/card";
import { Input } from "@/components/ui/input";

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
  balances?: { cash: number; nlb: number; lovcen: number; total: number };
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
  const { user } = useAuth();
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

  return (
    <div className="space-y-6">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <div>
          <h1 className="text-2xl font-semibold text-zinc-900 dark:text-zinc-50">{t("dashboard.title")}</h1>
          <p className="text-sm text-zinc-500">{t("dashboard.welcome", { name: user?.name ?? "" })}</p>
        </div>
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

      {/* Held at reduced opacity while refetching rather than flashing skeletons. */}
      <div className={refreshing ? "opacity-60 transition-opacity" : "transition-opacity"}>
        {loading ? (
          <p className="text-sm text-zinc-500">{t("common.loading")}</p>
        ) : (
          <div className="space-y-6">
            <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
              {dashboard?.balances && (
                <>
                  <Tile label={t("dashboard.cash")} value={formatMoney(dashboard.balances.cash)} />
                  <Tile label={t("dashboard.nlb")} value={formatMoney(dashboard.balances.nlb)} />
                  <Tile label={t("dashboard.lovcen")} value={formatMoney(dashboard.balances.lovcen)} />
                  <Tile
                    label={t("dashboard.totalBalance")}
                    value={formatMoney(dashboard.balances.total)}
                    emphasis
                  />
                </>
              )}
              {dashboard?.payables && (
                <Tile
                  label={t("dashboard.unpaidSuppliers")}
                  value={formatMoney(dashboard.payables.outstanding)}
                  hint={t("dashboard.overdueHint", { amount: formatMoney(dashboard.payables.overdue) })}
                  href="/payables"
                />
              )}
              {dashboard?.receivables && (
                <Tile
                  label={t("dashboard.receivables")}
                  value={formatMoney(dashboard.receivables.outstanding)}
                  hint={t("dashboard.overdueHint", { amount: formatMoney(dashboard.receivables.overdue) })}
                  href="/receivables"
                />
              )}
              {dashboard?.cashflow && (
                <>
                  <Tile
                    label={t("dashboard.monthlyIncome")}
                    value={formatMoney(dashboard.cashflow.month.income)}
                  />
                  <Tile
                    label={t("dashboard.monthlyExpenses")}
                    value={formatMoney(dashboard.cashflow.month.expenses)}
                  />
                </>
              )}
              {dashboard?.housing && (
                <>
                  <Tile
                    label={t("dashboard.housingCost")}
                    value={formatMoney(dashboard.housing.monthly_cost)}
                    href="/housing"
                  />
                  <Tile
                    label={t("dashboard.unpaidRent")}
                    value={formatMoney(dashboard.housing.unpaid_rent)}
                    href="/housing"
                  />
                  <Tile
                    label={t("dashboard.unpaidBills")}
                    value={formatMoney(dashboard.housing.unpaid_bills)}
                    hint={t("dashboard.overdueBillsHint", { count: dashboard.housing.overdue_bills_count })}
                    href="/housing"
                  />
                </>
              )}
            </div>

            {raised.length > 0 && (
              <Card>
                <p className="mb-3 text-sm font-semibold text-zinc-900 dark:text-zinc-50">
                  {t("dashboard.alerts")}
                </p>
                <ul className="flex flex-wrap gap-2">
                  {raised.map(([key, count]) => (
                    <li key={key}>
                      <Link
                        href={ALERT_LINKS[key] ?? "/dashboard"}
                        className="inline-flex items-center gap-2 rounded-full border border-amber-300 bg-amber-50 px-3 py-1 text-xs font-medium text-amber-800 transition-colors hover:bg-amber-100 dark:border-amber-900 dark:bg-amber-950 dark:text-amber-300 dark:hover:bg-amber-900"
                      >
                        <span className="tabular-nums">{count}</span>
                        {t(`dashboardAlert.${key}`)}
                      </Link>
                    </li>
                  ))}
                </ul>
              </Card>
            )}

            <div className="grid gap-4 lg:grid-cols-3">
              {dashboard?.cashflow && (
                <Card className="lg:col-span-2">
                  <p className="mb-1 text-sm font-semibold text-zinc-900 dark:text-zinc-50">
                    {t("dashboard.cashflowTitle")}
                  </p>
                  <p className="mb-3 text-xs text-zinc-500">{t("dashboard.cashflowHint")}</p>
                  <CashflowChart points={dashboard.cashflow.trend} />
                </Card>
              )}

              {dashboard?.receivables && dashboard.receivables.by_client.length > 0 && (
                <Card>
                  <p className="mb-3 text-sm font-semibold text-zinc-900 dark:text-zinc-50">
                    {t("dashboard.byClient")}
                  </p>
                  <ul className="space-y-2">
                    {dashboard.receivables.by_client.map((row) => (
                      <li
                        key={row.client_id}
                        className="flex items-center justify-between gap-3 text-sm text-zinc-700 dark:text-zinc-300"
                      >
                        <span className="truncate">{row.name ?? "—"}</span>
                        <span className="tabular-nums font-medium text-zinc-900 dark:text-zinc-50">
                          {formatMoney(row.outstanding)}
                        </span>
                      </li>
                    ))}
                  </ul>
                </Card>
              )}
            </div>

            {dashboard?.recent_transactions && (
              <Card className="overflow-x-auto p-0">
                <p className="px-5 pb-3 pt-5 text-sm font-semibold text-zinc-900 dark:text-zinc-50">
                  {t("dashboard.recentTransactions")}
                </p>
                <table className="w-full min-w-[640px] text-sm">
                  <thead className="border-b border-zinc-200 text-left text-xs uppercase tracking-wider text-zinc-500 dark:border-zinc-800">
                    <tr>
                      <th className="px-5 py-3">{t("dashboard.date")}</th>
                      <th className="px-5 py-3">{t("dashboard.description")}</th>
                      <th className="px-5 py-3">{t("dashboard.counterparty")}</th>
                      <th className="px-5 py-3 text-right">{t("dashboard.amount")}</th>
                    </tr>
                  </thead>
                  <tbody className="divide-y divide-zinc-100 dark:divide-zinc-800">
                    {dashboard.recent_transactions.length === 0 ? (
                      <tr>
                        <td colSpan={4} className="px-5 py-8 text-center text-zinc-500">
                          {t("dashboard.noTransactions")}
                        </td>
                      </tr>
                    ) : (
                      dashboard.recent_transactions.map((transaction) => (
                        <tr key={transaction.id} className="text-zinc-800 dark:text-zinc-200">
                          <td className="px-5 py-3">{formatDate(transaction.date)}</td>
                          <td className="px-5 py-3">
                            <span className="flex flex-wrap items-center gap-2">
                              <span>{transaction.description ?? "—"}</span>
                              {transaction.possible_duplicate && (
                                <Badge tone="amber">{t("dashboard.possibleDuplicate")}</Badge>
                              )}
                            </span>
                          </td>
                          <td className="px-5 py-3 text-zinc-600 dark:text-zinc-300">
                            {transaction.counterparty ?? "—"}
                          </td>
                          <td className="px-5 py-3 text-right tabular-nums">
                            {formatMoney(transaction.amount, transaction.currency)}
                          </td>
                        </tr>
                      ))
                    )}
                  </tbody>
                </table>
              </Card>
            )}
          </div>
        )}
      </div>
    </div>
  );
}

function Tile({
  label,
  value,
  hint,
  href,
  emphasis,
}: {
  label: string;
  value: string;
  hint?: string;
  href?: string;
  emphasis?: boolean;
}) {
  const body = (
    <Card className={href ? "h-full p-4 transition-colors hover:border-indigo-300" : "h-full p-4"}>
      <p className="text-xs uppercase tracking-wider text-zinc-500">{label}</p>
      {/* Proportional figures: tabular-nums would make a standalone value look loose. */}
      <p
        className={
          emphasis
            ? "mt-1 text-2xl font-semibold text-zinc-900 dark:text-zinc-50"
            : "mt-1 text-xl font-semibold text-zinc-900 dark:text-zinc-50"
        }
      >
        {value}
      </p>
      {hint && <p className="mt-1 text-xs text-zinc-500">{hint}</p>}
    </Card>
  );

  return href ? (
    <Link href={href} className="block">
      {body}
    </Link>
  ) : (
    body
  );
}
