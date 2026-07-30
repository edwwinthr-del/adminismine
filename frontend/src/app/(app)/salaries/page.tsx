"use client";

import { useCallback, useEffect, useState } from "react";
import { ApiError, apiFetch } from "@/lib/api";
import { useI18n } from "@/lib/i18n/context";
import { formatMoney, todayISO } from "@/lib/format";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Card } from "@/components/ui/card";
import { Input } from "@/components/ui/input";
import { Modal } from "@/components/ui/modal";
import { Select } from "@/components/ui/select";

interface SalaryPayment {
  id: number;
  employee_id: number;
  employee?: { id: number; full_name: string; job_role: string | null; bank_account_status: string };
  salary_month: string | null;
  currency: string;
  base_salary: number;
  adjustments: number;
  deductions: number;
  net_salary_due: number;
  paid_amount: number;
  remaining_amount: number;
  status: string;
  notes: string | null;
}

interface Summary {
  month: string;
  employees: number;
  net_due: number;
  paid: number;
  remaining: number;
  unpaid_count: number;
  partial_count: number;
  paid_count: number;
}

interface SkippedEmployee {
  employee_id: number;
  full_name: string;
  reason: string;
}

interface GenerateResult {
  data: SalaryPayment[];
  meta: {
    month: string;
    preview: boolean;
    created_count: number;
    skipped: SkippedEmployee[];
    already_existing_count: number;
  };
}

const METHODS = ["cash", "nlb", "lovcen", "other"] as const;

function currentMonth(): string {
  return todayISO().slice(0, 7);
}

function statusTone(status: string): "gray" | "amber" | "green" {
  if (status === "paid") return "green";
  if (status === "partial") return "amber";
  return "gray";
}

