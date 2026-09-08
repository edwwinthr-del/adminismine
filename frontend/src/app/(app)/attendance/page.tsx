"use client";

import { useEffect, useMemo, useRef, useState } from "react";
import { ApiError, apiFetch } from "@/lib/api";
import { useResource } from "@/lib/data/use-resource";
import { useWorksiteOptions } from "@/lib/data/use-options";
import { useAuth } from "@/lib/auth/context";
import { useI18n } from "@/lib/i18n/context";
import { formatMoney, todayISO } from "@/lib/format";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Card } from "@/components/ui/card";
import { Input } from "@/components/ui/input";
import { Modal } from "@/components/ui/modal";
import { Select } from "@/components/ui/select";

interface AttendanceRecord {
  id: number;
  status: string;
  regular_hours: number | null;
  overtime_hours: number;
  overtime_reason: string | null;
  note: string | null;
  approval_status: string;
  rejection_reason: string | null;
  currency: string;
  daily_rate: number | null;
  regular_amount: number;
  overtime_amount: number;
  adjustment_amount: number;
  adjustment_reason: string | null;
  total_amount: number;
  approved_for_payroll: boolean;
}

interface RosterRow {
  employee_id: number;
  full_name: string;
  job_role: string | null;
  daily_rate: number | null;
  currency: string;
  record: AttendanceRecord | null;
}

interface RosterMeta {
  date: string;
  worksite: { id: number; name: string };
  working_days_basis: number;
  working_days_overridden: boolean;
}

/** Editable state for one worker's day. */
interface DraftRow {
  status: string;
  regular_hours: string;
  overtime_hours: string;
  overtime_reason: string;
  note: string;
}

const STATUSES = ["present", "absent", "holiday", "sick_leave", "unpaid_leave", "other"] as const;

function draftFrom(row: RosterRow): DraftRow {
  return {
    status: row.record?.status ?? "present",
    regular_hours:
      row.record?.regular_hours === null || row.record?.regular_hours === undefined
        ? ""
        : String(row.record.regular_hours),
    overtime_hours: row.record ? String(row.record.overtime_hours) : "",
    overtime_reason: row.record?.overtime_reason ?? "",
    note: row.record?.note ?? "",
  };
}

function approvalTone(status: string): "gray" | "amber" | "green" | "red" {
  if (status === "approved") return "green";
  if (status === "submitted") return "amber";
  if (status === "rejected") return "red";
  return "gray";
}

