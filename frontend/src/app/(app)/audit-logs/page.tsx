"use client";

import { useState } from "react";
import { usePage } from "@/lib/data/use-page";
import { useResource, withQuery } from "@/lib/data/use-resource";
import { useI18n } from "@/lib/i18n/context";
import { auditEventLabel } from "@/lib/audit";
import { recordTypeLabel } from "@/lib/labels";
import { formatDate } from "@/lib/format";
import { useDebouncedValue } from "@/lib/use-debounced-value";
import { AsyncSelect } from "@/components/ui/async-select";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Card } from "@/components/ui/card";
import { Input } from "@/components/ui/input";
import { Modal } from "@/components/ui/modal";
import { Pagination, type PageMeta } from "@/components/ui/pagination";
import { Select } from "@/components/ui/select";

interface AuditLog {
  id: number;
  log_name: string | null;
  /** Language-neutral event key, e.g. "payable.created". */
  event: string | null;
  subject_type: string | null;
  subject_label: string | null;
  subject_id: number | null;
  causer?: { id: number; name: string } | null;
  properties: Record<string, unknown> | null;
  created_at: string | null;
}

interface FilterOptions {
  subject_types: { value: string; label: string }[];
  events: string[];
}

const PER_PAGE = 50;

/**
 * The stored event is `module.action`. The action half decides the tone, so a
 * deletion reads differently from a creation at a glance in every language.
 */
function eventTone(event: string | null): "gray" | "green" | "amber" | "red" | "indigo" {
  const action = event?.split(".").slice(1).join(".") ?? "";
  if (action.includes("deleted") || action.includes("rejected")) return "red";
  if (action.includes("created")) return "green";
  if (action.includes("approved") || action.includes("confirmed")) return "indigo";
  if (action.includes("updated") || action.includes("synced")) return "amber";
  return "gray";
}

function formatTimestamp(value: string | null): string {
  if (!value) return "—";
  const date = new Date(value);
  if (Number.isNaN(date.getTime())) return value;
  return `${formatDate(value)} ${date.toLocaleTimeString(undefined, { hour: "2-digit", minute: "2-digit" })}`;
}

