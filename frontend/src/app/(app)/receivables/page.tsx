"use client";

import { useState } from "react";
import { apiFetch, errorMessage } from "@/lib/api";
import { usePage } from "@/lib/data/use-page";
import { useResource, withQuery } from "@/lib/data/use-resource";
import { useI18n } from "@/lib/i18n/context";
import { formatDate, formatMoney, todayISO } from "@/lib/format";
import { useDebouncedValue } from "@/lib/use-debounced-value";
import { AsyncSelect } from "@/components/ui/async-select";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Card } from "@/components/ui/card";
import { Input } from "@/components/ui/input";
import { Modal } from "@/components/ui/modal";
import { Pagination, type PageMeta } from "@/components/ui/pagination";
import { Select } from "@/components/ui/select";

interface Payment {
  id: number;
  amount: number;
  payment_date: string | null;
  method: string;
  reference: string | null;
  bank_transaction_id: number | null;
}

interface Deduction {
  id: number;
  amount: number;
  deduction_date: string | null;
  reason: string | null;
}

interface ReceivableInvoice {
  id: number;
  client_id: number;
  client?: { id: number; name: string };
  invoice_number: string | null;
  invoice_date: string | null;
  due_date: string | null;
  description: string | null;
  currency: string;
  invoice_amount: number;
  received_amount: number;
  deducted_amount: number;
  remaining_amount: number;
  status: string;
  is_overdue: boolean;
  notes: string | null;
  payments?: Payment[];
  deductions?: Deduction[];
}

interface StatementEntry {
  date: string | null;
  type: string;
  reference: string | null;
  debit: number;
  credit: number;
  balance: number;
}

const METHODS = ["cash", "nlb", "lovcen", "other"] as const;
const PER_PAGE = 25;

function statusTone(status: string): "gray" | "amber" | "green" {
  if (status === "paid") return "green";
  if (status === "partial") return "amber";
  return "gray";
}