export default function AttendancePage() {
  const { t } = useI18n();
  const { hasPermission } = useAuth();
  const canApprove = hasPermission("attendance.approve");

  const [worksiteId, setWorksiteId] = useState("");
  const [date, setDate] = useState(todayISO);
  const [drafts, setDrafts] = useState<Record<number, DraftRow>>({});
  const [saving, setSaving] = useState(false);
  const [message, setMessage] = useState<string | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [rejecting, setRejecting] = useState(false);
  const [overriding, setOverriding] = useState(false);

  const { options: worksites } = useWorksiteOptions();

  useEffect(() => {
    if (worksites.length > 0) setWorksiteId((current) => current || String(worksites[0].id));
  }, [worksites]);

  // `revalidateOnFocus` is off here alone: this screen is a data-entry grid, and
  // a tab-switch mid-entry must not pull a fresh roster out from under the hours
  // someone has typed but not yet saved.
  const {
    data,
    loading,
    error: rosterError,
    updatedAt,
  } = useResource<{ data: RosterRow[]; meta: RosterMeta }>(
    worksiteId && date ? `/attendance/roster?worksite_id=${worksiteId}&date=${date}` : null,
    { revalidateOnFocus: false },
  );

  const rows = data?.data ?? [];
  const meta = data?.meta ?? null;

  // Re-seed the drafts on each genuinely new response — a different worksite or
  // date, and the refetch that follows this page's own save, which is how the
  // server's normalised values and locked rows come back onto the screen.
  const seededAt = useRef<number | null>(null);

  useEffect(() => {
    if (!data || updatedAt === null || seededAt.current === updatedAt) return;

    seededAt.current = updatedAt;
    setDrafts(Object.fromEntries(data.data.map((row) => [row.employee_id, draftFrom(row)])));
  }, [data, updatedAt]);

  function setDraft(employeeId: number, patch: Partial<DraftRow>) {
    setDrafts((prev) => ({ ...prev, [employeeId]: { ...prev[employeeId], ...patch } }));
  }

  function markAllPresent() {
    setDrafts((prev) =>
      Object.fromEntries(
        Object.entries(prev).map(([id, draft]) => [id, { ...draft, status: "present", regular_hours: draft.regular_hours || "8" }]),
      ),
    );
  }

  const dayTotal = useMemo(
    () => rows.reduce((sum, row) => sum + (row.record?.total_amount ?? 0), 0),
    [rows],
  );

  const dayApproval = useMemo(() => {
    const saved = rows.map((row) => row.record?.approval_status).filter(Boolean) as string[];
    if (saved.length === 0) return null;
    if (saved.every((status) => status === "approved")) return "approved";
    if (saved.some((status) => status === "rejected")) return "rejected";
    if (saved.every((status) => status === "submitted")) return "submitted";
    return "draft";
  }, [rows]);

  async function saveDay() {
    setSaving(true);
    setError(null);
    setMessage(null);
    try {
      const res = await apiFetch<{ meta: { locked_employee_ids: number[] } }>("/attendance", {
        method: "POST",
        json: {
          date,
          worksite_id: Number(worksiteId),
          records: rows.map((row) => {
            const draft = drafts[row.employee_id];
            return {
              employee_id: row.employee_id,
              status: draft.status,
              regular_hours: draft.regular_hours === "" ? null : Number(draft.regular_hours),
              overtime_hours: draft.overtime_hours === "" ? 0 : Number(draft.overtime_hours),
              overtime_reason: draft.overtime_reason.trim() || null,
              note: draft.note.trim() || null,
            };
          }),
        },
      });
      const locked = res.meta.locked_employee_ids.length;
      setMessage(locked > 0 ? t("attendance.savedWithLocked", { count: locked }) : t("attendance.saved"));
    } catch (err) {
      setError(err instanceof ApiError ? err.message : "Error");
    } finally {
      setSaving(false);
    }
  }

  async function review(action: "submit" | "approve", reason?: string) {
    setSaving(true);
    setError(null);
    setMessage(null);
    try {
      await apiFetch(`/attendance/${action}`, {
        method: "POST",
        json: { date, worksite_id: Number(worksiteId), ...(reason ? { reason } : {}) },
      });
      setMessage(t(action === "submit" ? "attendance.submitted" : "attendance.approved"));
    } catch (err) {
      setError(err instanceof ApiError ? err.message : "Error");
    } finally {
      setSaving(false);
    }
  }

  async function reject(reason: string) {
    setSaving(true);
    setError(null);
    try {
      await apiFetch("/attendance/reject", {
        method: "POST",
        json: { date, worksite_id: Number(worksiteId), reason },
      });
      setMessage(t("attendance.rejected"));
      setRejecting(false);
    } catch (err) {
      setError(err instanceof ApiError ? err.message : "Error");
    } finally {
      setSaving(false);
    }
  }

  return (
    <div className="space-y-5">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <div className="flex flex-wrap items-center gap-3">
          {/* An explicit width, not `max-w-`: this group is content-sized, so a
              full-width control would claim the whole line and push the date
              picker onto a second row. */}
          <Select className="w-[14rem]" value={worksiteId} onChange={(e) => setWorksiteId(e.target.value)}>
            <option value="">{t("attendance.selectWorksite")}</option>
            {worksites.map((worksite) => (
              <option key={worksite.id} value={worksite.id}>
                {worksite.name}
              </option>
            ))}
          </Select>
          <Input className="w-[11rem]" type="date" value={date} onChange={(e) => setDate(e.target.value)} />
        </div>
      </div>

      <Card className="flex flex-wrap items-center justify-between gap-3 p-3">
        <div className="flex flex-wrap items-center gap-3 text-sm text-zinc-600 dark:text-zinc-300">
          <span>
            {t("attendance.workingDays")}: <strong className="tabular-nums">{meta?.working_days_basis ?? "—"}</strong>
            {meta?.working_days_overridden && <Badge tone="amber">{t("attendance.overridden")}</Badge>}
          </span>
          {canApprove && (
            <Button variant="secondary" className="h-8 px-3" onClick={() => setOverriding(true)}>
              {t("attendance.overrideWorkingDays")}
            </Button>
          )}
          {dayApproval && <Badge tone={approvalTone(dayApproval)}>{t(`approval.${dayApproval}`)}</Badge>}
        </div>
        <div className="flex flex-wrap items-center gap-2">
          <Button variant="secondary" onClick={markAllPresent} disabled={rows.length === 0}>
            {t("attendance.markAllPresent")}
          </Button>
          <Button onClick={() => void saveDay()} disabled={saving || rows.length === 0}>
            {saving ? t("common.saving") : t("attendance.saveDay")}
          </Button>
          <Button variant="secondary" onClick={() => void review("submit")} disabled={saving || rows.length === 0}>
            {t("attendance.submitDay")}
          </Button>
          {canApprove && (
            <>
              <Button variant="secondary" onClick={() => void review("approve")} disabled={saving || rows.length === 0}>
                {t("attendance.approveDay")}
              </Button>
              <Button variant="secondary" onClick={() => setRejecting(true)} disabled={saving || rows.length === 0}>
                {t("attendance.rejectDay")}
              </Button>
            </>
          )}
        </div>
      </Card>

      {message && <p className="text-sm text-green-700 dark:text-green-400">{message}</p>}
      {(error ?? rosterError) && <p className="text-sm text-red-600">{error ?? rosterError}</p>}

      <Card className="vui-table scroll-quiet overflow-x-auto p-0">
        <table className="w-full min-w-[1040px] text-sm">
          <thead>
            <tr>
              <th>{t("attendance.worker")}</th>
              <th>{t("attendance.status")}</th>
              <th>{t("attendance.hours")}</th>
              <th>{t("attendance.overtime")}</th>
              <th>{t("attendance.overtimeReason")}</th>
              <th>{t("attendance.note")}</th>
              <th className="text-right">{t("attendance.earned")}</th>
              <th>{t("attendance.approval")}</th>
            </tr>
          </thead>
          <tbody>
            {loading ? (
              <tr>
                <td colSpan={8} className="py-14 text-center text-sm text-zinc-500">
                  {t("common.loading")}
                </td>
              </tr>
            ) : !worksiteId ? (
              <tr>
                <td colSpan={8} className="py-14 text-center text-sm text-zinc-500">
                  {t("attendance.pickWorksite")}
                </td>
              </tr>
            ) : rows.length === 0 ? (
              <tr>
                <td colSpan={8} className="py-14 text-center text-sm text-zinc-500">
                  {t("attendance.emptyRoster")}
                </td>
              </tr>
            ) : (
              rows.map((row) => {
                const draft = drafts[row.employee_id];
                const locked = row.record?.approval_status === "approved" && !canApprove;

                return (
                  <tr key={row.employee_id} className="text-zinc-800 dark:text-zinc-200">
                    <td className="font-medium">
                      {row.full_name}
                      {row.daily_rate !== null && (
                        <span className="ml-2 text-xs text-zinc-500 tabular-nums">
                          {formatMoney(row.daily_rate, row.currency)}/{t("attendance.day")}
                        </span>
                      )}
                    </td>
                    <td>
                      <Select
                        className="h-8 min-w-[8.5rem]"
                        value={draft.status}
                        disabled={locked}
                        onChange={(e) => setDraft(row.employee_id, { status: e.target.value })}
                      >
                        {STATUSES.map((status) => (
                          <option key={status} value={status}>
                            {t(`attendanceStatus.${status}`)}
                          </option>
                        ))}
                      </Select>
                    </td>
                    <td>
                      <Input
                        className="h-8 w-20"
                        type="number"
                        step="0.5"
                        min="0"
                        max="24"
                        placeholder="8"
                        disabled={locked}
                        value={draft.regular_hours}
                        onChange={(e) => setDraft(row.employee_id, { regular_hours: e.target.value })}
                      />
                    </td>
                    <td>
                      <Input
                        className="h-8 w-20"
                        type="number"
                        step="0.5"
                        min="0"
                        max="24"
                        placeholder="0"
                        disabled={locked}
                        value={draft.overtime_hours}
                        onChange={(e) => setDraft(row.employee_id, { overtime_hours: e.target.value })}
                      />
                    </td>
                    <td>
                      <Input
                        className="h-8"
                        disabled={locked}
                        value={draft.overtime_reason}
                        onChange={(e) => setDraft(row.employee_id, { overtime_reason: e.target.value })}
                      />
                    </td>
                    <td>
                      <Input
                        className="h-8"
                        disabled={locked}
                        value={draft.note}
                        onChange={(e) => setDraft(row.employee_id, { note: e.target.value })}
                      />
                    </td>
                    <td className="text-right tabular-nums">
                      {row.record ? (
                        <span title={t("attendance.earnedBreakdown", {
                          regular: row.record.regular_amount,
                          overtime: row.record.overtime_amount,
                          adjustment: row.record.adjustment_amount,
                        })}>
                          {formatMoney(row.record.total_amount, row.record.currency)}
                        </span>
                      ) : (
                        "—"
                      )}
                    </td>
                    <td>
                      {row.record ? (
                        <span className="flex flex-col gap-1">
                          <Badge tone={approvalTone(row.record.approval_status)}>
                            {t(`approval.${row.record.approval_status}`)}
                          </Badge>
                          {row.record.rejection_reason && (
                            <span className="text-xs text-red-600">{row.record.rejection_reason}</span>
                          )}
                          {row.record.adjustment_reason && (
                            <span className="text-xs text-zinc-500">
                              {formatMoney(row.record.adjustment_amount, row.record.currency)} ·{" "}
                              {row.record.adjustment_reason}
                            </span>
                          )}
                        </span>
                      ) : (
                        <span className="text-xs text-zinc-500">{t("attendance.notSaved")}</span>
                      )}
                    </td>
                  </tr>
                );
              })
            )}
          </tbody>
          {rows.length > 0 && (
            <tfoot className="border-t border-zinc-200 dark:border-zinc-800">
              <tr className="text-zinc-800 dark:text-zinc-200">
                <td className="font-medium" colSpan={6}>
                  {t("attendance.dayTotal")}
                </td>
                <td className="text-right font-semibold tabular-nums">{formatMoney(dayTotal)}</td>
                <td />
              </tr>
            </tfoot>
          )}
        </table>
      </Card>

      {rejecting && <RejectModal onClose={() => setRejecting(false)} onReject={reject} saving={saving} />}
      {overriding && (
        <WorkingDaysModal date={date} onClose={() => setOverriding(false)} />
      )}
    </div>
  );
}

