"use client";

import { useState } from "react";
import { usePage } from "@/lib/data/use-page";
import { useResource, withQuery } from "@/lib/data/use-resource";
import { ApiError, apiFetch } from "@/lib/api";
import { useI18n } from "@/lib/i18n/context";
import { useDebouncedValue } from "@/lib/use-debounced-value";
import { formatDate, formatMoney } from "@/lib/format";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Card } from "@/components/ui/card";
import { Input } from "@/components/ui/input";
import { Modal } from "@/components/ui/modal";
import { Pagination, type PageMeta } from "@/components/ui/pagination";
import { Select } from "@/components/ui/select";

interface DocumentAlert {
  document: string;
  date: string;
  days_remaining: number;
  expired: boolean;
}

interface Employee {
  id: number;
  first_name: string;
  last_name: string;
  full_name: string;
  origin_country: string | null;
  passport_number: string | null;
  id_number: string | null;
  job_role: string | null;
  bank_account_number: string | null;
  bank_name: string | null;
  bank_account_status: string;
  base_salary: number | null;
  salary_currency: string;
  salary_period: string;
  salary_calculation_rule: string;
  daily_rate_override: number | null;
  overtime_multiplier: number | null;
  overtime_hourly_rate: number | null;
  contract_start_date: string | null;
  contract_end_date: string | null;
  work_permit_expiry: string | null;
  residence_permit_expiry: string | null;
  medical_exam_expiry: string | null;
  safety_training_expiry: string | null;
  status: string;
  missing_documents: string[];
  document_alerts: DocumentAlert[];
  notes: string | null;
  deleted_at: string | null;
}

const BANK_STATUSES = ["unknown", "none", "pending", "open"] as const;
const SALARY_PERIODS = ["monthly", "daily"] as const;
const SALARY_RULES = ["working_days", "fixed_daily"] as const;
const WORKER_STATUSES = ["active", "inactive"] as const;

/** Empty strings become null so optional fields clear instead of failing validation. */
function orNull(value: string): string | null {
  return value.trim() === "" ? null : value.trim();
}

function numberOrNull(value: string): number | null {
  return value.trim() === "" ? null : Number(value);
}

function bankTone(status: string): "gray" | "amber" | "green" | "red" {
  if (status === "open") return "green";
  if (status === "pending") return "amber";
  if (status === "none") return "red";
  return "gray";
}