export default function ReceivablesPage() {
  const { t } = useI18n();

  const [status, setStatus] = useState("");
  const [search, setSearch] = useState("");
  const [overdue, setOverdue] = useState(false);

  const debouncedSearch = useDebouncedValue(search);

  const [creating, setCreating] = useState(false);
  const [editing, setEditing] = useState<ReceivableInvoice | null>(null);
  const [settling, setSettling] = useState<ReceivableInvoice | null>(null);

  const [page, setPage] = usePage([status, debouncedSearch, overdue]);

  const { data, loading, refreshing, error } = useResource<{ data: ReceivableInvoice[]; meta: PageMeta }>(
    withQuery("/receivables", {
      status,
      search: debouncedSearch,
      overdue,
      page,
      per_page: PER_PAGE,
    }),
  );

  const invoices = data?.data ?? [];

  return (
    <div className="space-y-5">
      <div className="flex items-center justify-between">
        <h1 className="text-2xl font-semibold text-zinc-900 dark:text-zinc-50">{t("receivables.title")}</h1>
        <div className="flex gap-2">
          <StatementButton />
          <Button onClick={() => setCreating(true)}>{t("receivables.new")}</Button>
        </div>
      </div>

      <Card className="p-3">
        <div className="flex flex-wrap items-center gap-3">
          <Input
            className="max-w-xs"
            placeholder={t("receivables.search")}
            value={search}
            onChange={(e) => setSearch(e.target.value)}
          />
          <Select className="max-w-[12rem]" value={status} onChange={(e) => setStatus(e.target.value)}>
            <option value="">{t("receivables.allStatuses")}</option>
            <option value="unpaid">{t("status.unpaid")}</option>
            <option value="partial">{t("status.partial")}</option>
            <option value="paid">{t("status.paid")}</option>
          </Select>
          <label className="flex items-center gap-2 text-sm text-zinc-600 dark:text-zinc-300">
            <input type="checkbox" checked={overdue} onChange={(e) => setOverdue(e.target.checked)} />
            {t("receivables.overdueOnly")}
          </label>
        </div>
      </Card>

      {error && <p className="text-sm text-red-600">{error}</p>}

      <Card className="overflow-x-auto p-0">
        <table className="w-full min-w-[960px] text-sm">
          <thead className="border-b border-zinc-200 text-left text-xs uppercase tracking-wider text-zinc-500 dark:border-zinc-800">
            <tr>
              <th className="px-4 py-3">{t("receivables.client")}</th>
              <th className="px-4 py-3">{t("receivables.invoiceNumber")}</th>
              <th className="px-4 py-3">{t("receivables.invoiceDate")}</th>
              <th className="px-4 py-3">{t("receivables.dueDate")}</th>
              <th className="px-4 py-3 text-right">{t("receivables.amount")}</th>
              <th className="px-4 py-3 text-right">{t("receivables.received")}</th>
              <th className="px-4 py-3 text-right">{t("receivables.deducted")}</th>
              <th className="px-4 py-3 text-right">{t("receivables.remaining")}</th>
              <th className="px-4 py-3">{t("receivables.status")}</th>
              <th className="px-4 py-3 text-right">{t("receivables.actions")}</th>
            </tr>
          </thead>
          <tbody
            className={
              refreshing
                ? "divide-y divide-zinc-100 opacity-60 dark:divide-zinc-800"
                : "divide-y divide-zinc-100 dark:divide-zinc-800"
            }
          >
            {loading ? (
              <tr>
                <td colSpan={10} className="px-4 py-8 text-center text-zinc-500">
                  {t("common.loading")}
                </td>
              </tr>
            ) : invoices.length === 0 ? (
              <tr>
                <td colSpan={10} className="px-4 py-8 text-center text-zinc-500">
                  {t("receivables.none")}
                </td>
              </tr>
            ) : (
              invoices.map((inv) => (
                <tr key={inv.id} className="text-zinc-800 dark:text-zinc-200">
                  <td className="px-4 py-3 font-medium">{inv.client?.name ?? "—"}</td>
                  <td className="px-4 py-3">{inv.invoice_number ?? "—"}</td>
                  <td className="px-4 py-3">{formatDate(inv.invoice_date)}</td>
                  <td className="px-4 py-3">
                    <span className="flex items-center gap-1">
                      {formatDate(inv.due_date)}
                      {inv.is_overdue && <Badge tone="red">{t("payables.overdue")}</Badge>}
                    </span>
                  </td>
                  <td className="px-4 py-3 text-right tabular-nums">{formatMoney(inv.invoice_amount, inv.currency)}</td>
                  <td className="px-4 py-3 text-right tabular-nums">{formatMoney(inv.received_amount, inv.currency)}</td>
                  <td className="px-4 py-3 text-right tabular-nums">{formatMoney(inv.deducted_amount, inv.currency)}</td>
                  <td className="px-4 py-3 text-right font-medium tabular-nums">
                    {formatMoney(inv.remaining_amount, inv.currency)}
                  </td>
                  <td className="px-4 py-3">
                    <Badge tone={statusTone(inv.status)}>{t(`status.${inv.status}`)}</Badge>
                  </td>
                  <td className="px-4 py-3 text-right">
                    {/* Offered on every row: a settled invoice still has to be correctable. */}
                    <div className="flex justify-end gap-2">
                      <Button variant="secondary" className="h-8 px-3" onClick={() => setEditing(inv)}>
                        {t("receivables.edit")}
                      </Button>
                      <Button variant="secondary" className="h-8 px-3" onClick={() => setSettling(inv)}>
                        {t("receivables.manage")}
                      </Button>
                    </div>
                  </td>
                </tr>
              ))
            )}
          </tbody>
        </table>

        <Pagination meta={data?.meta} onPageChange={setPage} disabled={refreshing} />
      </Card>

      {creating && <InvoiceModal onClose={() => setCreating(false)} />}
      {editing && <InvoiceModal invoice={editing} onClose={() => setEditing(null)} />}
      {settling && <SettlementModal invoiceId={settling.id} onClose={() => setSettling(null)} />}
    </div>
  );
}

