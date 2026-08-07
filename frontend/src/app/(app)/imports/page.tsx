"use client";

import { useCallback, useEffect, useState } from "react";
import { apiFetch, downloadToDisk, errorMessage } from "@/lib/api";
import { useResource } from "@/lib/data/use-resource";
import { useI18n } from "@/lib/i18n/context";
import { formatDate } from "@/lib/format";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Card } from "@/components/ui/card";
import { Select } from "@/components/ui/select";

interface ImportColumn {
  key: string;
  header: string;
  type: string;
  required: boolean;
  values: string[];
  example: string | null;
  help: string | null;
  format: string;
}

interface ImportEntity {
  key: string;
  label: string;
  target: string;
  description: string | null;
  columns: ImportColumn[];
}

interface SheetSummary {
  sheet: string;
  rows: number;
  targets?: Record<string, number>;
  skipped: boolean;
  missing_columns?: string[];
  unknown_columns?: string[];
}

interface Batch {
  id: number;
  original_name: string;
  entity: string | null;
  status: string;
  sheet_summary: SheetSummary[];
  totals: {
    rows?: number;
    incomplete?: number;
    duplicates?: number;
    unknown_labels?: number;
    unmapped_sheets?: number;
    invalid?: number;
  };
  error: string | null;
  row_count?: number;
  imported_at: string | null;
  created_at: string;
}

interface Issue {
  type: string;
  field?: string;
  message: string;
  record_id?: number;
}

interface Row {
  id: number;
  sheet_name: string;
  row_number: number;
  target: string;
  raw: Record<string, unknown>;
  mapped: Record<string, unknown>;
  issues: Issue[];
  action: string;
  status: string;
  error: string | null;
}

/** The whole-workbook path keeps its own value, distinct from "nothing chosen". */
const WORKBOOK = "__workbook__";

function issueTone(type: string): "gray" | "amber" | "red" | "green" | "indigo" {
  if (type === "invalid" || type === "duplicate" || type === "unmatched") return "red";
  if (type === "incomplete" || type === "unknown_label" || type === "review") return "amber";
  if (type === "match") return "green";
  return "indigo";
}

