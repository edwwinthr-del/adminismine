"use client";

import { useState } from "react";
import { usePage } from "@/lib/data/use-page";
import { useResource, withQuery } from "@/lib/data/use-resource";
import { useClientOptions, useEmployeeOptions, useSupplierOptions } from "@/lib/data/use-options";
import { ApiError, apiFetch, errorMessage } from "@/lib/api";
import { useI18n } from "@/lib/i18n/context";
import { useDebouncedValue } from "@/lib/use-debounced-value";
import { formatDate, formatMoney, todayISO } from "@/lib/format";
import {
  BankRecordField,
  bankRecordPayload,
  canBook,
  type BankRecordMode,
} from "@/components/bank-record-field";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Card } from "@/components/ui/card";
import { Input } from "@/components/ui/input";
import { Modal } from "@/components/ui/modal";
import { Pagination, type PageMeta } from "@/components/ui/pagination";
import { Select } from "@/components/ui/select";
import { Checkbox } from "@/components/ui/checkbox";

interface Repayment {
  id: number;
  amount: number;
  currency: string;
  payment_date: string | null;
  method: string;
  reference: string | null;
  notes: string | null;
}

interface Loan {
  id: number;
  counterparty: string;
  direction: string;
  reference_number: string | null;
  loan_date: string | null;
  due_date: string | null;
  currency: string;
  original_amount: number;
  exchange_rate: number | null;
  amount_eur: number;
  repaid_amount: number;
  remaining_amount: number;
  status: string;
  is_overdue: boolean;
  supplier_id: number | null;
  supplier?: { id: number; name: string };
  client_id: number | null;
  client?: { id: number; name: string };
  employee_id: number | null;
  employee?: { id: number; full_name: string };
  repayments?: Repayment[];
  repayment_count?: number;
  notes: string | null;
}

interface LoanListResponse {
  data: Loan[];
  meta: PageMeta & {
    total_eur: number;
    repaid_eur: number;
    remaining_eur: number;
    overdue_count: number;
  };
}

const DIRECTIONS = ["received", "given"] as const;
const METHODS = ["cash", "nlb", "lovcen", "other"] as const;
const CURRENCIES = ["EUR", "TRY"] as const;

const labelClass = "mb-1.5 block px-4 text-[11px] font-medium uppercase tracking-[0.08em] text-zinc-500";

function statusTone(status: string): "gray" | "amber" | "green" {
  if (status === "repaid") return "green";
  if (status === "partial") return "amber";
  return "gray";
}

