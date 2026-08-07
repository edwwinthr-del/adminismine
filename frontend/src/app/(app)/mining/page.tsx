"use client";

import { useState } from "react";
import { ApiError, apiFetch } from "@/lib/api";
import { useResource, withQuery } from "@/lib/data/use-resource";
import { useEmployeeOptions, useWorksiteOptions, type EmployeeOption, type WorksiteOption } from "@/lib/data/use-options";
import { useAuth } from "@/lib/auth/context";
import { useI18n } from "@/lib/i18n/context";
import { formatDate, todayISO } from "@/lib/format";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Card } from "@/components/ui/card";
import { Input } from "@/components/ui/input";
import { Modal } from "@/components/ui/modal";
import { Select } from "@/components/ui/select";

interface ProductionRecord {
  id: number;
  period_type: string;
  date: string | null;
  period_month: string | null;
  worksite_id: number;
  worksite?: { id: number; name: string };
  engineer_id: number | null;
  engineer?: { id: number; full_name: string } | null;
  material_type: string;
  quantity: number;
  unit: string;
  quality_grade: string | null;
  approval_status: string;
  rejection_reason: string | null;
  notes: string | null;
}

interface Totals {
  month: string;
  year: number;
  unit: string;
  daily: { date: string; quantity: number }[];
  daily_total: number;
  monthly_entries_total: number;
  month_total: number;
  year_to_date_total: number;
  by_worksite: { worksite_id: number; name: string | null; quantity: number }[];
  by_material: { material_type: string; quantity: number }[];
  pending_approval: number;
}

const MATERIALS = ["bauxite_ore", "overburden", "limestone", "other"] as const;
const UNITS = ["tons", "m3", "kg"] as const;
const PERIOD_TYPES = ["daily", "monthly"] as const;

function currentMonth(): string {
  return todayISO().slice(0, 7);
}

function formatQuantity(value: number): string {
  return new Intl.NumberFormat(undefined, { maximumFractionDigits: 3 }).format(value);
}

function approvalTone(status: string): "gray" | "green" | "red" {
  if (status === "approved") return "green";
  if (status === "rejected") return "red";
  return "gray";
}

