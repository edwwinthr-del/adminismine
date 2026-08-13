"use client";

import { useCallback, useEffect, useMemo, useState } from "react";
import { ApiError, apiFetch, downloadToDisk } from "@/lib/api";
import { useResource } from "@/lib/data/use-resource";
import { useAuth } from "@/lib/auth/context";
import { useI18n } from "@/lib/i18n/context";
import { formatDate, formatMoney, todayISO } from "@/lib/format";
import { Button } from "@/components/ui/button";
import { Card } from "@/components/ui/card";
import { Input } from "@/components/ui/input";
import { Select } from "@/components/ui/select";

interface Column {
  key: string;
  label: string;
  type: string;
}

interface ReportMeta {
  key: string;
  filters: string[];
  columns: Column[];
  needs_signature: boolean;
  parties: Record<string, string> | null;
}

interface ReportData {
  key: string;
  columns: Column[];
  rows: Record<string, unknown>[];
  totals: Record<string, number>;
  row_count: number;
}

/*
 * Filters sit in one row of pills with the actions on the same line, the way the
 * reference lays a toolbar out — rather than a form grid with the buttons
 * stranded on a row of their own. The label is a small cap above each control:
 * a report can take a month, a year, a date range or a party, and unlike a
 * search box those are not self-describing from their contents alone.
 */
const labelClass = "mb-1.5 block px-4 text-[11px] font-medium uppercase tracking-[0.08em] text-zinc-500";

function currentMonth(): string {
  return todayISO().slice(0, 7);
}