function InvoiceModal({ invoice, onClose }: { invoice?: ReceivableInvoice; onClose: () => void }) {
  const { t } = useI18n();
  const editing = invoice !== undefined;

  const [clientId, setClientId] = useState<number | null>(invoice?.client_id ?? null);
  const [invoiceNumber, setInvoiceNumber] = useState(invoice?.invoice_number ?? "");
  const [invoiceDate, setInvoiceDate] = useState(invoice?.invoice_date ?? todayISO());
  const [dueDate, setDueDate] = useState(invoice?.due_date ?? "");
  const [amount, setAmount] = useState(invoice ? String(invoice.invoice_amount) : "");
  const [description, setDescription] = useState(invoice?.description ?? "");
  const [notes, setNotes] = useState(invoice?.notes ?? "");
  const [newClient, setNewClient] = useState("");
  const [error, setError] = useState<string | null>(null);
  const [saving, setSaving] = useState(false);

  async function addClient() {
    if (!newClient.trim()) return;

    try {
      const res = await apiFetch<{ data: { id: number } }>("/clients", {
        method: "POST",
        json: { name: newClient.trim() },
      });
      setClientId(res.data.id);
      setNewClient("");
    } catch (err) {
      setError(errorMessage(err));
    }
  }

  async function submit(e: React.FormEvent) {
    e.preventDefault();
    setSaving(true);
    setError(null);

    try {
      await apiFetch(editing ? `/receivables/${invoice.id}` : "/receivables", {
        method: editing ? "PUT" : "POST",
        json: {
          client_id: clientId,
          invoice_number: invoiceNumber || null,
          invoice_date: invoiceDate,
          due_date: dueDate || null,
          invoice_amount: Number(amount),
          description: description || null,
          notes: notes || null,
        },
      });
      onClose();
    } catch (err) {
      setError(errorMessage(err));
    } finally {
      setSaving(false);
    }
  }

  async function remove() {
    if (!editing || !window.confirm(t("receivables.removeConfirm"))) return;

    setSaving(true);
    try {
      await apiFetch(`/receivables/${invoice.id}`, { method: "DELETE" });
      onClose();
    } catch (err) {
      setError(errorMessage(err));
      setSaving(false);
    }
  }

  const label = "mb-1 block text-sm font-medium text-zinc-700 dark:text-zinc-300";

  return (
    <Modal open onClose={onClose} title={editing ? t("receivables.editInvoice") : t("receivables.new")}>
      <form onSubmit={submit} className="space-y-4">
        {editing && invoice.status === "paid" && (
          <p className="rounded-md bg-zinc-50 px-3 py-2 text-xs text-zinc-600 dark:bg-zinc-800 dark:text-zinc-300">
            {t("receivables.correctable")}
          </p>
        )}

        <div>
          <label className={label}>{t("receivables.client")}</label>
          <AsyncSelect
            resource="clients"
            value={clientId}
            onChange={setClientId}
            params={{ active_only: true }}
            placeholder={t("receivables.selectClient")}
            required
          />
          <div className="mt-2 flex gap-2">
            <Input
              placeholder={t("receivables.clientName")}
              value={newClient}
              onChange={(e) => setNewClient(e.target.value)}
            />
            <Button type="button" variant="secondary" onClick={() => void addClient()}>
              {t("receivables.add")}
            </Button>
          </div>
        </div>

        <div className="grid grid-cols-2 gap-4">
          <div>
            <label className={label}>{t("receivables.invoiceNumber")}</label>
            <Input value={invoiceNumber} onChange={(e) => setInvoiceNumber(e.target.value)} />
          </div>
          <div>
            <label className={label}>{t("receivables.amount")}</label>
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
            <label className={label}>{t("receivables.invoiceDate")}</label>
            <Input type="date" value={invoiceDate} onChange={(e) => setInvoiceDate(e.target.value)} required />
          </div>
          <div>
            <label className={label}>{t("receivables.dueDate")}</label>
            <Input type="date" value={dueDate} onChange={(e) => setDueDate(e.target.value)} />
          </div>
        </div>

        <div>
          <label className={label}>{t("receivables.description")}</label>
          <Input value={description} onChange={(e) => setDescription(e.target.value)} />
        </div>
        <div>
          <label className={label}>{t("receivables.notes")}</label>
          <Input value={notes} onChange={(e) => setNotes(e.target.value)} />
        </div>

        {error && <p className="text-sm text-red-600">{error}</p>}

        <div className="flex justify-between gap-2">
          {editing ? (
            <Button type="button" variant="danger" disabled={saving} onClick={() => void remove()}>
              {t("receivables.remove")}
            </Button>
          ) : (
            <span />
          )}
          <div className="flex gap-2">
            <Button type="button" variant="secondary" onClick={onClose}>
              {t("common.cancel")}
            </Button>
            <Button type="submit" disabled={saving || clientId === null}>
              {saving ? t("common.saving") : editing ? t("common.save") : t("receivables.create")}
            </Button>
          </div>
        </div>
      </form>
    </Modal>
  );
}