export default function MiningPage() {
  const { t } = useI18n();
  const { hasPermission } = useAuth();
  const canApprove = hasPermission("mining_production.approve");

  const [month, setMonth] = useState(currentMonth);
  const [worksiteId, setWorksiteId] = useState("");
  const [material, setMaterial] = useState("");
  const [periodType, setPeriodType] = useState("");
  const [approvedOnly, setApprovedOnly] = useState(false);

  const [creating, setCreating] = useState(false);
  const [editing, setEditing] = useState<ProductionRecord | null>(null);
  const [rejecting, setRejecting] = useState<ProductionRecord | null>(null);
  const [error, setError] = useState<string | null>(null);

  // The totals deliberately ignore `period_type`: filtering the list to daily or
  // monthly entries changes which rows are shown, not how much was produced in
  // the month. Keeping them as separate resources preserves that — and means
  // switching the period no longer refetches the totals.
  const { data: list, loading, error: listError } = useResource<{ data: ProductionRecord[] }>(
    withQuery("/production", {
      month,
      per_page: 200,
      worksite_id: worksiteId,
      material_type: material,
      period_type: periodType,
      approved_only: approvedOnly,
    }),
  );

  const { data: sums } = useResource<{ data: Totals }>(
    withQuery("/production/totals", {
      month,
      worksite_id: worksiteId,
      material_type: material,
      approved_only: approvedOnly,
    }),
  );

  const records = list?.data ?? [];
  const totals = sums?.data ?? null;

  const { options: worksites } = useWorksiteOptions();
  const { options: employees } = useEmployeeOptions();

  // No manual refetch: apiFetch's markMutated() revalidates both resources above.
  async function approve(record: ProductionRecord) {
    await apiFetch(`/production/${record.id}/approve`, { method: "POST" });
  }

  async function remove(record: ProductionRecord) {
    if (!window.confirm(t("mining.deleteConfirm"))) return;
    try {
      await apiFetch(`/production/${record.id}`, { method: "DELETE" });
    } catch (err) {
      setError(err instanceof ApiError ? err.message : "Error");
    }
  }

  const unit = totals?.unit ?? "tons";

  return (
    <div className="space-y-5">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <h1 className="text-[2.5rem] font-light leading-none tracking-[-0.02em] text-zinc-900 dark:text-zinc-50">{t("mining.title")}</h1>
        <div className="flex items-center gap-3">
          <Input className="w-[10rem]" type="month" value={month} onChange={(e) => setMonth(e.target.value)} />
          <Button onClick={() => setCreating(true)}>{t("mining.new")}</Button>
        </div>
      </div>

      <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
        <Tile
          label={t("mining.monthTotal")}
          value={`${formatQuantity(totals?.month_total ?? 0)} ${t(`unit.${unit}`)}`}
          hint={t("mining.dailyPlusMonthly", {
            daily: formatQuantity(totals?.daily_total ?? 0),
            monthly: formatQuantity(totals?.monthly_entries_total ?? 0),
          })}
        />
        <Tile
          label={t("mining.ytdTotal", { year: totals?.year ?? new Date().getFullYear() })}
          value={`${formatQuantity(totals?.year_to_date_total ?? 0)} ${t(`unit.${unit}`)}`}
        />
        <Tile label={t("mining.entries")} value={String(records.length)} />
        <Tile label={t("mining.pendingApproval")} value={String(totals?.pending_approval ?? 0)} />
      </div>

      <Card className="p-4">
        <div className="flex flex-wrap items-center gap-3">
          <Select className="max-w-[13rem]" value={worksiteId} onChange={(e) => setWorksiteId(e.target.value)}>
            <option value="">{t("mining.allWorksites")}</option>
            {worksites.map((worksite) => (
              <option key={worksite.id} value={worksite.id}>
                {worksite.name}
              </option>
            ))}
          </Select>
          <Select className="max-w-[13rem]" value={material} onChange={(e) => setMaterial(e.target.value)}>
            <option value="">{t("mining.allMaterials")}</option>
            {MATERIALS.map((value) => (
              <option key={value} value={value}>
                {t(`material.${value}`)}
              </option>
            ))}
          </Select>
          <Select className="max-w-[11rem]" value={periodType} onChange={(e) => setPeriodType(e.target.value)}>
            <option value="">{t("mining.allPeriods")}</option>
            {PERIOD_TYPES.map((value) => (
              <option key={value} value={value}>
                {t(`mining.${value}`)}
              </option>
            ))}
          </Select>
          <label className="flex items-center gap-2 text-sm text-zinc-600 dark:text-zinc-300">
            <input type="checkbox" checked={approvedOnly} onChange={(e) => setApprovedOnly(e.target.checked)} />
            {t("mining.approvedOnly")}
          </label>
        </div>
      </Card>

      {(error ?? listError) && <p className="text-sm text-red-600">{error ?? listError}</p>}

      <div className="grid gap-5 lg:grid-cols-3">
        <Card className="p-4 lg:col-span-1">
          <p className="mb-3 text-xs font-semibold uppercase tracking-wider text-zinc-500">
            {t("mining.byWorksite")}
          </p>
          {totals && totals.by_worksite.length > 0 ? (
            <ul className="space-y-2 text-sm">
              {totals.by_worksite.map((row) => (
                <li key={row.worksite_id} className="flex items-center justify-between gap-3">
                  <span className="text-zinc-700 dark:text-zinc-200">{row.name ?? `#${row.worksite_id}`}</span>
                  <span className="tabular-nums text-zinc-900 dark:text-zinc-50">
                    {formatQuantity(row.quantity)} {t(`unit.${unit}`)}
                  </span>
                </li>
              ))}
            </ul>
          ) : (
            <p className="text-sm text-zinc-500">{t("mining.noData")}</p>
          )}

          <p className="mb-3 mt-5 text-xs font-semibold uppercase tracking-wider text-zinc-500">
            {t("mining.byMaterial")}
          </p>
          {totals && totals.by_material.length > 0 ? (
            <ul className="space-y-2 text-sm">
              {totals.by_material.map((row) => (
                <li key={row.material_type} className="flex items-center justify-between gap-3">
                  <span className="text-zinc-700 dark:text-zinc-200">{t(`material.${row.material_type}`)}</span>
                  <span className="tabular-nums text-zinc-900 dark:text-zinc-50">
                    {formatQuantity(row.quantity)} {t(`unit.${unit}`)}
                  </span>
                </li>
              ))}
            </ul>
          ) : (
            <p className="text-sm text-zinc-500">{t("mining.noData")}</p>
          )}
        </Card>

        <Card className="table-quiet overflow-x-auto p-0 lg:col-span-2">
          <table className="w-full min-w-[820px] text-sm">
            <thead className="border-b border-zinc-900/8 text-left text-[11px] uppercase tracking-[0.1em] text-zinc-500 dark:border-white/10">
              <tr>
                <th className="px-4 py-3">{t("mining.period")}</th>
                <th className="px-4 py-3">{t("mining.worksite")}</th>
                <th className="px-4 py-3">{t("mining.engineer")}</th>
                <th className="px-4 py-3">{t("mining.material")}</th>
                <th className="px-4 py-3 text-right">{t("mining.quantity")}</th>
                <th className="px-4 py-3">{t("mining.approval")}</th>
                <th className="px-4 py-3 text-right">{t("mining.actions")}</th>
              </tr>
            </thead>
            <tbody className="divide-y divide-zinc-900/5 dark:divide-white/8">
              {loading ? (
                <tr>
                  <td colSpan={7} className="px-4 py-14 text-center text-sm text-zinc-500">
                    {t("common.loading")}
                  </td>
                </tr>
              ) : records.length === 0 ? (
                <tr>
                  <td colSpan={7} className="px-4 py-14 text-center text-sm text-zinc-500">
                    {t("mining.none")}
                  </td>
                </tr>
              ) : (
                records.map((record) => (
                  <tr key={record.id} className="text-zinc-800 dark:text-zinc-200">
                    <td className="px-4 py-3">
                      {record.period_type === "daily" ? (
                        formatDate(record.date)
                      ) : (
                        <span className="flex items-center gap-2">
                          {record.period_month?.slice(0, 7)}
                          <Badge tone="indigo">{t("mining.monthly")}</Badge>
                        </span>
                      )}
                    </td>
                    <td className="px-4 py-3 font-medium">{record.worksite?.name ?? `#${record.worksite_id}`}</td>
                    <td className="px-4 py-3">{record.engineer?.full_name ?? "—"}</td>
                    <td className="px-4 py-3">
                      {t(`material.${record.material_type}`)}
                      {record.quality_grade && (
                        <span className="ml-2 text-xs text-zinc-500">{record.quality_grade}</span>
                      )}
                    </td>
                    <td className="px-4 py-3 text-right font-medium tabular-nums">
                      {formatQuantity(record.quantity)} {t(`unit.${record.unit}`)}
                    </td>
                    <td className="px-4 py-3">
                      <span className="flex flex-col gap-1">
                        <Badge tone={approvalTone(record.approval_status)}>
                          {t(`approval.${record.approval_status}`)}
                        </Badge>
                        {record.rejection_reason && (
                          <span className="text-xs text-red-600">{record.rejection_reason}</span>
                        )}
                      </span>
                    </td>
                    <td className="px-4 py-3 text-right">
                      <div className="flex justify-end gap-2">
                        {canApprove && record.approval_status !== "approved" && (
                          <Button variant="secondary" className="h-8 px-3" onClick={() => void approve(record)}>
                            {t("mining.approve")}
                          </Button>
                        )}
                        {canApprove && record.approval_status !== "rejected" && (
                          <Button variant="secondary" className="h-8 px-3" onClick={() => setRejecting(record)}>
                            {t("mining.reject")}
                          </Button>
                        )}
                        <Button variant="secondary" className="h-8 px-3" onClick={() => setEditing(record)}>
                          {t("mining.edit")}
                        </Button>
                        <Button variant="secondary" className="h-8 px-3" onClick={() => void remove(record)}>
                          {t("mining.delete")}
                        </Button>
                      </div>
                    </td>
                  </tr>
                ))
              )}
            </tbody>
          </table>
        </Card>
      </div>

      <Card className="p-4">
        <p className="mb-3 text-xs font-semibold uppercase tracking-wider text-zinc-500">
          {t("mining.dailyBreakdown")}
        </p>
        {totals && totals.daily.length > 0 ? (
          <ul className="grid gap-2 sm:grid-cols-2 lg:grid-cols-4">
            {totals.daily.map((row) => (
              <li
                key={row.date}
                className="flex items-center justify-between gap-3 rounded-lg border border-zinc-200 px-3 py-2 text-sm dark:border-zinc-800"
              >
                <span className="text-zinc-600 dark:text-zinc-300">{formatDate(row.date)}</span>
                <span className="font-medium tabular-nums text-zinc-900 dark:text-zinc-50">
                  {formatQuantity(row.quantity)}
                </span>
              </li>
            ))}
          </ul>
        ) : (
          <p className="text-sm text-zinc-500">{t("mining.noDaily")}</p>
        )}
      </Card>

      {creating && (
        <RecordModal
          worksites={worksites}
          employees={employees}
          defaultMonth={month}
          onClose={() => setCreating(false)}
        />
      )}
      {editing && (
        <RecordModal
          record={editing}
          worksites={worksites}
          employees={employees}
          defaultMonth={month}
          onClose={() => setEditing(null)}
        />
      )}
      {rejecting && <RejectModal record={rejecting} onClose={() => setRejecting(null)} />}
    </div>
  );
}