export default function ReportsPage() {
  const { t } = useI18n();
  const { hasPermission } = useAuth();
  const [selected, setSelected] = useState<string>("");
  const [filters, setFilters] = useState<Record<string, string>>({});
  const [data, setData] = useState<ReportData | null>(null);
  const [loading, setLoading] = useState(false);
  const [exporting, setExporting] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const canExport = hasPermission("reports.export");

  // The catalogue of available reports is a fixed list, so it goes through the
  // cache. Running a report (below) stays an explicit user action and is
  // deliberately *not* a resource — it should fire when the button is pressed,
  // not whenever a filter changes.
  const { data: catalogue } = useResource<{ data: ReportMeta[] }>("/reports");
  const reports = useMemo(() => catalogue?.data ?? [], [catalogue]);

  // The first report is the default, derived rather than written into state on
  // arrival: storing it would mean an extra render pass, and `selected` only has
  // to hold a value once the user has actually picked one.
  const selectedKey = selected || reports[0]?.key || "";
  const report = useMemo(
    () => reports.find((r) => r.key === selectedKey) ?? null,
    [reports, selectedKey],
  );

  // Each report declares which filters it understands, so switching report
  // resets to sensible defaults rather than carrying stale ones across.
  useEffect(() => {
    if (!report) return;

    const next: Record<string, string> = {};
    if (report.filters.includes("month")) next.month = currentMonth();
    if (report.filters.includes("year")) next.year = String(new Date().getFullYear());
    setFilters(next);
    setData(null);
  }, [report]);

  const run = useCallback(async () => {
    if (!report) return;

    setLoading(true);
    setError(null);
    try {
      const query = new URLSearchParams(
        Object.entries(filters).filter(([, value]) => value !== ""),
      );
      const res = await apiFetch<{ data: ReportData }>(`/reports/${report.key}?${query.toString()}`);
      setData(res.data);
    } catch (err) {
      setError(err instanceof ApiError ? err.message : "Error");
    } finally {
      setLoading(false);
    }
  }, [report, filters]);

  async function exportAs(format: "xlsx" | "pdf") {
    if (!report) return;

    setExporting(true);
    setError(null);
    try {
      const query = new URLSearchParams(
        Object.entries(filters).filter(([, value]) => value !== ""),
      );
      query.set("format", format);
      // The title travels with the request so the file is in the user's language.
      query.set("title", t(`report.${report.key}`));

      await downloadToDisk(
        `/reports/${report.key}/export?${query.toString()}`,
        `${report.key}.${format}`,
      );
    } catch (err) {
      setError(err instanceof ApiError ? err.message : "Error");
    } finally {
      setExporting(false);
    }
  }

  function setFilter(key: string, value: string) {
    setFilters((prev) => ({ ...prev, [key]: value }));
  }

  function renderCell(value: unknown, type: string): string {
    if (value === null || value === undefined || value === "") return "—";
    if (type === "money") return formatMoney(Number(value));
    if (type === "number") return String(value);
    if (type === "date") return formatDate(String(value));
    if (type === "month") return String(value).slice(0, 7);
    // Enum-ish values get a translation when one exists; otherwise they show
    // as stored, never invented.
    const translated = t(`reportValue.${value}`);
    return translated === `reportValue.${value}` ? String(value) : translated;
  }

  return (
    <div className="space-y-5">
      <div>
        <h1 className="text-[2.5rem] font-light leading-none tracking-[-0.02em] text-zinc-900 dark:text-zinc-50">{t("reports.title")}</h1>
        <p className="text-sm text-zinc-500">{t("reports.subtitle")}</p>
      </div>

      {error && <p className="text-sm text-red-600">{error}</p>}

      <Card className="p-4">
        <div className="flex flex-wrap items-end gap-3">
          <div className="min-w-[16rem] flex-1">
            <label className={labelClass}>{t("reports.report")}</label>
            <Select value={selectedKey} onChange={(e) => setSelected(e.target.value)}>
              {reports.map((row) => (
                <option key={row.key} value={row.key}>
                  {t(`report.${row.key}`)}
                </option>
              ))}
            </Select>
          </div>

          {report?.filters.includes("month") && (
            <div className="w-[11rem]">
              <label className={labelClass}>{t("reports.month")}</label>
              <Input
                type="month"
                value={filters.month ?? ""}
                onChange={(e) => setFilter("month", e.target.value)}
              />
            </div>
          )}
          {report?.filters.includes("year") && (
            <div className="w-[8rem]">
              <label className={labelClass}>{t("reports.year")}</label>
              <Input
                type="number"
                min="2000"
                max="2100"
                value={filters.year ?? ""}
                onChange={(e) => setFilter("year", e.target.value)}
              />
            </div>
          )}
          {report?.filters.includes("date_from") && (
            <div className="w-[10.5rem]">
              <label className={labelClass}>{t("reports.from")}</label>
              <Input
                type="date"
                value={filters.date_from ?? ""}
                onChange={(e) => setFilter("date_from", e.target.value)}
              />
            </div>
          )}
          {report?.filters.includes("date_to") && (
            <div className="w-[10.5rem]">
              <label className={labelClass}>{t("reports.to")}</label>
              <Input
                type="date"
                value={filters.date_to ?? ""}
                onChange={(e) => setFilter("date_to", e.target.value)}
              />
            </div>
          )}
          {(report?.filters.includes("supplier_id") || report?.filters.includes("client_id")) &&
            report.parties && (
              <div className="min-w-[14rem] flex-1">
                <label className={labelClass}>
                  {report.filters.includes("supplier_id") ? t("reports.supplier") : t("reports.client")}
                </label>
                <Select
                  value={filters.supplier_id ?? filters.client_id ?? ""}
                  onChange={(e) =>
                    setFilter(
                      report.filters.includes("supplier_id") ? "supplier_id" : "client_id",
                      e.target.value,
                    )
                  }
                >
                  <option value="">{t("reports.choose")}</option>
                  {Object.entries(report.parties).map(([id, name]) => (
                    <option key={id} value={id}>
                      {name}
                    </option>
                  ))}
                </Select>
              </div>
            )}

          {/* Actions ride on the same line as the filters they act on. */}
          <div className="ml-auto flex flex-wrap items-center gap-2">
            {canExport && (
              <>
                <Button
                  variant="secondary"
                  disabled={exporting || !data}
                  onClick={() => void exportAs("xlsx")}
                >
                  {t("reports.exportExcel")}
                </Button>
                <Button
                  variant="secondary"
                  disabled={exporting || !data}
                  onClick={() => void exportAs("pdf")}
                >
                  {t("reports.exportPdf")}
                </Button>
              </>
            )}
            {/* The one yellow thing on the screen: what the page is for. */}
            <Button onClick={() => void run()} disabled={loading || !report}>
              {loading ? t("reports.running") : t("reports.run")}
            </Button>
          </div>
        </div>
      </Card>

      {/* Before anything has been run the page would otherwise be a toolbar over
          empty space; say what to do instead of showing nothing. */}
      {!data && !loading && (
        <Card className="flex flex-col items-center justify-center gap-3 px-6 py-16 text-center">
          <span className="grid h-12 w-12 place-items-center rounded-2xl bg-zinc-900/[0.05] text-zinc-400 dark:bg-white/10">
            <svg
              viewBox="0 0 24 24"
              className="h-6 w-6"
              fill="none"
              stroke="currentColor"
              strokeWidth={1.5}
              strokeLinecap="round"
              strokeLinejoin="round"
              aria-hidden
            >
              <path d="M4 20V10m5 10V4m5 16v-7m5 7V7" />
            </svg>
          </span>
          <p className="max-w-sm text-sm text-zinc-500">{t("reports.emptyHint")}</p>
        </Card>
      )}

      {data && (
        <Card className="table-quiet scroll-quiet overflow-x-auto p-0">
          {/* The result gets its own header naming what was run, so an exported
              sheet and the screen it came from are recognisably the same thing. */}
          <div className="flex flex-wrap items-baseline justify-between gap-2 px-5 pb-4 pt-5">
            <h2 className="text-lg font-medium tracking-tight text-zinc-900 dark:text-zinc-50">
              {report ? t(`report.${report.key}`) : ""}
            </h2>
            <span className="text-xs uppercase tracking-[0.08em] text-zinc-500">
              {t("reports.rowCount", { count: data.row_count })}
            </span>
          </div>
          <table className="w-full min-w-[720px] text-sm">
            <thead className="border-b border-zinc-900/8 text-left text-[11px] uppercase tracking-[0.1em] text-zinc-500 dark:border-white/10">
              <tr>
                {data.columns.map((column) => (
                  <th
                    key={column.key}
                    className={
                      column.type === "money" || column.type === "number"
                        ? "px-4 py-3 text-right"
                        : "px-4 py-3"
                    }
                  >
                    {t(`reportColumn.${column.label}`)}
                  </th>
                ))}
              </tr>
            </thead>
            <tbody className="divide-y divide-zinc-900/5 dark:divide-white/8">
              {data.rows.length === 0 ? (
                <tr>
                  <td colSpan={data.columns.length} className="px-4 py-14 text-center text-sm text-zinc-500">
                    {t("reports.noRows")}
                  </td>
                </tr>
              ) : (
                data.rows.map((row, index) => (
                  <tr key={index} className="text-zinc-800 dark:text-zinc-200">
                    {data.columns.map((column) => (
                      <td
                        key={column.key}
                        className={
                          column.type === "money" || column.type === "number"
                            ? "px-4 py-3 text-right tabular-nums"
                            : "px-4 py-3"
                        }
                      >
                        {renderCell(row[column.key], column.type)}
                      </td>
                    ))}
                  </tr>
                ))
              )}
            </tbody>
            {/* The total is set apart by weight and a tinted band rather than a
                heavier rule — the same way the reference separates a summary row
                from the rows it sums. */}
            {Object.keys(data.totals).length > 0 && data.rows.length > 0 && (
              <tfoot className="border-t border-zinc-900/10 bg-zinc-900/[0.03] dark:border-white/15 dark:bg-white/5">
                <tr className="font-semibold text-zinc-900 dark:text-zinc-50">
                  {data.columns.map((column, index) => (
                    <td
                      key={column.key}
                      className={
                        column.type === "money" || column.type === "number"
                          ? "px-4 py-3.5 text-right tabular-nums"
                          : "px-4 py-3.5"
                      }
                    >
                      {column.key in data.totals
                        ? formatMoney(data.totals[column.key])
                        : index === 0
                          ? t("reports.total")
                          : ""}
                    </td>
                  ))}
                </tr>
              </tfoot>
            )}
          </table>
        </Card>
      )}
    </div>
  );
}