export default function SalariesPage() {
  const { t } = useI18n();
  const [month, setMonth] = useState(currentMonth);
  const [status, setStatus] = useState("");
  const [rows, setRows] = useState<SalaryPayment[]>([]);
  const [summary, setSummary] = useState<Summary | null>(null);
  const [loading, setLoading] = useState(true);
  const [paying, setPaying] = useState<SalaryPayment | null>(null);
  const [adjusting, setAdjusting] = useState<SalaryPayment | null>(null);
  const [preview, setPreview] = useState<GenerateResult | null>(null);

  const load = useCallback(async () => {
    setLoading(true);
    const params = new URLSearchParams({ month, per_page: "200" });
    if (status) params.set("status", status);
    try {
      const [list, sum] = await Promise.all([
        apiFetch<{ data: SalaryPayment[] }>(`/salary-payments?${params.toString()}`),
        apiFetch<{ data: Summary }>(`/salary-payments/summary?month=${month}`),
      ]);
      setRows(list.data);
      setSummary(sum.data);
    } finally {
      setLoading(false);
    }
  }, [month, status]);

  useEffect(() => {
    void load();
  }, [load]);

  async function openPreview() {
    const result = await apiFetch<GenerateResult>("/salary-payments/generate", {
      method: "POST",
      json: { month, preview: true },
    });
    setPreview(result);
  }

  async function confirmGenerate() {
    await apiFetch("/salary-payments/generate", { method: "POST", json: { month } });
    setPreview(null);
    await load();
  }

  async function remove(row: SalaryPayment) {
    if (!window.confirm(t("salaries.deleteConfirm"))) return;
    await apiFetch(`/salary-payments/${row.id}`, { method: "DELETE" });
    await load();
  }

  return (
    <div className="space-y-5">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <h1 className="text-2xl font-semibold text-zinc-900 dark:text-zinc-50">{t("salaries.title")}</h1>
        <div className="flex items-center gap-3">
          <Input className="w-[10rem]" type="month" value={month} onChange={(e) => setMonth(e.target.value)} />
          <Button onClick={() => void openPreview()}>{t("salaries.generate")}</Button>
        </div>
      </div>

      <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
        <SummaryTile label={t("salaries.employees")} value={String(summary?.employees ?? 0)} />
        <SummaryTile label={t("salaries.netDue")} value={formatMoney(summary?.net_due ?? 0)} />
        <SummaryTile label={t("salaries.paid")} value={formatMoney(summary?.paid ?? 0)} />
        <SummaryTile
          label={t("salaries.remaining")}
          value={formatMoney(summary?.remaining ?? 0)}
          hint={t("salaries.unpaidCount", { count: summary?.unpaid_count ?? 0 })}
        />
      </div>

      <Card className="p-3">
        <div className="flex flex-wrap items-center gap-3">
          <Select className="max-w-[12rem]" value={status} onChange={(e) => setStatus(e.target.value)}>
            <option value="">{t("salaries.allStatuses")}</option>
            <option value="unpaid">{t("status.unpaid")}</option>
            <option value="partial">{t("status.partial")}</option>
            <option value="paid">{t("status.paid")}</option>
          </Select>
        </div>
      </Card>

      <Card className="overflow-x-auto p-0">
        <table className="w-full min-w-[940px] text-sm">
          <thead className="border-b border-zinc-200 text-left text-xs uppercase tracking-wider text-zinc-500 dark:border-zinc-800">
            <tr>
              <th className="px-4 py-3">{t("salaries.worker")}</th>
              <th className="px-4 py-3 text-right">{t("salaries.base")}</th>
              <th className="px-4 py-3 text-right">{t("salaries.adjustments")}</th>
              <th className="px-4 py-3 text-right">{t("salaries.deductions")}</th>
              <th className="px-4 py-3 text-right">{t("salaries.netDue")}</th>
              <th className="px-4 py-3 text-right">{t("salaries.paid")}</th>
              <th className="px-4 py-3 text-right">{t("salaries.remaining")}</th>
              <th className="px-4 py-3">{t("salaries.status")}</th>
              <th className="px-4 py-3 text-right">{t("salaries.actions")}</th>
            </tr>
          </thead>
          <tbody className="divide-y divide-zinc-100 dark:divide-zinc-800">
            {loading ? (
              <tr>
                <td colSpan={9} className="px-4 py-8 text-center text-zinc-500">
                  {t("common.loading")}
                </td>
              </tr>
            ) : rows.length === 0 ? (
              <tr>
                <td colSpan={9} className="px-4 py-8 text-center text-zinc-500">
                  {t("salaries.none")}
                </td>
              </tr>
            ) : (
              rows.map((row) => (
                <tr key={row.id} className="text-zinc-800 dark:text-zinc-200">
                  <td className="px-4 py-3 font-medium">{row.employee?.full_name ?? `#${row.employee_id}`}</td>
                  <td className="px-4 py-3 text-right tabular-nums">{formatMoney(row.base_salary, row.currency)}</td>
                  <td className="px-4 py-3 text-right tabular-nums">{formatMoney(row.adjustments, row.currency)}</td>
                  <td className="px-4 py-3 text-right tabular-nums">{formatMoney(row.deductions, row.currency)}</td>
                  <td className="px-4 py-3 text-right font-medium tabular-nums">
                    {formatMoney(row.net_salary_due, row.currency)}
                  </td>
                  <td className="px-4 py-3 text-right tabular-nums">{formatMoney(row.paid_amount, row.currency)}</td>
                  <td className="px-4 py-3 text-right font-medium tabular-nums">
                    {formatMoney(row.remaining_amount, row.currency)}
                  </td>
                  <td className="px-4 py-3">
                    <Badge tone={statusTone(row.status)}>{t(`status.${row.status}`)}</Badge>
                  </td>
                  <td className="px-4 py-3 text-right">
                    <div className="flex justify-end gap-2">
                      {row.status !== "paid" && (
                        <Button variant="secondary" className="h-8 px-3" onClick={() => setPaying(row)}>
                          {t("salaries.recordPayment")}
                        </Button>
                      )}
                      <Button variant="secondary" className="h-8 px-3" onClick={() => setAdjusting(row)}>
                        {t("salaries.adjust")}
                      </Button>
                      {row.paid_amount === 0 && (
                        <Button variant="secondary" className="h-8 px-3" onClick={() => void remove(row)}>
                          {t("salaries.delete")}
                        </Button>
                      )}
                    </div>
                  </td>
                </tr>
              ))
            )}
          </tbody>
        </table>
      </Card>

      {preview && (
        <GeneratePreviewModal
          result={preview}
          onClose={() => setPreview(null)}
          onConfirm={confirmGenerate}
        />
      )}
      {paying && <RecordPaymentModal row={paying} onClose={() => setPaying(null)} onSaved={load} />}
      {adjusting && <AdjustModal row={adjusting} onClose={() => setAdjusting(null)} onSaved={load} />}
    </div>
  );
}