function Tile({ label, value, hint }: { label: string; value: string; hint?: string }) {
  return (
    <Card className="p-4">
      <p className="text-xs uppercase tracking-wider text-zinc-500">{label}</p>
      <p className="mt-1 text-xl font-semibold tabular-nums text-zinc-900 dark:text-zinc-50">{value}</p>
      {hint && <p className="mt-1 text-xs text-zinc-500">{hint}</p>}
    </Card>
  );
}

function RecordModal({
  record,
  worksites,
  employees,
  defaultMonth,
  onClose,
}: {
  record?: ProductionRecord;
  worksites: WorksiteOption[];
  employees: EmployeeOption[];
  defaultMonth: string;
  onClose: () => void;
}) {
  const { t } = useI18n();
  const [periodType, setPeriodType] = useState(record?.period_type ?? "daily");
  const [date, setDate] = useState(record?.date ?? todayISO());
  const [periodMonth, setPeriodMonth] = useState(record?.period_month?.slice(0, 7) ?? defaultMonth);
  const [worksiteId, setWorksiteId] = useState(record ? String(record.worksite_id) : "");
  const [engineerId, setEngineerId] = useState(record?.engineer_id ? String(record.engineer_id) : "");
  const [materialType, setMaterialType] = useState(record?.material_type ?? "bauxite_ore");
  const [quantity, setQuantity] = useState(record ? String(record.quantity) : "");
  const [unit, setUnit] = useState(record?.unit ?? "tons");
  const [quality, setQuality] = useState(record?.quality_grade ?? "");
  const [notes, setNotes] = useState(record?.notes ?? "");
  const [error, setError] = useState<string | null>(null);
  const [saving, setSaving] = useState(false);

  async function submit(e: React.FormEvent) {
    e.preventDefault();
    setSaving(true);
    setError(null);
    try {
      // The period is fixed once filed, so an edit only sends the figures.
      const body = record
        ? {
            engineer_id: engineerId ? Number(engineerId) : null,
            material_type: materialType,
            quantity: Number(quantity),
            unit,
            quality_grade: quality.trim() || null,
            notes: notes.trim() || null,
          }
        : {
            period_type: periodType,
            date: periodType === "daily" ? date : null,
            period_month: periodType === "monthly" ? periodMonth : undefined,
            worksite_id: Number(worksiteId),
            engineer_id: engineerId ? Number(engineerId) : null,
            material_type: materialType,
            quantity: Number(quantity),
            unit,
            quality_grade: quality.trim() || null,
            notes: notes.trim() || null,
          };

      await apiFetch(record ? `/production/${record.id}` : "/production", {
        method: record ? "PUT" : "POST",
        json: body,
      });
      onClose();
    } catch (err) {
      setError(err instanceof ApiError ? err.message : "Error");
    } finally {
      setSaving(false);
    }
  }

  const label = "mb-1.5 block px-4 text-[11px] font-medium uppercase tracking-[0.08em] text-zinc-500";

  return (
    <Modal open onClose={onClose} title={record ? t("mining.edit") : t("mining.new")}>
      <form onSubmit={submit} className="space-y-4">
        <div className="grid grid-cols-2 gap-4">
          <div>
            <label className={label}>{t("mining.periodType")}</label>
            {record ? (
              <p className="py-2 text-sm text-zinc-700 dark:text-zinc-200">{t(`mining.${record.period_type}`)}</p>
            ) : (
              <Select value={periodType} onChange={(e) => setPeriodType(e.target.value)}>
                {PERIOD_TYPES.map((value) => (
                  <option key={value} value={value}>
                    {t(`mining.${value}`)}
                  </option>
                ))}
              </Select>
            )}
          </div>
          <div>
            <label className={label}>{periodType === "daily" ? t("mining.date") : t("mining.month")}</label>
            {record ? (
              <p className="py-2 text-sm text-zinc-700 dark:text-zinc-200">
                {record.period_type === "daily" ? formatDate(record.date) : record.period_month?.slice(0, 7)}
              </p>
            ) : periodType === "daily" ? (
              <Input type="date" value={date} onChange={(e) => setDate(e.target.value)} required />
            ) : (
              <Input type="month" value={periodMonth} onChange={(e) => setPeriodMonth(e.target.value)} required />
            )}
          </div>
          <div>
            <label className={label}>{t("mining.worksite")}</label>
            {record ? (
              <p className="py-2 text-sm text-zinc-700 dark:text-zinc-200">{record.worksite?.name}</p>
            ) : (
              <Select value={worksiteId} onChange={(e) => setWorksiteId(e.target.value)} required>
                <option value="">{t("mining.selectWorksite")}</option>
                {worksites.map((worksite) => (
                  <option key={worksite.id} value={worksite.id}>
                    {worksite.name}
                  </option>
                ))}
              </Select>
            )}
          </div>
          <div>
            <label className={label}>{t("mining.engineer")}</label>
            <Select value={engineerId} onChange={(e) => setEngineerId(e.target.value)}>
              <option value="">{t("mining.noEngineer")}</option>
              {employees.map((employee) => (
                <option key={employee.id} value={employee.id}>
                  {employee.full_name}
                </option>
              ))}
            </Select>
          </div>
          <div>
            <label className={label}>{t("mining.material")}</label>
            <Select value={materialType} onChange={(e) => setMaterialType(e.target.value)}>
              {MATERIALS.map((value) => (
                <option key={value} value={value}>
                  {t(`material.${value}`)}
                </option>
              ))}
            </Select>
          </div>
          <div>
            <label className={label}>{t("mining.unit")}</label>
            <Select value={unit} onChange={(e) => setUnit(e.target.value)}>
              {UNITS.map((value) => (
                <option key={value} value={value}>
                  {t(`unit.${value}`)}
                </option>
              ))}
            </Select>
          </div>
          <div>
            <label className={label}>{t("mining.quantity")}</label>
            <Input
              type="number"
              step="0.001"
              min="0"
              value={quantity}
              onChange={(e) => setQuantity(e.target.value)}
              required
            />
          </div>
          <div>
            <label className={label}>{t("mining.quality")}</label>
            <Input value={quality} onChange={(e) => setQuality(e.target.value)} />
          </div>
        </div>
        <div>
          <label className={label}>{t("mining.notes")}</label>
          <Input value={notes} onChange={(e) => setNotes(e.target.value)} />
        </div>

        {error && <p className="text-sm text-red-600">{error}</p>}
        <div className="flex justify-end gap-2">
          <Button type="button" variant="secondary" onClick={onClose}>
            {t("common.cancel")}
          </Button>
          <Button type="submit" disabled={saving || (!record && !worksiteId)}>
            {saving ? t("common.saving") : t("common.save")}
          </Button>
        </div>
      </form>
    </Modal>
  );
}