export default function WorkersPage() {
  const { t } = useI18n();
  const [editing, setEditing] = useState<Employee | null>(null);
  const [creating, setCreating] = useState(false);

  // filters
  const [search, setSearch] = useState("");
  const [status, setStatus] = useState("active");
  const [bankStatus, setBankStatus] = useState("");
  const [expiringOnly, setExpiringOnly] = useState(false);
  const [missingOnly, setMissingOnly] = useState(false);
  const [showRemoved, setShowRemoved] = useState(false);

  const debouncedSearch = useDebouncedValue(search);

  // Changing a filter starts over at the first page.
  const [page, setPage] = usePage([
    debouncedSearch,
    status,
    bankStatus,
    expiringOnly,
    missingOnly,
    showRemoved,
  ]);

  // Through useResource rather than a hand-rolled effect, so this list is cached
  // per URL, deduped, and refetched by `markMutated()` after any write — the
  // behaviour the rest of the app gets for free (see lib/data/cache.ts). The
  // effect version re-requested on every mount, which on a dev server that
  // handles one request at a time is a round trip the user waits through.
  const { data, loading } = useResource<{ data: Employee[]; meta?: PageMeta }>(
    withQuery("/employees", {
      search: debouncedSearch,
      status,
      bank_account_status: bankStatus,
      expiring: expiringOnly,
      missing_documents: missingOnly,
      with_removed: showRemoved,
      per_page: 25,
      page,
    }),
  );

  const employees = data?.data ?? [];
  const meta = data?.meta ?? null;

  // No manual refetch after a write: apiFetch calls markMutated(), which
  // revalidates every mounted resource including this one.
  async function remove(employee: Employee) {
    if (!window.confirm(t("workers.removeConfirm", { name: employee.full_name }))) return;
    await apiFetch(`/employees/${employee.id}`, { method: "DELETE" });
  }

  async function restore(employee: Employee) {
    await apiFetch(`/employees/${employee.id}/restore`, { method: "POST" });
  }

  return (
    <div className="space-y-5">
      <div className="flex items-center justify-between">
        <h1 className="text-[2.5rem] font-light leading-none tracking-[-0.02em] text-zinc-900 dark:text-zinc-50">{t("workers.title")}</h1>
        <Button onClick={() => setCreating(true)}>{t("workers.new")}</Button>
      </div>

      <Card className="p-4">
        <div className="flex flex-wrap items-center gap-3">
          <Input
            className="max-w-xs"
            placeholder={t("workers.search")}
            value={search}
            onChange={(e) => setSearch(e.target.value)}
          />
          <Select className="max-w-[11rem]" value={status} onChange={(e) => setStatus(e.target.value)}>
            <option value="">{t("workers.allStatuses")}</option>
            {WORKER_STATUSES.map((s) => (
              <option key={s} value={s}>
                {t(`workerStatus.${s}`)}
              </option>
            ))}
          </Select>
          <Select className="max-w-[13rem]" value={bankStatus} onChange={(e) => setBankStatus(e.target.value)}>
            <option value="">{t("workers.allBankStatuses")}</option>
            {BANK_STATUSES.map((s) => (
              <option key={s} value={s}>
                {t(`bankStatus.${s}`)}
              </option>
            ))}
          </Select>
          <label className="flex items-center gap-2 text-sm text-zinc-600 dark:text-zinc-300">
            <input type="checkbox" checked={expiringOnly} onChange={(e) => setExpiringOnly(e.target.checked)} />
            {t("workers.expiringOnly")}
          </label>
          <label className="flex items-center gap-2 text-sm text-zinc-600 dark:text-zinc-300">
            <input type="checkbox" checked={missingOnly} onChange={(e) => setMissingOnly(e.target.checked)} />
            {t("workers.missingOnly")}
          </label>
          <label className="flex items-center gap-2 text-sm text-zinc-600 dark:text-zinc-300">
            <input type="checkbox" checked={showRemoved} onChange={(e) => setShowRemoved(e.target.checked)} />
            {t("workers.showRemoved")}
          </label>
        </div>
      </Card>

      <Card className="table-quiet overflow-x-auto p-0">
        <table className="w-full min-w-[980px] text-sm">
          <thead className="border-b border-zinc-900/8 text-left text-[11px] uppercase tracking-[0.1em] text-zinc-500 dark:border-white/10">
            <tr>
              <th className="px-4 py-3">{t("workers.name")}</th>
              <th className="px-4 py-3">{t("workers.jobRole")}</th>
              <th className="px-4 py-3">{t("workers.country")}</th>
              <th className="px-4 py-3">{t("workers.passport")}</th>
              <th className="px-4 py-3 text-right">{t("workers.baseSalary")}</th>
              <th className="px-4 py-3">{t("workers.bankStatus")}</th>
              <th className="px-4 py-3">{t("workers.documents")}</th>
              <th className="px-4 py-3">{t("workers.status")}</th>
              <th className="px-4 py-3 text-right">{t("workers.actions")}</th>
            </tr>
          </thead>
          <tbody className="divide-y divide-zinc-900/5 dark:divide-white/8">
            {loading ? (
              <tr>
                <td colSpan={9} className="px-4 py-14 text-center text-sm text-zinc-500">
                  {t("common.loading")}
                </td>
              </tr>
            ) : employees.length === 0 ? (
              <tr>
                <td colSpan={9} className="px-4 py-14 text-center text-sm text-zinc-500">
                  {t("workers.none")}
                </td>
              </tr>
            ) : (
              employees.map((employee) => (
                <tr key={employee.id} className="text-zinc-800 dark:text-zinc-200">
                  <td className="px-4 py-3 font-medium">
                    <span className="flex items-center gap-2">
                      {employee.full_name}
                      {employee.deleted_at && <Badge tone="gray">{t("workers.removed")}</Badge>}
                    </span>
                  </td>
                  <td className="px-4 py-3">{employee.job_role ?? "—"}</td>
                  <td className="px-4 py-3">{employee.origin_country ?? "—"}</td>
                  <td className="px-4 py-3">{employee.passport_number ?? "—"}</td>
                  <td className="px-4 py-3 text-right tabular-nums">
                    {employee.base_salary === null ? "—" : formatMoney(employee.base_salary, employee.salary_currency)}
                  </td>
                  <td className="px-4 py-3">
                    <Badge tone={bankTone(employee.bank_account_status)}>
                      {t(`bankStatus.${employee.bank_account_status}`)}
                    </Badge>
                  </td>
                  <td className="px-4 py-3">
                    <DocumentCell employee={employee} />
                  </td>
                  <td className="px-4 py-3">
                    <Badge tone={employee.status === "active" ? "green" : "gray"}>
                      {t(`workerStatus.${employee.status}`)}
                    </Badge>
                  </td>
                  <td className="px-4 py-3 text-right">
                    <div className="flex justify-end gap-2">
                      <Button variant="secondary" className="h-8 px-3" onClick={() => setEditing(employee)}>
                        {t("workers.edit")}
                      </Button>
                      {employee.deleted_at ? (
                        <Button variant="secondary" className="h-8 px-3" onClick={() => void restore(employee)}>
                          {t("workers.restore")}
                        </Button>
                      ) : (
                        <Button variant="secondary" className="h-8 px-3" onClick={() => void remove(employee)}>
                          {t("workers.remove")}
                        </Button>
                      )}
                    </div>
                  </td>
                </tr>
              ))
            )}
          </tbody>
        </table>

        <Pagination meta={meta} onPageChange={setPage} disabled={loading} />
      </Card>

      {creating && <WorkerModal onClose={() => setCreating(false)} />}
      {editing && <WorkerModal employee={editing} onClose={() => setEditing(null)} />}
    </div>
  );
}