export default function ImportsPage() {
  const { t } = useI18n();

  /*
   * Import is a three-step decision, in this order: what am I importing, what
   * does that file have to look like, then upload it. Choosing the entity first
   * is what makes the other two possible — the template and the validation are
   * both generated from the same definition on the server.
   */
  const [choice, setChoice] = useState("");
  const [uploading, setUploading] = useState(false);
  const [downloading, setDownloading] = useState(false);
  const [selected, setSelected] = useState<number | null>(null);
  const [error, setError] = useState<string | null>(null);

  const { data: catalogue } = useResource<{ data: ImportEntity[] }>("/imports/entities");
  const { data: batchData } = useResource<{ data: Batch[] }>("/imports");

  const entities = catalogue?.data ?? [];
  const batches = batchData?.data ?? [];
  const entity = entities.find((candidate) => candidate.key === choice) ?? null;
  const isWorkbook = choice === WORKBOOK;

  async function downloadTemplate() {
    if (!entity) return;

    setDownloading(true);
    setError(null);
    try {
      await downloadToDisk(
        `/imports/template?entity=${encodeURIComponent(entity.key)}`,
        `import-template-${entity.key}.xlsx`,
      );
    } catch (err) {
      setError(errorMessage(err));
    } finally {
      setDownloading(false);
    }
  }

  async function upload(file: File) {
    setUploading(true);
    setError(null);
    try {
      const body = new FormData();
      body.append("file", file);
      if (entity) body.append("entity", entity.key);

      const res = await apiFetch<{ data: Batch }>("/imports", { method: "POST", body });
      setSelected(res.data.id);
    } catch (err) {
      setError(errorMessage(err));
    } finally {
      setUploading(false);
    }
  }

  const entityLabel = (key: string | null): string =>
    key === null
      ? t("imports.wholeWorkbookShort")
      : (entities.find((candidate) => candidate.key === key)?.label ?? key);

  return (
    <div className="space-y-5">
      <div>
        <h1 className="text-[2.5rem] font-light leading-none tracking-[-0.02em] text-zinc-900 dark:text-zinc-50">{t("imports.title")}</h1>
        <p className="text-sm text-zinc-500">{t("imports.subtitle")}</p>
      </div>

      {error && <p className="text-sm text-red-600">{error}</p>}

      <Card className="space-y-5">
        <section>
          <p className="mb-2 text-sm font-semibold text-zinc-900 dark:text-zinc-50">{t("imports.step1")}</p>
          <Select className="max-w-md" value={choice} onChange={(e) => setChoice(e.target.value)}>
            <option value="">{t("imports.chooseEntity")}</option>
            {entities.map((candidate) => (
              <option key={candidate.key} value={candidate.key}>
                {candidate.label}
              </option>
            ))}
            <option value={WORKBOOK}>{t("imports.wholeWorkbook")}</option>
          </Select>
          {isWorkbook && <p className="mt-2 text-xs text-zinc-500">{t("imports.wholeWorkbookHint")}</p>}
          {entity?.description && <p className="mt-2 text-xs text-zinc-500">{entity.description}</p>}
        </section>

        {entity && (
          <section>
            <p className="mb-2 text-sm font-semibold text-zinc-900 dark:text-zinc-50">{t("imports.step2")}</p>
            <p className="mb-3 text-xs text-zinc-500">{t("imports.templateHint")}</p>
            <Button variant="secondary" disabled={downloading} onClick={() => void downloadTemplate()}>
              {downloading ? t("common.loading") : t("imports.downloadTemplate")}
            </Button>

            <details className="mt-3">
              <summary className="cursor-pointer text-xs text-zinc-500">
                {t("imports.columns")} ({entity.columns.length})
              </summary>
              <ul className="mt-2 space-y-1 text-xs">
                {entity.columns.map((column) => (
                  <li key={column.key} className="flex flex-wrap items-baseline gap-2">
                    <span className="font-medium text-zinc-800 dark:text-zinc-200">{column.header}</span>
                    <Badge tone={column.required ? "amber" : "gray"}>
                      {column.required ? t("imports.required") : t("imports.optional")}
                    </Badge>
                    <span className="text-zinc-500">{column.format}</span>
                  </li>
                ))}
              </ul>
            </details>
          </section>
        )}

        {(entity || isWorkbook) && (
          <section>
            <p className="mb-2 text-sm font-semibold text-zinc-900 dark:text-zinc-50">{t("imports.step3")}</p>
            <label className="inline-flex h-10 cursor-pointer items-center justify-center rounded-md bg-indigo-600 px-4 text-sm font-medium text-white transition-colors hover:bg-indigo-500">
              {uploading
                ? t("imports.uploading")
                : entity
                  ? t("imports.uploadFor", { entity: entity.label })
                  : t("imports.upload")}
              <input
                type="file"
                accept=".xlsx,.xls,.csv"
                className="hidden"
                disabled={uploading}
                onChange={(e) => {
                  const file = e.target.files?.[0];
                  if (file) void upload(file);
                  e.target.value = "";
                }}
              />
            </label>
          </section>
        )}
      </Card>

      <Card className="table-quiet overflow-x-auto p-0">
        <table className="w-full min-w-[820px] text-sm">
          <thead className="border-b border-zinc-900/8 text-left text-[11px] uppercase tracking-[0.1em] text-zinc-500 dark:border-white/10">
            <tr>
              <th className="px-4 py-3">{t("imports.file")}</th>
              <th className="px-4 py-3">{t("imports.entityColumn")}</th>
              <th className="px-4 py-3">{t("imports.uploaded")}</th>
              <th className="px-4 py-3 text-right">{t("imports.rows")}</th>
              <th className="px-4 py-3">{t("imports.status")}</th>
              <th className="px-4 py-3 text-right">{t("imports.actions")}</th>
            </tr>
          </thead>
          <tbody className="divide-y divide-zinc-900/5 dark:divide-white/8">
            {batches.length === 0 ? (
              <tr>
                <td colSpan={6} className="px-4 py-14 text-center text-sm text-zinc-500">
                  {t("imports.none")}
                </td>
              </tr>
            ) : (
              batches.map((batch) => (
                <tr key={batch.id} className="text-zinc-800 dark:text-zinc-200">
                  <td className="px-4 py-3 font-medium">{batch.original_name}</td>
                  <td className="px-4 py-3 text-zinc-500">{entityLabel(batch.entity)}</td>
                  <td className="px-4 py-3">{formatDate(batch.created_at)}</td>
                  <td className="px-4 py-3 text-right tabular-nums">{batch.totals?.rows ?? 0}</td>
                  <td className="px-4 py-3">
                    <Badge tone={batch.status === "imported" ? "green" : batch.status === "failed" ? "red" : "gray"}>
                      {t(`importStatus.${batch.status}`)}
                    </Badge>
                  </td>
                  <td className="px-4 py-3 text-right">
                    <Button
                      variant="secondary"
                      className="h-8 px-3"
                      onClick={() => setSelected(selected === batch.id ? null : batch.id)}
                    >
                      {selected === batch.id ? t("imports.hide") : t("imports.review")}
                    </Button>
                  </td>
                </tr>
              ))
            )}
          </tbody>
        </table>
      </Card>

      {selected !== null && <BatchPreview batchId={selected} />}
    </div>
  );
}