/**
 * Everything settling one invoice — payments received and deductions — each
 * correctable and removable in place.
 */
function SettlementModal({ invoiceId, onClose }: { invoiceId: number; onClose: () => void }) {
  const { t } = useI18n();
  const { data, loading } = useResource<{ data: ReceivableInvoice }>(`/receivables/${invoiceId}`);
  const invoice = data?.data;

  const [addingPayment, setAddingPayment] = useState(false);
  const [addingDeduction, setAddingDeduction] = useState(false);
  const [editingPayment, setEditingPayment] = useState<Payment | null>(null);
  const [editingDeduction, setEditingDeduction] = useState<Deduction | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);

  async function remove(kind: "payments" | "deductions", id: number, confirmKey: string) {
    if (!window.confirm(t(confirmKey))) return;

    setBusy(true);
    setError(null);
    try {
      await apiFetch(`/receivables/${invoiceId}/${kind}/${id}`, { method: "DELETE" });
    } catch (err) {
      setError(errorMessage(err));
    } finally {
      setBusy(false);
    }
  }

  return (
    <Modal open onClose={onClose} title={t("receivables.manage")}>
      {loading || !invoice ? (
        <p className="text-sm text-zinc-500">{t("common.loading")}</p>
      ) : (
        <div className="space-y-4">
          <div className="grid grid-cols-4 gap-2 rounded-md bg-zinc-50 p-3 text-sm dark:bg-zinc-800">
            <Figure label={t("receivables.amount")} value={formatMoney(invoice.invoice_amount, invoice.currency)} />
            <Figure label={t("receivables.received")} value={formatMoney(invoice.received_amount, invoice.currency)} />
            <Figure label={t("receivables.deducted")} value={formatMoney(invoice.deducted_amount, invoice.currency)} />
            <Figure label={t("receivables.remaining")} value={formatMoney(invoice.remaining_amount, invoice.currency)} />
          </div>

          <section>
            <p className="mb-2 text-sm font-semibold text-zinc-900 dark:text-zinc-50">
              {t("receivables.paymentsTitle")}
            </p>
            {(invoice.payments ?? []).length === 0 ? (
              <p className="text-sm text-zinc-500">{t("receivables.noPayments")}</p>
            ) : (
              <ul className="divide-y divide-zinc-100 dark:divide-zinc-800">
                {(invoice.payments ?? []).map((payment) => (
                  <li key={payment.id} className="flex flex-wrap items-center justify-between gap-2 py-2">
                    <span className="flex flex-col">
                      <span className="text-sm font-medium tabular-nums text-zinc-900 dark:text-zinc-50">
                        {formatMoney(payment.amount, invoice.currency)}
                      </span>
                      <span className="text-xs text-zinc-500">
                        {formatDate(payment.payment_date)} · {t(`method.${payment.method}`)}
                        {payment.bank_transaction_id ? ` · ${t("payables.matchedTo")}` : ""}
                      </span>
                    </span>
                    <span className="flex gap-2">
                      <Button
                        variant="secondary"
                        className="h-8 px-3"
                        disabled={busy}
                        onClick={() => setEditingPayment(payment)}
                      >
                        {t("common.edit")}
                      </Button>
                      <Button
                        variant="ghost"
                        className="h-8 px-3 text-red-600"
                        disabled={busy}
                        onClick={() => void remove("payments", payment.id, "receivables.removePaymentConfirm")}
                      >
                        {t("receivables.removePayment")}
                      </Button>
                    </span>
                  </li>
                ))}
              </ul>
            )}
          </section>

          <section>
            <p className="mb-2 text-sm font-semibold text-zinc-900 dark:text-zinc-50">
              {t("receivables.deductionsTitle")}
            </p>
            {(invoice.deductions ?? []).length === 0 ? (
              <p className="text-sm text-zinc-500">{t("receivables.noDeductions")}</p>
            ) : (
              <ul className="divide-y divide-zinc-100 dark:divide-zinc-800">
                {(invoice.deductions ?? []).map((deduction) => (
                  <li key={deduction.id} className="flex flex-wrap items-center justify-between gap-2 py-2">
                    <span className="flex flex-col">
                      <span className="text-sm font-medium tabular-nums text-zinc-900 dark:text-zinc-50">
                        {formatMoney(deduction.amount, invoice.currency)}
                      </span>
                      <span className="text-xs text-zinc-500">
                        {formatDate(deduction.deduction_date)}
                        {deduction.reason ? ` · ${deduction.reason}` : ""}
                      </span>
                    </span>
                    <span className="flex gap-2">
                      <Button
                        variant="secondary"
                        className="h-8 px-3"
                        disabled={busy}
                        onClick={() => setEditingDeduction(deduction)}
                      >
                        {t("common.edit")}
                      </Button>
                      <Button
                        variant="ghost"
                        className="h-8 px-3 text-red-600"
                        disabled={busy}
                        onClick={() => void remove("deductions", deduction.id, "receivables.removeDeductionConfirm")}
                      >
                        {t("receivables.removePayment")}
                      </Button>
                    </span>
                  </li>
                ))}
              </ul>
            )}
          </section>

          {error && <p className="text-sm text-red-600">{error}</p>}

          <div className="flex flex-wrap justify-between gap-2">
            <div className="flex gap-2">
              <Button
                type="button"
                variant="secondary"
                disabled={invoice.remaining_amount <= 0}
                onClick={() => setAddingPayment(true)}
              >
                {t("receivables.recordPayment")}
              </Button>
              <Button
                type="button"
                variant="secondary"
                disabled={invoice.remaining_amount <= 0}
                onClick={() => setAddingDeduction(true)}
              >
                {t("receivables.recordDeduction")}
              </Button>
            </div>
            <Button type="button" onClick={onClose}>
              {t("common.close")}
            </Button>
          </div>

          {addingPayment && <SettleForm invoice={invoice} kind="payment" onClose={() => setAddingPayment(false)} />}
          {addingDeduction && (
            <SettleForm invoice={invoice} kind="deduction" onClose={() => setAddingDeduction(false)} />
          )}
          {editingPayment && (
            <SettleForm
              invoice={invoice}
              kind="payment"
              payment={editingPayment}
              onClose={() => setEditingPayment(null)}
            />
          )}
          {editingDeduction && (
            <SettleForm
              invoice={invoice}
              kind="deduction"
              deduction={editingDeduction}
              onClose={() => setEditingDeduction(null)}
            />
          )}
        </div>
      )}
    </Modal>
  );
}