function RejectModal({
  record,
  onClose,
}: {
  record: ProductionRecord;
  onClose: () => void;
}) {
  const { t } = useI18n();
  const [reason, setReason] = useState("");
  const [error, setError] = useState<string | null>(null);
  const [saving, setSaving] = useState(false);

  async function submit(e: React.FormEvent) {
    e.preventDefault();
    setSaving(true);
    setError(null);
    try {
      await apiFetch(`/production/${record.id}/reject`, { method: "POST", json: { reason: reason.trim() } });
      onClose();
    } catch (err) {
      setError(err instanceof ApiError ? err.message : "Error");
    } finally {
      setSaving(false);
    }
  }

  return (
    <Modal open onClose={onClose} title={t("mining.reject")}>
      <form onSubmit={submit} className="space-y-4">
        <div>
          <label className="mb-1.5 block px-4 text-[11px] font-medium uppercase tracking-[0.08em] text-zinc-500">
            {t("mining.rejectReason")}
          </label>
          <Input value={reason} onChange={(e) => setReason(e.target.value)} required />
        </div>
        {error && <p className="text-sm text-red-600">{error}</p>}
        <div className="flex justify-end gap-2">
          <Button type="button" variant="secondary" onClick={onClose}>
            {t("common.cancel")}
          </Button>
          <Button type="submit" disabled={saving || reason.trim() === ""}>
            {saving ? t("common.saving") : t("mining.reject")}
          </Button>
        </div>
      </form>
    </Modal>
  );
}