export default function LoansPage() {
  const { t } = useI18n();
  const [onlyOutstanding, setOnlyOutstanding] = useState(false);
  const [search, setSearch] = useState("");
  const [creating, setCreating] = useState(false);
  const [editing, setEditing] = useState<Loan | null>(null);
  const [repaying, setRepaying] = useState<Loan | null>(null);
  const [history, setHistory] = useState<Loan | null>(null);
  const [error, setError] = useState<string | null>(null);

  const debouncedSearch = useDebouncedValue(search);

  const [page, setPage] = usePage([onlyOutstanding, debouncedSearch]);

  const { data, loading, error: listError } = useResource<LoanListResponse>(
    withQuery("/loans", {
      page,
      per_page: 25,
      outstanding: onlyOutstanding,
      search: debouncedSearch,
    }),
  );

  const loans = data?.data ?? [];
  const totals = data?.meta ?? null;

  // No manual refetch: apiFetch's markMutated() revalidates every mounted
  // resource, this list included.
  async function remove(loan: Loan) {
    if (!window.confirm(t("loans.removeConfirm"))) return;
    try {
      await apiFetch(`/loans/${loan.id}`, { method: "DELETE" });
    } catch (err) {
      setError(err instanceof ApiError ? err.message : "Error");
    }
  }

  // Counted by the server: with paging, the rows on screen are not the whole set.
  const overdueCount = totals?.overdue_count ?? 0;

  return (
    <div className="space-y-5">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <h1 className="text-[2.5rem] font-light leading-none tracking-[-0.02em] text-zinc-900 dark:text-zinc-50">{t("loans.title")}</h1>
        <Button onClick={() => setCreating(true)}>{t("loans.new")}</Button>
      </div>

      <div className="grid gap-3 sm:grid-cols-3">
        <Tile label={t("loans.total")} value={formatMoney(totals?.total_eur ?? 0)} />
        <Tile label={t("loans.repaid")} value={formatMoney(totals?.repaid_eur ?? 0)} />
        <Tile
          label={t("loans.remaining")}
          value={formatMoney(totals?.remaining_eur ?? 0)}
          hint={t("loans.overdueHint", { count: overdueCount })}
        />
      </div>

      <Card className="p-4">
        <div className="flex flex-wrap items-center gap-3">
          <Input
            className="max-w-xs"
            placeholder={t("loans.counterparty")}
            value={search}
            onChange={(e) => setSearch(e.target.value)}
          />
          <label className="flex items-center gap-2 text-sm text-zinc-600 dark:text-zinc-300">
            <Checkbox
              checked={onlyOutstanding}
              onChange={(e) => setOnlyOutstanding(e.target.checked)}
              className="h-4 w-4 rounded border-zinc-300 dark:border-zinc-700"
            />
            {t("loans.onlyOutstanding")}
          </label>
        </div>
      </Card>

      {(error ?? listError) && <p className="text-sm text-red-600">{error ?? listError}</p>}

      <Card className="table-quiet scroll-quiet overflow-x-auto p-0">
        <table className="w-full min-w-[1040px] text-sm">
          <thead className="border-b border-zinc-900/8 text-left text-[11px] uppercase tracking-[0.1em] text-zinc-500 dark:border-white/10">
            <tr>
              <th className="px-4 py-3">{t("loans.counterparty")}</th>
              <th className="px-4 py-3">{t("loans.loanDate")}</th>
              <th className="px-4 py-3">{t("loans.dueDate")}</th>
              <th className="px-4 py-3 text-right">{t("loans.originalAmount")}</th>
              <th className="px-4 py-3 text-right">{t("loans.repaid")}</th>
              <th className="px-4 py-3 text-right">{t("loans.remaining")}</th>
              <th className="px-4 py-3">{t("loans.status")}</th>
              <th className="px-4 py-3 text-right">{t("loans.actions")}</th>
            </tr>
          </thead>
          <tbody className="divide-y divide-zinc-900/5 dark:divide-white/8">
            {loading ? (
              <tr>
                <td colSpan={8} className="px-4 py-14 text-center text-sm text-zinc-500">
                  {t("common.loading")}
                </td>
              </tr>
            ) : loans.length === 0 ? (
              <tr>
                <td colSpan={8} className="px-4 py-14 text-center text-sm text-zinc-500">
                  {t("loans.none")}
                </td>
              </tr>
            ) : (
              loans.map((loan) => (
                <tr key={loan.id} className="text-zinc-800 dark:text-zinc-200">
                  <td className="px-4 py-3">
                    <span className="flex flex-col">
                      <span className="font-medium">{loan.counterparty}</span>
                      <span className="text-xs text-zinc-500">
                        {[loan.reference_number, t(`loanDirection.${loan.direction}`)]
                          .filter(Boolean)
                          .join(" · ")}
                      </span>
                    </span>
                  </td>
                  <td className="px-4 py-3">{formatDate(loan.loan_date)}</td>
                  <td className="px-4 py-3">
                    <span className="flex flex-col">
                      <span>{formatDate(loan.due_date)}</span>
                      {loan.is_overdue && (
                        <span className="text-xs text-red-600">{t("loans.overdue")}</span>
                      )}
                    </span>
                  </td>
                  <td className="px-4 py-3 text-right tabular-nums">
                    <span className="flex flex-col">
                      <span>{formatMoney(loan.original_amount, loan.currency)}</span>
                      {loan.currency !== "EUR" && (
                        <span className="text-xs text-zinc-500">{formatMoney(loan.amount_eur)}</span>
                      )}
                    </span>
                  </td>
                  <td className="px-4 py-3 text-right tabular-nums">{formatMoney(loan.repaid_amount)}</td>
                  <td className="px-4 py-3 text-right tabular-nums">{formatMoney(loan.remaining_amount)}</td>
                  <td className="px-4 py-3">
                    <Badge tone={statusTone(loan.status)}>{t(`loanStatus.${loan.status}`)}</Badge>
                  </td>
                  <td className="px-4 py-3 text-right">
                    <div className="flex flex-wrap justify-end gap-2">
                      {loan.remaining_amount > 0 && (
                        <Button variant="secondary" className="h-8 px-3" onClick={() => setRepaying(loan)}>
                          {t("loans.recordRepayment")}
                        </Button>
                      )}
                      <Button variant="secondary" className="h-8 px-3" onClick={() => setHistory(loan)}>
                        {t("loans.history")}
                        {loan.repayment_count ? ` (${loan.repayment_count})` : ""}
                      </Button>
                      <Button variant="secondary" className="h-8 px-3" onClick={() => setEditing(loan)}>
                        {t("loans.edit")}
                      </Button>
                      <Button variant="secondary" className="h-8 px-3" onClick={() => void remove(loan)}>
                        {t("loans.remove")}
                      </Button>
                    </div>
                  </td>
                </tr>
              ))
            )}
          </tbody>
        </table>

        <Pagination meta={totals} onPageChange={setPage} disabled={loading} />
      </Card>

      {(creating || editing) && (
        <LoanModal
          loan={editing ?? undefined}
          onClose={() => {
            setCreating(false);
            setEditing(null);
          }}
         
        />
      )}
      {repaying && (
        <RepaymentModal
          loan={repaying}
          onClose={() => setRepaying(null)}
         
        />
      )}
      {history && <HistoryModal loan={history} onClose={() => setHistory(null)} />}
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

function LoanModal({
  loan,
  onClose,
}: {
  loan?: Loan;
  onClose: () => void;
}) {
  const { t } = useI18n();
  const { options: suppliers } = useSupplierOptions();
  const { options: clients } = useClientOptions();
  const { options: employees } = useEmployeeOptions();
  const [counterparty, setCounterparty] = useState(loan?.counterparty ?? "");
  const [direction, setDirection] = useState(loan?.direction ?? "received");
  const [referenceNumber, setReferenceNumber] = useState(loan?.reference_number ?? "");
  const [loanDate, setLoanDate] = useState(loan?.loan_date ?? todayISO());
  const [dueDate, setDueDate] = useState(loan?.due_date ?? "");
  const [currency, setCurrency] = useState(loan?.currency ?? "EUR");
  const [amount, setAmount] = useState(loan ? String(loan.original_amount) : "");
  const [rate, setRate] = useState(loan?.exchange_rate == null ? "" : String(loan.exchange_rate));
  const [supplierId, setSupplierId] = useState(loan?.supplier_id ? String(loan.supplier_id) : "");
  const [clientId, setClientId] = useState(loan?.client_id ? String(loan.client_id) : "");
  const [employeeId, setEmployeeId] = useState(loan?.employee_id ? String(loan.employee_id) : "");
  const [notes, setNotes] = useState(loan?.notes ?? "");
  const [error, setError] = useState<string | null>(null);
  const [saving, setSaving] = useState(false);

  async function submit(e: React.FormEvent) {
    e.preventDefault();
    setSaving(true);
    setError(null);
    try {
      await apiFetch(loan ? `/loans/${loan.id}` : "/loans", {
        method: loan ? "PUT" : "POST",
        json: {
          counterparty: counterparty.trim(),
          direction,
          reference_number: referenceNumber.trim() || null,
          loan_date: loanDate,
          due_date: dueDate || null,
          currency,
          original_amount: Number(amount),
          exchange_rate: rate === "" ? null : Number(rate),
          supplier_id: supplierId ? Number(supplierId) : null,
          client_id: clientId ? Number(clientId) : null,
          employee_id: employeeId ? Number(employeeId) : null,
          notes: notes.trim() || null,
        },
      });
      onClose();
    } catch (err) {
      setError(err instanceof ApiError ? err.message : "Error");
    } finally {
      setSaving(false);
    }
  }

  return (
    <Modal open onClose={onClose} title={loan ? t("loans.edit") : t("loans.new")}>
      <form onSubmit={submit} className="space-y-4">
        <div className="grid grid-cols-2 gap-4">
          <div>
            <label className={labelClass}>{t("loans.counterparty")}</label>
            <Input
              value={counterparty}
              onChange={(e) => setCounterparty(e.target.value)}
              placeholder="NORTH-EX"
              required
            />
          </div>
          <div>
            <label className={labelClass}>{t("loans.direction")}</label>
            <Select value={direction} onChange={(e) => setDirection(e.target.value)}>
              {DIRECTIONS.map((value) => (
                <option key={value} value={value}>
                  {t(`loanDirection.${value}`)}
                </option>
              ))}
            </Select>
          </div>
          <div>
            <label className={labelClass}>{t("loans.referenceNumber")}</label>
            <Input
              value={referenceNumber}
              onChange={(e) => setReferenceNumber(e.target.value)}
              placeholder="101/24"
            />
          </div>
          <div>
            <label className={labelClass}>{t("loans.loanDate")}</label>
            <Input type="date" value={loanDate} onChange={(e) => setLoanDate(e.target.value)} required />
          </div>
          <div>
            <label className={labelClass}>{t("loans.dueDate")}</label>
            <Input type="date" value={dueDate} onChange={(e) => setDueDate(e.target.value)} />
          </div>
          <div>
            <label className={labelClass}>{t("loans.currency")}</label>
            <Select value={currency} onChange={(e) => setCurrency(e.target.value)}>
              {CURRENCIES.map((value) => (
                <option key={value} value={value}>
                  {value}
                </option>
              ))}
            </Select>
          </div>
          <div>
            <label className={labelClass}>{t("loans.originalAmount")}</label>
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
            <label className={labelClass}>{t("loans.exchangeRate")}</label>
            <Input
              type="number"
              step="0.0001"
              min="0"
              value={rate}
              onChange={(e) => setRate(e.target.value)}
              disabled={currency === "EUR"}
            />
          </div>
        </div>

        <div className="space-y-2 border-t border-zinc-200 pt-4 dark:border-zinc-800">
          <p className="text-sm font-medium text-zinc-700 dark:text-zinc-300">{t("loans.linkSection")}</p>
          <div className="grid grid-cols-3 gap-4">
            <div>
              <label className={labelClass}>{t("loans.supplier")}</label>
              <Select value={supplierId} onChange={(e) => setSupplierId(e.target.value)}>
                <option value="">{t("loans.notLinked")}</option>
                {suppliers.map((supplier) => (
                  <option key={supplier.id} value={supplier.id}>
                    {supplier.name}
                  </option>
                ))}
              </Select>
            </div>
            <div>
              <label className={labelClass}>{t("loans.client")}</label>
              <Select value={clientId} onChange={(e) => setClientId(e.target.value)}>
                <option value="">{t("loans.notLinked")}</option>
                {clients.map((client) => (
                  <option key={client.id} value={client.id}>
                    {client.name}
                  </option>
                ))}
              </Select>
            </div>
            <div>
              <label className={labelClass}>{t("loans.worker")}</label>
              <Select value={employeeId} onChange={(e) => setEmployeeId(e.target.value)}>
                <option value="">{t("loans.notLinked")}</option>
                {employees.map((employee) => (
                  <option key={employee.id} value={employee.id}>
                    {employee.full_name}
                  </option>
                ))}
              </Select>
            </div>
          </div>
        </div>

        <div>
          <label className={labelClass}>{t("loans.notes")}</label>
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

function RepaymentModal({
  loan,
  onClose,
}: {
  loan: Loan;
  onClose: () => void;
}) {
  const { t } = useI18n();
  const [amount, setAmount] = useState(String(loan.remaining_amount));
  const [paymentDate, setPaymentDate] = useState(todayISO());
  const [method, setMethod] = useState<string>("cash");
  const [reference, setReference] = useState("");
  const [notes, setNotes] = useState("");
  const [error, setError] = useState<string | null>(null);
  const [saving, setSaving] = useState(false);

  /*
   * A repayment only reaches the balances, the cashflow and the dashboard if a
   * bank movement records it, so it defaults to booking one — money out for a
   * loan the company took, money in for one it gave.
   */
  const [bankMode, setBankMode] = useState<BankRecordMode>(canBook("cash") ? "book" : "none");
  const [movementId, setMovementId] = useState<number | null>(null);

  function changeMethod(next: string) {
    setMethod(next);

    // `other` names no account, so there is nothing to book into.
    if (!canBook(next) && bankMode === "book") setBankMode("none");
    if (canBook(next) && bankMode === "none") setBankMode("book");
  }

  async function submit(e: React.FormEvent) {
    e.preventDefault();
    setSaving(true);
    setError(null);
    try {
      await apiFetch(`/loans/${loan.id}/repayments`, {
        method: "POST",
        json: {
          amount: Number(amount),
          payment_date: paymentDate,
          method,
          reference: reference.trim() || null,
          notes: notes.trim() || null,
          ...bankRecordPayload(bankMode, movementId),
        },
      });
      onClose();
    } catch (err) {
      setError(err instanceof ApiError ? err.message : "Error");
    } finally {
      setSaving(false);
    }
  }

  return (
    <Modal open onClose={onClose} title={`${t("loans.recordRepayment")} — ${loan.counterparty}`}>
      <form onSubmit={submit} className="space-y-4">
        <div className="grid grid-cols-2 gap-4">
          <div>
            <label className={labelClass}>{t("loans.amount")}</label>
            <Input
              type="number"
              step="0.01"
              min="0.01"
              max={loan.remaining_amount}
              value={amount}
              onChange={(e) => setAmount(e.target.value)}
              required
            />
            <p className="mt-1 text-xs text-zinc-500">
              {t("loans.remaining")}: {formatMoney(loan.remaining_amount)}
            </p>
          </div>
          <div>
            <label className={labelClass}>{t("loans.repaymentDate")}</label>
            <Input type="date" value={paymentDate} onChange={(e) => setPaymentDate(e.target.value)} required />
          </div>
          <div>
            <label className={labelClass}>{t("loans.method")}</label>
            <Select value={method} onChange={(e) => changeMethod(e.target.value)}>
              {METHODS.map((value) => (
                <option key={value} value={value}>
                  {t(`method.${value}`)}
                </option>
              ))}
            </Select>
          </div>
          <div>
            <label className={labelClass}>{t("loans.reference")}</label>
            <Input value={reference} onChange={(e) => setReference(e.target.value)} />
          </div>
          <div className="col-span-2">
            <BankRecordField
              mode={bankMode}
              onModeChange={setBankMode}
              movementId={movementId}
              onMovementChange={setMovementId}
              method={method}
              editing={false}
            />
          </div>
          <div className="col-span-2">
            <label className={labelClass}>{t("loans.notes")}</label>
            <Input value={notes} onChange={(e) => setNotes(e.target.value)} />
          </div>
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

function HistoryModal({ loan, onClose }: { loan: Loan; onClose: () => void }) {
  const { t } = useI18n();

  // Read-only, so it can render the cache directly — there is no draft here that
  // a revalidation could overwrite.
  const { data, loading } = useResource<{ data: Loan }>(`/loans/${loan.id}`);
  const repayments = data?.data.repayments ?? [];

  return (
    <Modal open onClose={onClose} title={`${t("loans.history")} — ${loan.counterparty}`}>
      <div className="space-y-4">
        {loading ? (
          <p className="text-sm text-zinc-500">{t("common.loading")}</p>
        ) : repayments.length === 0 ? (
          <p className="text-sm text-zinc-500">{t("loans.noRepayments")}</p>
        ) : (
          <table className="w-full text-sm">
            <thead className="border-b border-zinc-900/8 text-left text-[11px] uppercase tracking-[0.1em] text-zinc-500 dark:border-white/10">
              <tr>
                <th className="py-2">{t("loans.repaymentDate")}</th>
                <th className="py-2">{t("loans.method")}</th>
                <th className="py-2">{t("loans.reference")}</th>
                <th className="py-2 text-right">{t("loans.amount")}</th>
              </tr>
            </thead>
            <tbody className="divide-y divide-zinc-900/5 dark:divide-white/8">
              {repayments.map((repayment) => (
                <tr key={repayment.id} className="text-zinc-800 dark:text-zinc-200">
                  <td className="py-2">{formatDate(repayment.payment_date)}</td>
                  <td className="py-2">{t(`method.${repayment.method}`)}</td>
                  <td className="py-2 text-xs text-zinc-500">{repayment.reference ?? "—"}</td>
                  <td className="py-2 text-right tabular-nums">{formatMoney(repayment.amount)}</td>
                </tr>
              ))}
            </tbody>
          </table>
        )}
        <div className="flex justify-end">
          <Button variant="secondary" onClick={onClose}>
            {t("common.cancel")}
          </Button>
        </div>
      </div>
    </Modal>
  );
}