export default function AuditLogsPage() {
  const { t } = useI18n();
  const [search, setSearch] = useState("");
  const [subjectType, setSubjectType] = useState("");
  const [causerId, setCauserId] = useState<number | null>(null);
  const [dateFrom, setDateFrom] = useState("");
  const [dateTo, setDateTo] = useState("");
  const [inspecting, setInspecting] = useState<AuditLog | null>(null);

  const debouncedSearch = useDebouncedValue(search);

  const [page, setPage] = usePage([debouncedSearch, subjectType, causerId, dateFrom, dateTo]);

  const { data, loading, error } = useResource<{ data: AuditLog[]; meta: PageMeta }>(
    withQuery("/audit-logs", {
      search: debouncedSearch,
      subject_type: subjectType,
      causer_id: causerId,
      date_from: dateFrom,
      date_to: dateTo,
      page,
      per_page: PER_PAGE,
    }),
  );
  const { data: optionsData } = useResource<{ data: FilterOptions }>("/audit-logs/filters");

  const logs = data?.data ?? [];
  const subjectTypes = optionsData?.data.subject_types ?? [];

  function reset() {
    setSearch("");
    setSubjectType("");
    setCauserId(null);
    setDateFrom("");
    setDateTo("");
  }

  return (
    <div className="space-y-5">
      <div>
        <p className="text-sm text-zinc-500">{t("audit.subtitle")}</p>
      </div>

      <Card className="flex flex-wrap items-end gap-3 p-3">
        <div className="min-w-[180px] flex-1">
          <label className="mb-1 block text-xs text-zinc-500">{t("audit.event")}</label>
          <Input placeholder={t("audit.searchHint")} value={search} onChange={(e) => setSearch(e.target.value)} />
        </div>
        <div className="min-w-[160px]">
          <label className="mb-1 block text-xs text-zinc-500">{t("audit.recordType")}</label>
          <Select value={subjectType} onChange={(e) => setSubjectType(e.target.value)}>
            <option value="">{t("audit.allTypes")}</option>
            {subjectTypes.map((type) => (
              <option key={type.value} value={type.value}>
                {recordTypeLabel(type.value, t)}
              </option>
            ))}
          </Select>
        </div>
        <div className="min-w-[180px]">
          <label className="mb-1 block text-xs text-zinc-500">{t("audit.actor")}</label>
          <AsyncSelect
            resource="users"
            value={causerId}
            onChange={(value) => setCauserId(value)}
            emptyLabel={t("audit.anyone")}
          />
        </div>
        <div>
          <label className="mb-1 block text-xs text-zinc-500">{t("audit.from")}</label>
          <Input type="date" value={dateFrom} onChange={(e) => setDateFrom(e.target.value)} />
        </div>
        <div>
          <label className="mb-1 block text-xs text-zinc-500">{t("audit.to")}</label>
          <Input type="date" value={dateTo} onChange={(e) => setDateTo(e.target.value)} />
        </div>
        <Button variant="secondary" onClick={reset}>
          {t("audit.reset")}
        </Button>
      </Card>

      {error && <p className="text-sm text-red-600">{error}</p>}

      <Card className="vui-table scroll-quiet overflow-x-auto p-0">
        <table className="w-full min-w-[860px] text-sm">
          <thead>
            <tr>
              <th>{t("audit.when")}</th>
              <th>{t("audit.actor")}</th>
              <th>{t("audit.event")}</th>
              <th>{t("audit.record")}</th>
              <th className="text-right">{t("common.actions")}</th>
            </tr>
          </thead>
          <tbody>
            {loading ? (
              <tr>
                <td colSpan={5} className="py-14 text-center text-sm text-zinc-500">
                  {t("common.loading")}
                </td>
              </tr>
            ) : logs.length === 0 ? (
              <tr>
                <td colSpan={5} className="py-14 text-center text-sm text-zinc-500">
                  {t("audit.none")}
                </td>
              </tr>
            ) : (
              logs.map((log) => (
                <tr key={log.id} className="text-zinc-800 dark:text-zinc-200">
                  <td className="whitespace-nowrap text-zinc-500">{formatTimestamp(log.created_at)}</td>
                  <td>{log.causer?.name ?? t("audit.system")}</td>
                  <td>
                    {/* The stored key is what the row *is*; the label is how it
                        reads. Kept as a title so the neutral key stays
                        recoverable when someone is comparing against the API. */}
                    <Badge tone={eventTone(log.event)}>
                      <span title={log.event ?? undefined}>
                        {auditEventLabel(log.event, t) ?? "—"}
                      </span>
                    </Badge>
                  </td>
                  <td className="text-zinc-500">
                    {log.subject_label ? `${log.subject_label} #${log.subject_id}` : "—"}
                  </td>
                  <td className="text-right">
                    <Button variant="secondary" className="h-8 px-3" onClick={() => setInspecting(log)}>
                      {t("common.details")}
                    </Button>
                  </td>
                </tr>
              ))
            )}
          </tbody>
        </table>
      </Card>

      {data?.meta && <Pagination meta={data.meta} onPageChange={setPage} />}

      {inspecting && <DetailsModal log={inspecting} onClose={() => setInspecting(null)} />}
    </div>
  );
}

function DetailsModal({ log, onClose }: { log: AuditLog; onClose: () => void }) {
  const { t } = useI18n();

  return (
    <Modal open onClose={onClose} title={auditEventLabel(log.event, t) ?? t("audit.title")}>
      <dl className="space-y-3 text-sm">
        <div className="flex justify-between gap-4">
          <dt className="text-zinc-500">{t("audit.when")}</dt>
          <dd className="text-zinc-800 dark:text-zinc-200">{formatTimestamp(log.created_at)}</dd>
        </div>
        <div className="flex justify-between gap-4">
          <dt className="text-zinc-500">{t("audit.actor")}</dt>
          <dd className="text-zinc-800 dark:text-zinc-200">{log.causer?.name ?? t("audit.system")}</dd>
        </div>
        <div className="flex justify-between gap-4">
          <dt className="text-zinc-500">{t("audit.record")}</dt>
          <dd className="text-zinc-800 dark:text-zinc-200">
            {log.subject_label ? `${log.subject_label} #${log.subject_id}` : "—"}
          </dd>
        </div>
        <div>
          <dt className="mb-1 text-zinc-500">{t("audit.properties")}</dt>
          <dd>
            {/* Whatever the module recorded, shown as stored — never reworded. */}
            <pre className="max-h-72 overflow-auto rounded-md bg-zinc-50 p-3 text-xs text-zinc-700 dark:bg-zinc-900 dark:text-zinc-300">
              {JSON.stringify(log.properties ?? {}, null, 2)}
            </pre>
          </dd>
        </div>
      </dl>
      <div className="mt-4 flex justify-end">
        <Button variant="secondary" onClick={onClose}>
          {t("common.close")}
        </Button>
      </div>
    </Modal>
  );
}