function BatchPreview({ batchId }: { batchId: number }) {
  const { t } = useI18n();
  const [sheet, setSheet] = useState("");
  const [issuesOnly, setIssuesOnly] = useState(false);
  const [failedOnly, setFailedOnly] = useState(false);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [result, setResult] = useState<{ imported: number; skipped: number; failed: number } | null>(null);

  const query = new URLSearchParams();
  if (sheet) query.set("sheet", sheet);
  if (issuesOnly) query.set("issues_only", "1");
  if (failedOnly) query.set("status", "failed");

  const { data, loading, refresh } = useResource<{ data: Batch; rows: Row[] }>(
    `/imports/${batchId}?${query.toString()}`,
  );

  const batch = data?.data;
  const rows = data?.rows ?? [];

  const toggle = useCallback(
    async (row: Row) => {
      const action = row.action === "create" ? "skip" : "create";

      try {
        await apiFetch(`/imports/${batchId}/rows`, {
          method: "PUT",
          json: { rows: [{ id: row.id, action }] },
        });
      } catch (err) {
        setError(errorMessage(err));
        await refresh();
      }
    },
    [batchId, refresh],
  );

  async function run(endpoint: "commit" | "cancel") {
    if (endpoint === "commit" && !window.confirm(t("imports.confirmImport"))) return;

    setBusy(true);
    setError(null);
    try {
      const res = await apiFetch<{ meta?: { imported: number; skipped: number; failed: number } }>(
        `/imports/${batchId}/${endpoint}`,
        { method: "POST" },
      );
      setResult(res.meta ?? null);
    } catch (err) {
      setError(errorMessage(err));
    } finally {
      setBusy(false);
    }
  }

  // Reset the row filters whenever a different batch is opened.
  useEffect(() => {
    setSheet("");
    setIssuesOnly(false);
    setFailedOnly(false);
    setResult(null);
  }, [batchId]);

  if (loading && !batch) return <p className="text-sm text-zinc-500">{t("common.loading")}</p>;
  if (!batch) return null;

  const previewed = batch.status === "previewed";
  const willImport = rows.filter((row) => row.action === "create" && row.status === "pending").length;
  const invalid = batch.totals?.invalid ?? 0;
  const missingColumns = batch.sheet_summary?.flatMap((summary) => summary.missing_columns ?? []) ?? [];

  return (
    <div className="space-y-4">
      <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
        <Stat label={t("imports.rows")} value={String(batch.totals?.rows ?? 0)} />
        <Stat label={t("imports.invalid")} value={String(invalid)} tone={invalid > 0 ? "red" : undefined} />
        <Stat label={t("imports.duplicates")} value={String(batch.totals?.duplicates ?? 0)} />
        <Stat label={t("imports.incomplete")} value={String(batch.totals?.incomplete ?? 0)} />
      </div>

      {/* A file missing a required column cannot be judged row by row. */}
      {(batch.error || missingColumns.length > 0) && (
        <Card className="border-red-300 bg-red-50 dark:border-red-900 dark:bg-red-950/40">
          <p className="text-sm text-red-700 dark:text-red-300">{batch.error}</p>
        </Card>
      )}

      {invalid > 0 && previewed && (
        <Card className="border-amber-300 bg-amber-50 dark:border-amber-900 dark:bg-amber-950/40">
          <p className="text-sm font-medium text-amber-900 dark:text-amber-200">
            {t("imports.invalid")}: {invalid}
          </p>
          <p className="mt-1 text-xs text-amber-800 dark:text-amber-300">{t("imports.invalidHint")}</p>
        </Card>
      )}

      {result && (
        <Card className="border-indigo-300 bg-indigo-50 dark:border-indigo-900 dark:bg-indigo-950/40">
          <p className="text-sm text-indigo-900 dark:text-indigo-200">{t("imports.result", result)}</p>
        </Card>
      )}

      <Card>
        <p className="mb-3 text-sm font-semibold text-zinc-900 dark:text-zinc-50">{t("imports.sheets")}</p>
        <ul className="space-y-1 text-sm">
          {batch.sheet_summary.map((row) => (
            <li key={row.sheet} className="flex flex-wrap items-center justify-between gap-2">
              <span className="text-zinc-700 dark:text-zinc-300">{row.sheet}</span>
              {row.skipped ? (
                <Badge tone="amber">{t("imports.noParser")}</Badge>
              ) : (
                <span className="text-xs text-zinc-500">
                  {t("imports.rowsFound", { count: row.rows })}
                  {row.targets &&
                    ` · ${Object.entries(row.targets)
                      .map(([target, count]) => `${t(`importTarget.${target}`)} ${count}`)
                      .join(", ")}`}
                </span>
              )}
            </li>
          ))}
        </ul>
      </Card>

      <div className="flex flex-wrap items-end justify-between gap-3">
        <div className="flex flex-wrap items-end gap-3">
          <Select className="w-64" value={sheet} onChange={(e) => setSheet(e.target.value)}>
            <option value="">{t("imports.allSheets")}</option>
            {batch.sheet_summary
              .filter((row) => !row.skipped)
              .map((row) => (
                <option key={row.sheet} value={row.sheet}>
                  {row.sheet}
                </option>
              ))}
          </Select>
          <label className="flex h-10 items-center gap-2 text-sm text-zinc-600 dark:text-zinc-300">
            <input
              type="checkbox"
              checked={issuesOnly}
              onChange={(e) => setIssuesOnly(e.target.checked)}
              className="h-4 w-4 rounded border-zinc-300 dark:border-zinc-700"
            />
            {t("imports.issuesOnly")}
          </label>
          {/* After a commit, the rows that did not make it are what matters. */}
          <label className="flex h-10 items-center gap-2 text-sm text-zinc-600 dark:text-zinc-300">
            <input
              type="checkbox"
              checked={failedOnly}
              onChange={(e) => setFailedOnly(e.target.checked)}
              className="h-4 w-4 rounded border-zinc-300 dark:border-zinc-700"
            />
            {t("imports.showFailed")}
          </label>
        </div>

        {previewed && (
          <div className="flex flex-wrap gap-2">
            <Button variant="secondary" disabled={busy} onClick={() => void run("cancel")}>
              {t("imports.cancel")}
            </Button>
            <Button disabled={busy || willImport === 0} onClick={() => void run("commit")}>
              {t("imports.approve", { count: willImport })}
            </Button>
          </div>
        )}
      </div>

      {error && <p className="text-sm text-red-600">{error}</p>}

      <Card className="table-quiet overflow-x-auto p-0">
        <table className="w-full min-w-[900px] text-sm">
          <thead className="border-b border-zinc-900/8 text-left text-[11px] uppercase tracking-[0.1em] text-zinc-500 dark:border-white/10">
            <tr>
              <th className="px-4 py-3">{t("imports.source")}</th>
              <th className="px-4 py-3">{t("imports.target")}</th>
              <th className="px-4 py-3">{t("imports.mapped")}</th>
              <th className="px-4 py-3">{t("imports.issues")}</th>
              <th className="px-4 py-3 text-right">{t("imports.import")}</th>
            </tr>
          </thead>
          <tbody className="divide-y divide-zinc-900/5 dark:divide-white/8">
            {rows.length === 0 ? (
              <tr>
                <td colSpan={5} className="px-4 py-14 text-center text-sm text-zinc-500">
                  {t("imports.noRows")}
                </td>
              </tr>
            ) : (
              rows.map((row) => (
                <tr key={row.id} className="align-top text-zinc-800 dark:text-zinc-200">
                  <td className="px-4 py-3">
                    {/* Sheet name and row number are kept so a row can always be
                        traced back to the workbook. */}
                    <span className="flex flex-col">
                      <span className="font-medium">{row.sheet_name}</span>
                      <span className="text-xs text-zinc-500">
                        {t("imports.rowNumber", { number: row.row_number })}
                      </span>
                    </span>
                  </td>
                  <td className="px-4 py-3">{t(`importTarget.${row.target}`)}</td>
                  <td className="px-4 py-3">
                    <dl className="space-y-0.5 text-xs">
                      {Object.entries(row.mapped)
                        .filter(([, value]) => value !== null && value !== "" && value !== false)
                        .slice(0, 5)
                        .map(([key, value]) => (
                          <div key={key} className="flex gap-2">
                            <dt className="text-zinc-500">{key}</dt>
                            <dd className="text-zinc-800 dark:text-zinc-200">{String(value)}</dd>
                          </div>
                        ))}
                    </dl>
                  </td>
                  <td className="px-4 py-3">
                    <span className="flex flex-wrap gap-1">
                      {row.issues.length === 0 ? (
                        <span className="text-xs text-zinc-400">—</span>
                      ) : (
                        row.issues.map((issue, index) => (
                          <Badge key={index} tone={issueTone(issue.type)}>
                            {issue.message}
                          </Badge>
                        ))
                      )}
                    </span>
                    {row.error && <p className="mt-1 text-xs text-red-600">{row.error}</p>}
                  </td>
                  <td className="px-4 py-3 text-right">
                    {row.status === "pending" ? (
                      <input
                        type="checkbox"
                        checked={row.action === "create"}
                        disabled={!previewed}
                        onChange={() => void toggle(row)}
                        className="h-4 w-4 rounded border-zinc-300 dark:border-zinc-700"
                      />
                    ) : (
                      <Badge tone={row.status === "imported" ? "green" : row.status === "failed" ? "red" : "gray"}>
                        {t(`importRowStatus.${row.status}`)}
                      </Badge>
                    )}
                  </td>
                </tr>
              ))
            )}
          </tbody>
        </table>
      </Card>
    </div>
  );
}

function Stat({ label, value, tone }: { label: string; value: string; tone?: "red" }) {
  return (
    <Card className="p-4">
      <p className="text-xs uppercase tracking-wider text-zinc-500">{label}</p>
      <p
        className={
          tone === "red"
            ? "mt-1 text-xl font-semibold text-red-600 dark:text-red-400"
            : "mt-1 text-xl font-semibold text-zinc-900 dark:text-zinc-50"
        }
      >
        {value}
      </p>
    </Card>
  );
}