function SummaryTile({ label, value, hint }: { label: string; value: string; hint?: string }) {
  return (
    <Card className="p-4">
      <p className="text-xs uppercase tracking-wider text-zinc-500">{label}</p>
      <p className="mt-1 text-xl font-semibold tabular-nums text-zinc-900 dark:text-zinc-50">{value}</p>
      {hint && <p className="mt-1 text-xs text-zinc-500">{hint}</p>}
    </Card>
  );
}

/** Nothing is written until the office confirms this list. */
function GeneratePreviewModal({
  result,
  onClose,
  onConfirm,
}: {
  result: GenerateResult;
  onClose: () => void;
  onConfirm: () => Promise<void>;
}) {
  const { t } = useI18n();
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState<string | null>(null);

  async function confirm() {
    setSaving(true);
    setError(null);
    try {
      await onConfirm();
    } catch (err) {
      setError(err instanceof ApiError ? err.message : "Error");
    } finally {
      setSaving(false);
    }
  }

  return (
    <Modal open onClose={onClose} title={t("salaries.generatePreview", { month: result.meta.month.slice(0, 7) })}>
      <div className="space-y-4">
        <p className="text-sm text-zinc-600 dark:text-zinc-300">
          {t("salaries.willCreate", { count: result.meta.created_count })}
          {result.meta.already_existing_count > 0 &&
            ` · ${t("salaries.alreadyExists", { count: result.meta.already_existing_count })}`}
        </p>

        {result.data.length > 0 && (
          <ul className="max-h-52 space-y-1 overflow-y-auto text-sm text-zinc-700 dark:text-zinc-200">
            {result.data.map((row) => (
              <li key={row.employee_id} className="flex justify-between gap-4">
                <span>{row.employee?.full_name ?? `#${row.employee_id}`}</span>
                <span className="tabular-nums">{formatMoney(row.net_salary_due, row.currency)}</span>
              </li>
            ))}
          </ul>
        )}

        {result.meta.skipped.length > 0 && (
          <div>
            <p className="mb-1 text-xs font-semibold uppercase tracking-wider text-zinc-500">
              {t("salaries.skipped")}
            </p>
            <ul className="max-h-40 space-y-1 overflow-y-auto text-sm text-zinc-500">
              {result.meta.skipped.map((skipped) => (
                <li key={skipped.employee_id} className="flex justify-between gap-4">
                  <span>{skipped.full_name}</span>
                  <span>{t(`salaries.reason.${skipped.reason}`)}</span>
                </li>
              ))}
            </ul>
          </div>
        )}

        {error && <p className="text-sm text-red-600">{error}</p>}
        <div className="flex justify-end gap-2">
          <Button type="button" variant="secondary" onClick={onClose}>
            {t("common.cancel")}
          </Button>
          <Button onClick={() => void confirm()} disabled={saving || result.meta.created_count === 0}>
            {saving ? t("common.saving") : t("salaries.confirmGenerate")}
          </Button>
        </div>
      </div>
    </Modal>
  );
}