function RejectModal({
  onClose,
  onReject,
  saving,
}: {
  onClose: () => void;
  onReject: (reason: string) => Promise<void>;
  saving: boolean;
}) {
  const { t } = useI18n();
  const [reason, setReason] = useState("");

  return (
    <Modal open onClose={onClose} title={t("attendance.rejectDay")}>
      <form
        className="space-y-4"
        onSubmit={(e) => {
          e.preventDefault();
          void onReject(reason.trim());
        }}
      >
        <div>
          <label className="mb-1.5 block px-4 text-[11px] font-medium uppercase tracking-[0.08em] text-zinc-500">
            {t("attendance.rejectReason")}
          </label>
          <Input value={reason} onChange={(e) => setReason(e.target.value)} required />
        </div>
        <div className="flex justify-end gap-2">
          <Button type="button" variant="secondary" onClick={onClose}>
            {t("common.cancel")}
          </Button>
          <Button type="submit" disabled={saving || reason.trim() === ""}>
            {saving ? t("common.saving") : t("attendance.rejectDay")}
          </Button>
        </div>
      </form>
    </Modal>
  );
}

/** Overriding the month's working days changes every daily rate in that month. */
function WorkingDaysModal({ date, onClose }: { date: string; onClose: () => void }) {
  const { t } = useI18n();
  const month = date.slice(0, 7);
  const [workingDays, setWorkingDays] = useState("");
  const [derived, setDerived] = useState<number | null>(null);
  const [isOverridden, setIsOverridden] = useState(false);
  const [reason, setReason] = useState("");
  const [error, setError] = useState<string | null>(null);
  const [saving, setSaving] = useState(false);

  const { data } = useResource<{
    data: { working_days: number; derived_working_days: number; is_overridden: boolean; reason: string | null };
  }>(`/working-days?month=${month}`);

  // Seeded once: these fields are a draft, and a revalidation must not overwrite
  // a figure or reason the user is part-way through typing.
  const seeded = useRef(false);

  useEffect(() => {
    if (seeded.current || !data) return;

    seeded.current = true;
    setWorkingDays(String(data.data.working_days));
    setDerived(data.data.derived_working_days);
    setIsOverridden(data.data.is_overridden);
    setReason(data.data.reason ?? "");
  }, [data]);

  // Closing is all these need to do: the write itself revalidates the roster
  // behind the modal, and with it every daily rate the override changes.
  async function submit(e: React.FormEvent) {
    e.preventDefault();
    setSaving(true);
    setError(null);
    try {
      await apiFetch("/working-days", {
        method: "PUT",
        json: { month, working_days: Number(workingDays), reason: reason.trim() },
      });
      onClose();
    } catch (err) {
      setError(err instanceof ApiError ? err.message : "Error");
    } finally {
      setSaving(false);
    }
  }

  async function clearOverride() {
    setSaving(true);
    try {
      await apiFetch(`/working-days?month=${month}`, { method: "DELETE" });
      onClose();
    } catch (err) {
      setError(err instanceof ApiError ? err.message : "Error");
    } finally {
      setSaving(false);
    }
  }

  const label = "mb-1.5 block px-4 text-[11px] font-medium uppercase tracking-[0.08em] text-zinc-500";

  return (
    <Modal open onClose={onClose} title={`${t("attendance.overrideWorkingDays")} — ${month}`}>
      <form onSubmit={submit} className="space-y-4">
        <p className="text-sm text-zinc-500">{t("attendance.derivedWorkingDays", { days: derived ?? "—" })}</p>
        <div>
          <label className={label}>{t("attendance.workingDays")}</label>
          <Input
            type="number"
            min="1"
            max="31"
            value={workingDays}
            onChange={(e) => setWorkingDays(e.target.value)}
            required
          />
        </div>
        <div>
          <label className={label}>{t("attendance.overrideReason")}</label>
          <Input value={reason} onChange={(e) => setReason(e.target.value)} required />
        </div>
        {error && <p className="text-sm text-red-600">{error}</p>}
        <div className="flex justify-between gap-2">
          {isOverridden ? (
            <Button type="button" variant="secondary" onClick={() => void clearOverride()} disabled={saving}>
              {t("attendance.clearOverride")}
            </Button>
          ) : (
            <span />
          )}
          <div className="flex gap-2">
            <Button type="button" variant="secondary" onClick={onClose}>
              {t("common.cancel")}
            </Button>
            <Button type="submit" disabled={saving || reason.trim() === ""}>
              {saving ? t("common.saving") : t("common.save")}
            </Button>
          </div>
        </div>
      </form>
    </Modal>
  );
}