function Figure({ label, value }: { label: string; value: string }) {
  return (
    <span className="flex flex-col">
      <span className="text-xs uppercase tracking-wider text-zinc-500">{label}</span>
      <span className="tabular-nums font-medium text-zinc-900 dark:text-zinc-50">{value}</span>
    </span>
  );
}

/** One form for both settlement kinds, creating or correcting. */
function SettleForm({
  invoice,
  kind,
  payment,
  deduction,
  onClose,
}: {
  invoice: ReceivableInvoice;
  kind: "payment" | "deduction";
  payment?: Payment;
  deduction?: Deduction;
  onClose: () => void;
}) {
  const { t } = useI18n();
  const isPayment = kind === "payment";
  const existing = payment ?? deduction;
  const editing = existing !== undefined;

  const [amount, setAmount] = useState(
    existing ? String(existing.amount) : String(invoice.remaining_amount),
  );
  const [date, setDate] = useState(
    (payment?.payment_date ?? deduction?.deduction_date) || todayISO(),
  );
  const [method, setMethod] = useState(payment?.method ?? "cash");
  const [reason, setReason] = useState(deduction?.reason ?? "");
  const [error, setError] = useState<string | null>(null);
  const [saving, setSaving] = useState(false);

  const title = editing
    ? isPayment
      ? t("receivables.editPayment")
      : t("receivables.editDeduction")
    : isPayment
      ? t("receivables.recordPayment")
      : t("receivables.recordDeduction");

  async function submit(e: React.FormEvent) {
    e.preventDefault();
    setSaving(true);
    setError(null);

    const segment = isPayment ? "payments" : "deductions";
    const body = isPayment
      ? { amount: Number(amount), payment_date: date, method }
      : { amount: Number(amount), deduction_date: date, reason: reason || null };

    try {
      await apiFetch(
        editing
          ? `/receivables/${invoice.id}/${segment}/${existing.id}`
          : `/receivables/${invoice.id}/${segment}`,
        { method: editing ? "PUT" : "POST", json: body },
      );
      onClose();
    } catch (err) {
      setError(errorMessage(err));
    } finally {
      setSaving(false);
    }
  }

  const label = "mb-1 block text-sm font-medium text-zinc-700 dark:text-zinc-300";

  return (
    <Modal open onClose={onClose} title={title}>
      <form onSubmit={submit} className="space-y-4">
        <div className="grid grid-cols-2 gap-4">
          <div>
            <label className={label}>{t("receivables.amount")}</label>
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
            <label className={label}>
              {isPayment ? t("payables.paymentDate") : t("receivables.deductionDate")}
            </label>
            <Input type="date" value={date} onChange={(e) => setDate(e.target.value)} required />
          </div>
        </div>

        {isPayment ? (
          <div>
            <label className={label}>{t("payables.method")}</label>
            <Select value={method} onChange={(e) => setMethod(e.target.value)}>
              {METHODS.map((m) => (
                <option key={m} value={m}>
                  {t(`method.${m}`)}
                </option>
              ))}
            </Select>
          </div>
        ) : (
          <div>
            <label className={label}>{t("receivables.reason")}</label>
            <Input value={reason} onChange={(e) => setReason(e.target.value)} />
          </div>
        )}

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

interface Statement {
  client: { name: string };
  totals: { invoiced: number; received: number; deducted: number; remaining: number };
  entries: StatementEntry[];
}

function StatementButton() {
  const { t } = useI18n();
  const [open, setOpen] = useState(false);
  const [clientId, setClientId] = useState<number | null>(null);

  const { data: statement, loading } = useResource<Statement>(
    clientId === null ? null : `/receivables/statement?client_id=${clientId}`,
  );

  const entryLabel = (type: string) =>
    type === "invoice"
      ? t("receivables.entryInvoice")
      : type === "payment"
        ? t("receivables.entryPayment")
        : t("receivables.entryDeduction");

  return (
    <>
      <Button variant="secondary" onClick={() => setOpen(true)}>
        {t("receivables.statement")}
      </Button>
      <Modal open={open} onClose={() => setOpen(false)} title={t("receivables.statement")}>
        <div className="space-y-4">
          <AsyncSelect
            resource="clients"
            value={clientId}
            onChange={setClientId}
            placeholder={t("receivables.selectClient")}
          />

          {loading && <p className="text-sm text-zinc-500">{t("common.loading")}</p>}

          {statement && (
            <>
              <div className="grid grid-cols-4 gap-2 text-center text-xs">
                {(["invoiced", "received", "deducted", "remaining"] as const).map((k) => (
                  <div key={k} className="rounded-md bg-zinc-100 p-2 dark:bg-zinc-800">
                    <div className="text-zinc-500">{t(`receivables.${k}`)}</div>
                    <div className="mt-1 font-semibold text-zinc-900 dark:text-zinc-50">
                      {formatMoney(statement.totals[k])}
                    </div>
                  </div>
                ))}
              </div>
              <div className="max-h-72 overflow-y-auto rounded-md border border-zinc-200 dark:border-zinc-800">
                <table className="w-full text-sm">
                  <thead className="sticky top-0 bg-zinc-50 text-left text-xs uppercase text-zinc-500 dark:bg-zinc-900">
                    <tr>
                      <th className="px-3 py-2">{t("receivables.date")}</th>
                      <th className="px-3 py-2">{t("receivables.type")}</th>
                      <th className="px-3 py-2 text-right">{t("receivables.debit")}</th>
                      <th className="px-3 py-2 text-right">{t("receivables.credit")}</th>
                      <th className="px-3 py-2 text-right">{t("receivables.balance")}</th>
                    </tr>
                  </thead>
                  <tbody className="divide-y divide-zinc-100 dark:divide-zinc-800">
                    {statement.entries.map((entry, i) => (
                      <tr key={i}>
                        <td className="px-3 py-2">{formatDate(entry.date)}</td>
                        <td className="px-3 py-2">{entryLabel(entry.type)}</td>
                        <td className="px-3 py-2 text-right tabular-nums">
                          {entry.debit ? formatMoney(entry.debit) : "—"}
                        </td>
                        <td className="px-3 py-2 text-right tabular-nums">
                          {entry.credit ? formatMoney(entry.credit) : "—"}
                        </td>
                        <td className="px-3 py-2 text-right font-medium tabular-nums">{formatMoney(entry.balance)}</td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
            </>
          )}
        </div>
      </Modal>
    </>
  );
}