function DocumentCell({ employee }: { employee: Employee }) {
  const { t } = useI18n();
  const alerts = employee.document_alerts;
  const missing = employee.missing_documents;

  if (alerts.length === 0 && missing.length === 0) {
    return <Badge tone="green">{t("workers.documentsOk")}</Badge>;
  }

  return (
    <div className="flex flex-wrap gap-1">
      {alerts.map((alert) => (
        <Badge key={alert.document} tone={alert.expired ? "red" : "amber"}>
          {t(`doc.${alert.document}`)}{" "}
          {alert.expired
            ? t("workers.expired")
            : t("workers.daysLeft", { days: alert.days_remaining })}
        </Badge>
      ))}
      {missing.length > 0 && (
        <Badge tone="indigo">{t("workers.missingCount", { count: missing.length })}</Badge>
      )}
    </div>
  );
}

interface FormState {
  first_name: string;
  last_name: string;
  origin_country: string;
  passport_number: string;
  id_number: string;
  job_role: string;
  bank_account_number: string;
  bank_name: string;
  bank_account_status: string;
  base_salary: string;
  salary_currency: string;
  salary_period: string;
  salary_calculation_rule: string;
  daily_rate_override: string;
  overtime_multiplier: string;
  overtime_hourly_rate: string;
  contract_start_date: string;
  contract_end_date: string;
  work_permit_expiry: string;
  residence_permit_expiry: string;
  medical_exam_expiry: string;
  safety_training_expiry: string;
  status: string;
  notes: string;
}

function initialForm(employee?: Employee): FormState {
  return {
    first_name: employee?.first_name ?? "",
    last_name: employee?.last_name ?? "",
    origin_country: employee?.origin_country ?? "",
    passport_number: employee?.passport_number ?? "",
    id_number: employee?.id_number ?? "",
    job_role: employee?.job_role ?? "",
    bank_account_number: employee?.bank_account_number ?? "",
    bank_name: employee?.bank_name ?? "",
    bank_account_status: employee?.bank_account_status ?? "unknown",
    base_salary: employee?.base_salary === null || employee?.base_salary === undefined ? "" : String(employee.base_salary),
    salary_currency: employee?.salary_currency ?? "EUR",
    salary_period: employee?.salary_period ?? "monthly",
    salary_calculation_rule: employee?.salary_calculation_rule ?? "working_days",
    daily_rate_override: employee?.daily_rate_override == null ? "" : String(employee.daily_rate_override),
    overtime_multiplier: employee?.overtime_multiplier == null ? "" : String(employee.overtime_multiplier),
    overtime_hourly_rate: employee?.overtime_hourly_rate == null ? "" : String(employee.overtime_hourly_rate),
    contract_start_date: employee?.contract_start_date ?? "",
    contract_end_date: employee?.contract_end_date ?? "",
    work_permit_expiry: employee?.work_permit_expiry ?? "",
    residence_permit_expiry: employee?.residence_permit_expiry ?? "",
    medical_exam_expiry: employee?.medical_exam_expiry ?? "",
    safety_training_expiry: employee?.safety_training_expiry ?? "",
    status: employee?.status ?? "active",
    notes: employee?.notes ?? "",
  };
}

