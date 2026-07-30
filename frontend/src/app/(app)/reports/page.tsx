"use client";

import { useCallback, useEffect, useMemo, useState } from "react";
import { ApiError, apiFetch, downloadToDisk } from "@/lib/api";
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

const labelClass = "mb-1 block text-sm font-medium text-zinc-700 dark:text-zinc-300";

function currentMonth(): string {
  return todayISO().slice(0, 7);
}

export default function ReportsPage() {
  const { t } = useI18n();
  const { hasPermission } = useAuth();
  const [reports, setReports] = useState<ReportMeta[]>([]);
  const [selected, setSelected] = useState<string>("");
  const [filters, setFilters] = useState<Record<string, string>>({});
  const [data, setData] = useState<ReportData | null>(null);
  const [loading, setLoading] = useState(false);
  const [exporting, setExporting] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const canExport = hasPermission("reports.export");
  const report = useMemo(() => reports.find((r) => r.key === selected) ?? null, [reports, selected]);

  useEffect(() => {
    void apiFetch<{ data: ReportMeta[] }>("/reports")
      .then((res) => {
        setReports(res.data);
        if (res.data.length > 0) setSelected(res.data[0].key);
      })
      .catch((err) => setError(err instanceof ApiError ? err.message : "Error"));
  }, []);

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
        <h1 className="text-2xl font-semibold text-zinc-900 dark:text-zinc-50">{t("reports.title")}</h1>
        <p className="text-sm text-zinc-500">{t("reports.subtitle")}</p>
      </div>

      {error && <p className="text-sm text-red-600">{error}</p>}

      <Card className="space-y-4">
        <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
          <div className="sm:col-span-2">
            <label className={labelClass}>{t("reports.report")}</label>
            <Select value={selected} onChange={(e) => setSelected(e.target.value)}>
              {reports.map((row) => (
                <option key={row.key} value={row.key}>
                  {t(`report.${row.key}`)}
                </option>
              ))}
            </Select>
          </div>

          {report?.filters.includes("month") && (
            <div>
              <label className={labelClass}>{t("reports.month")}</label>
              <Input
                type="month"
                value={filters.month ?? ""}
                onChange={(e) => setFilter("month", e.target.value)}
              />
            </div>
          )}
          {report?.filters.includes("year") && (
            <div>
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
            <div>
              <label className={labelClass}>{t("reports.from")}</label>
              <Input
                type="date"
                value={filters.date_from ?? ""}
                onChange={(e) => setFilter("date_from", e.target.value)}
              />
            </div>
          )}
          {report?.filters.includes("date_to") && (
            <div>
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
              <div className="sm:col-span-2">
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
        </div>

        <div className="flex flex-wrap justify-end gap-2">
          <Button onClick={() => void run()} disabled={loading || !report}>
            {loading ? t("reports.running") : t("reports.run")}
          </Button>
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
        </div>
      </Card>

      {data && (
        <Card className="overflow-x-auto p-0">
          <p className="px-5 pb-3 pt-5 text-sm text-zinc-500">
            {t("reports.rowCount", { count: data.row_count })}
          </p>
          <table className="w-full min-w-[720px] text-sm">
            <thead className="border-b border-zinc-200 text-left text-xs uppercase tracking-wider text-zinc-500 dark:border-zinc-800">
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
            <tbody className="divide-y divide-zinc-100 dark:divide-zinc-800">
              {data.rows.length === 0 ? (
                <tr>
                  <td colSpan={data.columns.length} className="px-4 py-8 text-center text-zinc-500">
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
            {Object.keys(data.totals).length > 0 && data.rows.length > 0 && (
              <tfoot className="border-t border-zinc-300 dark:border-zinc-700">
                <tr className="font-medium text-zinc-900 dark:text-zinc-50">
                  {data.columns.map((column, index) => (
                    <td
                      key={column.key}
                      className={
                        column.type === "money" || column.type === "number"
                          ? "px-4 py-3 text-right tabular-nums"
                          : "px-4 py-3"
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