function RecordPaymentModal({
  row,
  onClose,
  onSaved,
}: {
  row: SalaryPayment;
  onClose: () => void;
  onSaved: () => Promise<void>;
}) {
  const { t } = useI18n();
  const [amount, setAmount] = useState(String(row.remaining_amount));
  const [paymentDate, setPaymentDate] = useState(todayISO());
  const [method, setMethod] = useState<string>("cash");
  const [error, setError] = useState<string | null>(null);
  const [saving, setSaving] = useState(false);

  async function submit(e: React.FormEvent) {
    e.preventDefault();
    setSaving(true);
    setError(null);
    try {
      await apiFetch(`/salary-payments/${row.id}/payments`, {
        method: "POST",
        json: { amount: Number(amount), payment_date: paymentDate, method },
      });
      await onSaved();
      onClose();
    } catch (err) {
      setError(err instanceof ApiError ? err.message : "Error");
    } finally {
      setSaving(false);
    }
  }

  const label = "mb-1 block text-sm font-medium text-zinc-700 dark:text-zinc-300";

  return (
    <Modal open onClose={onClose} title={t("salaries.recordPayment")}>
      <form onSubmit={submit} className="space-y-4">
        <p className="text-sm text-zinc-500">{row.employee?.full_name}</p>
        <div className="grid grid-cols-2 gap-4">
          <div>
            <label className={label}>{t("salaries.amount")}</label>
            <Input
              type="number"
              step="0.01"
              min="0"
              value={amount}
              onChange={(e) => setAmount(e.target.value)}
              required
            />
          </div>
          <div>
            <label className={label}>{t("salaries.paymentDate")}</label>
            <Input type="date" value={paymentDate} onChange={(e) => setPaymentDate(e.target.value)} required />
          </div>
        </div>
        <div>
          <label className={label}>{t("salaries.method")}</label>
          <Select value={method} onChange={(e) => setMethod(e.target.value)}>
            {METHODS.map((m) => (
              <option key={m} value={m}>
                {t(`method.${m}`)}
              </option>
            ))}
          </Select>
        </div>
        {error && <p className="text-sm text-red-600">{error}</p>}
        <div className="flex justify-end gap-2">
          <Button type="button" variant="secondary" onClick={onClose}>
            {t("common.cancel")}
          </Button>
          <Button type="submit" disabled={saving}>
            {saving ? t("common.saving") : t("salaries.recordPayment")}
          </Button>
        </div>
      </form>
    </Modal>
  );
}

function AdjustModal({
  row,
  onClose,
  onSaved,
}: {
  row: SalaryPayment;
  onClose: () => void;
  onSaved: () => Promise<void>;
}) {
  const { t } = useI18n();
  const [base, setBase] = useState(String(row.base_salary));
  const [adjustments, setAdjustments] = useState(String(row.adjustments));
  const [deductions, setDeductions] = useState(String(row.deductions));
  const [notes, setNotes] = useState(row.notes ?? "");
  const [error, setError] = useState<string | null>(null);
  const [saving, setSaving] = useState(false);

  const net = Number(base || 0) + Number(adjustments || 0) - Number(deductions || 0);

  async function submit(e: React.FormEvent) {
    e.preventDefault();
    setSaving(true);
    setError(null);
    try {
      await apiFetch(`/salary-payments/${row.id}`, {
        method: "PUT",
        json: {
          base_salary: Number(base),
          adjustments: Number(adjustments),
          deductions: Number(deductions),
          notes: notes.trim() === "" ? null : notes.trim(),
        },
      });
      await onSaved();
      onClose();
    } catch (err) {
      setError(err instanceof ApiError ? err.message : "Error");
    } finally {
      setSaving(false);
    }
  }

  const label = "mb-1 block text-sm font-medium text-zinc-700 dark:text-zinc-300";

  return (
    <Modal open onClose={onClose} title={t("salaries.adjust")}>
      <form onSubmit={submit} className="space-y-4">
        <p className="text-sm text-zinc-500">{row.employee?.full_name}</p>
        <div className="grid grid-cols-2 gap-4">
          <div>
            <label className={label}>{t("salaries.base")}</label>
            <Input type="number" step="0.01" min="0" value={base} onChange={(e) => setBase(e.target.value)} required />
          </div>
          <div>
            <label className={label}>{t("salaries.adjustments")}</label>
            <Input type="number" step="0.01" value={adjustments} onChange={(e) => setAdjustments(e.target.value)} />
          </div>
          <div>
            <label className={label}>{t("salaries.deductions")}</label>
            <Input
              type="number"
              step="0.01"
              min="0"
              value={deductions}
              onChange={(e) => setDeductions(e.target.value)}
            />
          </div>
          <div>
            <label className={label}>{t("salaries.netDue")}</label>
            <p className="py-2 font-medium tabular-nums text-zinc-900 dark:text-zinc-50">
              {formatMoney(net, row.currency)}
            </p>
          </div>
        </div>
        <div>
          <label className={label}>{t("salaries.notes")}</label>
          <Input value={notes} onChange={(e) => setNotes(e.target.value)} />
        </div>
        {error && <p className="text-sm text-red-600">{error}</p>}
        <div className="flex justify-end gap-2">
          <Button type="button" variant="secondary" onClick={onClose}>
            {t("common.cancel")}
          </Button>
          <Button type="submit" disabled={saving}>
            {saving ? t("common.saving") : t("common.save")}
          </Button>
        </div>
      </form>
    </Modal>
  );
}