// No onSaved callback: saving goes through apiFetch, whose markMutated() call
// revalidates the list behind this modal on its own.
function WorkerModal({
  employee,
  onClose,
}: {
  employee?: Employee;
  onClose: () => void;
}) {
  const { t } = useI18n();
  const [form, setForm] = useState<FormState>(() => initialForm(employee));
  const [error, setError] = useState<string | null>(null);
  const [saving, setSaving] = useState(false);

  function set<K extends keyof FormState>(key: K, value: string) {
    setForm((prev) => ({ ...prev, [key]: value }));
  }

  async function submit(e: React.FormEvent) {
    e.preventDefault();
    setSaving(true);
    setError(null);
    try {
      await apiFetch(employee ? `/employees/${employee.id}` : "/employees", {
        method: employee ? "PUT" : "POST",
        json: {
          first_name: form.first_name.trim(),
          last_name: form.last_name.trim(),
          origin_country: orNull(form.origin_country),
          passport_number: orNull(form.passport_number),
          id_number: orNull(form.id_number),
          job_role: orNull(form.job_role),
          bank_account_number: orNull(form.bank_account_number),
          bank_name: orNull(form.bank_name),
          bank_account_status: form.bank_account_status,
          base_salary: numberOrNull(form.base_salary),
          salary_currency: form.salary_currency,
          salary_period: form.salary_period,
          salary_calculation_rule: form.salary_calculation_rule,
          daily_rate_override: numberOrNull(form.daily_rate_override),
          overtime_multiplier: numberOrNull(form.overtime_multiplier),
          overtime_hourly_rate: numberOrNull(form.overtime_hourly_rate),
          contract_start_date: orNull(form.contract_start_date),
          contract_end_date: orNull(form.contract_end_date),
          work_permit_expiry: orNull(form.work_permit_expiry),
          residence_permit_expiry: orNull(form.residence_permit_expiry),
          medical_exam_expiry: orNull(form.medical_exam_expiry),
          safety_training_expiry: orNull(form.safety_training_expiry),
          status: form.status,
          notes: orNull(form.notes),
        },
      });
      onClose();
    } catch (err) {
      setError(err instanceof ApiError ? err.message : "Error");
    } finally {
      setSaving(false);
    }
  }

  const label = "mb-1.5 block px-4 text-[11px] font-medium uppercase tracking-[0.08em] text-zinc-500";
  const section = "text-xs font-semibold uppercase tracking-wider text-zinc-500";

  return (
    <Modal open onClose={onClose} title={employee ? t("workers.edit") : t("workers.new")}>
      <form onSubmit={submit} className="space-y-5">
        <p className={section}>{t("workers.identity")}</p>
        <div className="grid grid-cols-2 gap-4">
          <div>
            <label className={label}>{t("workers.firstName")}</label>
            <Input value={form.first_name} onChange={(e) => set("first_name", e.target.value)} required />
          </div>
          <div>
            <label className={label}>{t("workers.lastName")}</label>
            <Input value={form.last_name} onChange={(e) => set("last_name", e.target.value)} required />
          </div>
          <div>
            <label className={label}>{t("workers.country")}</label>
            <Input value={form.origin_country} onChange={(e) => set("origin_country", e.target.value)} />
          </div>
          <div>
            <label className={label}>{t("workers.jobRole")}</label>
            <Input value={form.job_role} onChange={(e) => set("job_role", e.target.value)} />
          </div>
          <div>
            <label className={label}>{t("workers.passport")}</label>
            <Input value={form.passport_number} onChange={(e) => set("passport_number", e.target.value)} />
          </div>
          <div>
            <label className={label}>{t("workers.idNumber")}</label>
            <Input value={form.id_number} onChange={(e) => set("id_number", e.target.value)} />
          </div>
          <div>
            <label className={label}>{t("workers.status")}</label>
            <Select value={form.status} onChange={(e) => set("status", e.target.value)}>
              {WORKER_STATUSES.map((s) => (
                <option key={s} value={s}>
                  {t(`workerStatus.${s}`)}
                </option>
              ))}
            </Select>
          </div>
        </div>

        <p className={section}>{t("workers.salary")}</p>
        <div className="grid grid-cols-2 gap-4">
          <div>
            <label className={label}>{t("workers.baseSalary")}</label>
            <Input
              type="number"
              step="0.01"
              min="0"
              value={form.base_salary}
              onChange={(e) => set("base_salary", e.target.value)}
            />
          </div>
          <div>
            <label className={label}>{t("workers.salaryCurrency")}</label>
            <Select value={form.salary_currency} onChange={(e) => set("salary_currency", e.target.value)}>
              <option value="EUR">EUR</option>
              <option value="TRY">TRY</option>
            </Select>
          </div>
          <div>
            <label className={label}>{t("workers.salaryPeriod")}</label>
            <Select value={form.salary_period} onChange={(e) => set("salary_period", e.target.value)}>
              {SALARY_PERIODS.map((p) => (
                <option key={p} value={p}>
                  {t(`salaryPeriod.${p}`)}
                </option>
              ))}
            </Select>
          </div>
          <div>
            <label className={label}>{t("workers.salaryRule")}</label>
            <Select
              value={form.salary_calculation_rule}
              onChange={(e) => set("salary_calculation_rule", e.target.value)}
            >
              {SALARY_RULES.map((r) => (
                <option key={r} value={r}>
                  {t(`salaryRule.${r}`)}
                </option>
              ))}
            </Select>
          </div>
          <div>
            <label className={label}>{t("workers.dailyRateOverride")}</label>
            <Input
              type="number"
              step="0.01"
              min="0"
              value={form.daily_rate_override}
              onChange={(e) => set("daily_rate_override", e.target.value)}
            />
          </div>
          <div>
            <label className={label}>{t("workers.overtimeMultiplier")}</label>
            <Input
              type="number"
              step="0.05"
              min="0"
              value={form.overtime_multiplier}
              onChange={(e) => set("overtime_multiplier", e.target.value)}
            />
          </div>
          <div>
            <label className={label}>{t("workers.overtimeHourlyRate")}</label>
            <Input
              type="number"
              step="0.01"
              min="0"
              value={form.overtime_hourly_rate}
              onChange={(e) => set("overtime_hourly_rate", e.target.value)}
            />
          </div>
        </div>

        <p className={section}>{t("workers.bankAccount")}</p>
        <div className="grid grid-cols-2 gap-4">
          <div>
            <label className={label}>{t("workers.bankAccountNumber")}</label>
            <Input value={form.bank_account_number} onChange={(e) => set("bank_account_number", e.target.value)} />
          </div>
          <div>
            <label className={label}>{t("workers.bankName")}</label>
            <Input value={form.bank_name} onChange={(e) => set("bank_name", e.target.value)} />
          </div>
          <div>
            <label className={label}>{t("workers.bankStatus")}</label>
            <Select value={form.bank_account_status} onChange={(e) => set("bank_account_status", e.target.value)}>
              {BANK_STATUSES.map((s) => (
                <option key={s} value={s}>
                  {t(`bankStatus.${s}`)}
                </option>
              ))}
            </Select>
          </div>
        </div>

        <p className={section}>{t("workers.documentsSection")}</p>
        <div className="grid grid-cols-2 gap-4">
          <div>
            <label className={label}>{t("workers.contractStart")}</label>
            <Input type="date" value={form.contract_start_date} onChange={(e) => set("contract_start_date", e.target.value)} />
          </div>
          <div>
            <label className={label}>{t("doc.contract_end_date")}</label>
            <Input type="date" value={form.contract_end_date} onChange={(e) => set("contract_end_date", e.target.value)} />
          </div>
          <div>
            <label className={label}>{t("doc.work_permit_expiry")}</label>
            <Input type="date" value={form.work_permit_expiry} onChange={(e) => set("work_permit_expiry", e.target.value)} />
          </div>
          <div>
            <label className={label}>{t("doc.residence_permit_expiry")}</label>
            <Input
              type="date"
              value={form.residence_permit_expiry}
              onChange={(e) => set("residence_permit_expiry", e.target.value)}
            />
          </div>
          <div>
            <label className={label}>{t("doc.medical_exam_expiry")}</label>
            <Input type="date" value={form.medical_exam_expiry} onChange={(e) => set("medical_exam_expiry", e.target.value)} />
          </div>
          <div>
            <label className={label}>{t("doc.safety_training_expiry")}</label>
            <Input
              type="date"
              value={form.safety_training_expiry}
              onChange={(e) => set("safety_training_expiry", e.target.value)}
            />
          </div>
        </div>

        <div>
          <label className={label}>{t("workers.notes")}</label>
          <Input value={form.notes} onChange={(e) => set("notes", e.target.value)} />
        </div>

        {employee && employee.document_alerts.length > 0 && (
          <ul className="space-y-1 text-xs text-zinc-500">
            {employee.document_alerts.map((alert) => (
              <li key={alert.document}>
                {t(`doc.${alert.document}`)}: {formatDate(alert.date)}
              </li>
            ))}
          </ul>
        )}

        {error && <p className="text-sm text-red-600">{error}</p>}
        <div className="flex justify-end gap-2">
          <Button type="button" variant="secondary" onClick={onClose}>
            {t("common.cancel")}
          </Button>
          <Button type="submit" disabled={saving}>
            {saving ? t("common.saving") : employee ? t("common.save") : t("workers.create")}
          </Button>
        </div>
      </form>
    </Modal>
  );
}
