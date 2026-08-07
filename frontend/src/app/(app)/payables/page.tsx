"use client";

import { useState } from "react";
import { ApiError, apiFetch, errorMessage } from "@/lib/api";
import { useAuth } from "@/lib/auth/context";
import { usePage } from "@/lib/data/use-page";
import { useResource, withQuery } from "@/lib/data/use-resource";
import { useI18n } from "@/lib/i18n/context";
import { formatDate, formatMoney, todayISO } from "@/lib/format";
import { useDebouncedValue } from "@/lib/use-debounced-value";
import {
  BankRecordField,
  bankRecordPayload,
  canBook,
  type BankRecordMode,
} from "@/components/bank-record-field";
import { AsyncSelect } from "@/components/ui/async-select";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Card } from "@/components/ui/card";
import { Input } from "@/components/ui/input";
import { Modal } from "@/components/ui/modal";
import { Pagination, type PageMeta } from "@/components/ui/pagination";
import { PasswordConfirmModal } from "@/components/ui/password-confirm-modal";
import { Select } from "@/components/ui/select";

interface Payment {
  id: number;
  amount: number;
  payment_date: string | null;
  method: string;
  reference: string | null;
  bank_transaction_id: number | null;
}

interface PayableInvoice {
  id: number;
  supplier_id: number;
  supplier?: { id: number; name: string };
  invoice_number: string | null;
  invoice_date: string | null;
  due_date: string | null;
  description: string | null;
  expense_category: string | null;
  currency: string;
  original_amount: number;
  paid_amount: number;
  remaining_amount: number;
  status: string;
  is_overdue: boolean;
  notes: string | null;
  payments?: Payment[];
}

const METHODS = ["cash", "nlb", "lovcen", "other"] as const;
const PER_PAGE = 25;

function statusTone(status: string): "gray" | "amber" | "green" {
  if (status === "paid") return "green";
  if (status === "partial") return "amber";
  return "gray";
}

export default function PayablesPage() {
  const { t } = useI18n();
  const { hasPermission } = useAuth();

  const [status, setStatus] = useState("");
  const [search, setSearch] = useState("");
  const [overdue, setOverdue] = useState(false);

  // The request follows the settled term, not every keystroke.
  const debouncedSearch = useDebouncedValue(search);

  const [creating, setCreating] = useState(false);
  const [editing, setEditing] = useState<PayableInvoice | null>(null);
  const [settling, setSettling] = useState<PayableInvoice | null>(null);
  const [deleting, setDeleting] = useState<PayableInvoice | null>(null);

  // A new filter always starts at the first page: page 4 of the old filter is
  // rarely page 4 of the new one.
  const [page, setPage] = usePage([status, debouncedSearch, overdue]);

  const { data, loading, refreshing, error } = useResource<{ data: PayableInvoice[]; meta: PageMeta }>(
    withQuery("/payables", {
      status,
      search: debouncedSearch,
      overdue,
      page,
      per_page: PER_PAGE,
    }),
  );

  const invoices = data?.data ?? [];
  const canDelete = hasPermission("payables.approve");

  return (
    <div className="space-y-5">
      <div className="flex items-center justify-between">
        <h1 className="text-[2.5rem] font-light leading-none tracking-[-0.02em] text-zinc-900 dark:text-zinc-50">{t("payables.title")}</h1>
        <Button onClick={() => setCreating(true)}>{t("payables.new")}</Button>
      </div>

      <Card className="p-4">
        <div className="flex flex-wrap items-center gap-3">
          <Input
            className="max-w-xs"
            placeholder={t("payables.search")}
            value={search}
            onChange={(e) => setSearch(e.target.value)}
          />
          <Select className="max-w-[12rem]" value={status} onChange={(e) => setStatus(e.target.value)}>
            <option value="">{t("payables.allStatuses")}</option>
            <option value="unpaid">{t("status.unpaid")}</option>
            <option value="partial">{t("status.partial")}</option>
            <option value="paid">{t("status.paid")}</option>
          </Select>
          <label className="flex items-center gap-2 text-sm text-zinc-600 dark:text-zinc-300">
            <input type="checkbox" checked={overdue} onChange={(e) => setOverdue(e.target.checked)} />
            {t("payables.overdueOnly")}
          </label>
        </div>
      </Card>

      {error && <p className="text-sm text-red-600">{error}</p>}

      <Card className="table-quiet overflow-x-auto p-0">
        <table className="w-full min-w-[900px] text-sm">
          <thead className="border-b border-zinc-900/8 text-left text-[11px] uppercase tracking-[0.1em] text-zinc-500 dark:border-white/10">
            <tr>
              <th className="px-4 py-3">{t("payables.supplier")}</th>
              <th className="px-4 py-3">{t("payables.invoiceNumber")}</th>
              <th className="px-4 py-3">{t("payables.invoiceDate")}</th>
              <th className="px-4 py-3">{t("payables.dueDate")}</th>
              <th className="px-4 py-3 text-right">{t("payables.original")}</th>
              <th className="px-4 py-3 text-right">{t("payables.paid")}</th>
              <th className="px-4 py-3 text-right">{t("payables.remaining")}</th>
              <th className="px-4 py-3">{t("payables.status")}</th>
              <th className="px-4 py-3 text-right">{t("payables.actions")}</th>
            </tr>
          </thead>
          <tbody className={refreshing ? "divide-y divide-zinc-100 opacity-60 dark:divide-zinc-800" : "divide-y divide-zinc-900/5 dark:divide-white/8"}>
            {loading ? (
              <tr>
                <td colSpan={9} className="px-4 py-14 text-center text-sm text-zinc-500">
                  {t("common.loading")}
                </td>
              </tr>
            ) : invoices.length === 0 ? (
              <tr>
                <td colSpan={9} className="px-4 py-14 text-center text-sm text-zinc-500">
                  {t("payables.none")}
                </td>
              </tr>
            ) : (
              invoices.map((inv) => (
                <tr key={inv.id} className="text-zinc-800 dark:text-zinc-200">
                  <td className="px-4 py-3 font-medium">{inv.supplier?.name ?? "—"}</td>
                  <td className="px-4 py-3">{inv.invoice_number ?? "—"}</td>
                  <td className="px-4 py-3">{formatDate(inv.invoice_date)}</td>
                  <td className="px-4 py-3">
                    <span className="flex items-center gap-1">
                      {formatDate(inv.due_date)}
                      {inv.is_overdue && <Badge tone="red">{t("payables.overdue")}</Badge>}
                    </span>
                  </td>
                  <td className="px-4 py-3 text-right tabular-nums">{formatMoney(inv.original_amount, inv.currency)}</td>
                  <td className="px-4 py-3 text-right tabular-nums">{formatMoney(inv.paid_amount, inv.currency)}</td>
                  <td className="px-4 py-3 text-right font-medium tabular-nums">
                    {formatMoney(inv.remaining_amount, inv.currency)}
                  </td>
                  <td className="px-4 py-3">
                    <Badge tone={statusTone(inv.status)}>{t(`status.${inv.status}`)}</Badge>
                  </td>
                  <td className="px-4 py-3 text-right">
                    {/*
                      Available whatever the status: a paid invoice is still a
                      record that can have been entered wrong, and the fix is to
                      correct it rather than to book an offsetting entry.
                    */}
                    <div className="flex justify-end gap-2">
                      <Button variant="secondary" className="h-8 px-3" onClick={() => setEditing(inv)}>
                        {t("payables.edit")}
                      </Button>
                      <Button variant="secondary" className="h-8 px-3" onClick={() => setSettling(inv)}>
                        {t("payables.manage")}
                      </Button>
                      {/*
                        Removing the record entirely, behind a password. Hiding
                        the button from someone without payables.approve is a
                        courtesy — the route refuses them regardless.
                      */}
                      {canDelete && (
                        <Button
                          variant="ghost"
                          className="h-8 px-3 text-red-600"
                          onClick={() => setDeleting(inv)}
                        >
                          {t("common.delete")}
                        </Button>
                      )}
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
      {editing && (
        <InvoiceModal
          invoice={editing}
          canDelete={canDelete}
          onClose={() => setEditing(null)}
          onRequestDelete={(invoice) => {
            setEditing(null);
            setDeleting(invoice);
          }}
        />
      )}
      {settling && (
        <SettlementModal invoiceId={settling.id} canDelete={canDelete} onClose={() => setSettling(null)} />
      )}
      {deleting && (
        <PasswordConfirmModal
          title={t("payables.remove")}
          message={t("payables.removeConfirm")}
          onClose={() => setDeleting(null)}
          onConfirm={(password) =>
            apiFetch(`/payables/${deleting.id}`, {
              method: "DELETE",
              // The password rides on the DELETE itself, so the server verifies
              // it in the same request that removes the row.
              json: { current_password: password },
            })
          }
        />
      )}
    </div>
  );
}

/** Create or correct an invoice. The same form serves both. */
function InvoiceModal({
  invoice,
  canDelete,
  onClose,
  onRequestDelete,
}: {
  invoice?: PayableInvoice;
  canDelete?: boolean;
  onClose: () => void;
  /** Hands the invoice to the page's password gate — the form never deletes. */
  onRequestDelete?: (invoice: PayableInvoice) => void;
}) {
  const { t } = useI18n();
  const editing = invoice !== undefined;

  const [supplierId, setSupplierId] = useState<number | null>(invoice?.supplier_id ?? null);
  const [invoiceNumber, setInvoiceNumber] = useState(invoice?.invoice_number ?? "");
  const [invoiceDate, setInvoiceDate] = useState(invoice?.invoice_date ?? todayISO());
  const [dueDate, setDueDate] = useState(invoice?.due_date ?? "");
  const [category, setCategory] = useState(invoice?.expense_category ?? "");
  const [description, setDescription] = useState(invoice?.description ?? "");
  const [amount, setAmount] = useState(invoice ? String(invoice.original_amount) : "");
  const [notes, setNotes] = useState(invoice?.notes ?? "");
  const [error, setError] = useState<string | null>(null);
  const [saving, setSaving] = useState(false);

  const [newSupplier, setNewSupplier] = useState("");

  async function addSupplier() {
    if (!newSupplier.trim()) return;

    try {
      const res = await apiFetch<{ data: { id: number } }>("/suppliers", {
        method: "POST",
        json: { name: newSupplier.trim() },
      });
      setSupplierId(res.data.id);
      setNewSupplier("");
    } catch (err) {
      setError(err instanceof ApiError ? err.message : "Error");
    }
  }

  async function submit(e: React.FormEvent) {
    e.preventDefault();
    setSaving(true);
    setError(null);

    const body = {
      supplier_id: supplierId,
      invoice_number: invoiceNumber || null,
      invoice_date: invoiceDate,
      due_date: dueDate || null,
      expense_category: category || null,
      description: description || null,
      original_amount: Number(amount),
      notes: notes || null,
    };

    try {
      await apiFetch(editing ? `/payables/${invoice.id}` : "/payables", {
        method: editing ? "PUT" : "POST",
        json: body,
      });
      onClose();
    } catch (err) {
      setError(errorMessage(err));
    } finally {
      setSaving(false);
    }
  }

  const label = "mb-1.5 block px-4 text-[11px] font-medium uppercase tracking-[0.08em] text-zinc-500";

  return (
    <Modal open onClose={onClose} title={editing ? t("payables.editInvoice") : t("payables.new")}>
      <form onSubmit={submit} className="space-y-4">
        {editing && invoice.status === "paid" && (
          <p className="rounded-md bg-zinc-50 px-3 py-2 text-xs text-zinc-600 dark:bg-zinc-800 dark:text-zinc-300">
            {t("payables.correctable")}
          </p>
        )}

        <div>
          <label className={label}>{t("payables.supplier")}</label>
          <AsyncSelect
            resource="suppliers"
            value={supplierId}
            onChange={setSupplierId}
            params={{ active_only: true }}
            placeholder={t("payables.selectSupplier")}
            required
          />
          <div className="mt-2 flex gap-2">
            <Input
              placeholder={t("payables.supplierName")}
              value={newSupplier}
              onChange={(e) => setNewSupplier(e.target.value)}
            />
            <Button type="button" variant="secondary" onClick={() => void addSupplier()}>
              {t("payables.add")}
            </Button>
          </div>
        </div>

        <div className="grid grid-cols-2 gap-4">
          <div>
            <label className={label}>{t("payables.invoiceNumber")}</label>
            <Input value={invoiceNumber} onChange={(e) => setInvoiceNumber(e.target.value)} />
          </div>
          <div>
            <label className={label}>{t("payables.amount")}</label>
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
            <label className={label}>{t("payables.invoiceDate")}</label>
            <Input type="date" value={invoiceDate} onChange={(e) => setInvoiceDate(e.target.value)} required />
          </div>
          <div>
            <label className={label}>{t("payables.dueDate")}</label>
            <Input type="date" value={dueDate} onChange={(e) => setDueDate(e.target.value)} />
          </div>
        </div>

        <div>
          <label className={label}>{t("payables.category")}</label>
          <Input value={category} onChange={(e) => setCategory(e.target.value)} />
        </div>
        <div>
          <label className={label}>{t("payables.description")}</label>
          <Input value={description} onChange={(e) => setDescription(e.target.value)} />
        </div>
        <div>
          <label className={label}>{t("payables.notes")}</label>
          <Input value={notes} onChange={(e) => setNotes(e.target.value)} />
        </div>

        {error && <p className="text-sm text-red-600">{error}</p>}

        <div className="flex justify-between gap-2">
          {editing && canDelete && onRequestDelete ? (
            <Button type="button" variant="danger" disabled={saving} onClick={() => onRequestDelete(invoice)}>
              {t("payables.remove")}
            </Button>
          ) : (
            <span />
          )}
          <div className="flex gap-2">
            <Button type="button" variant="secondary" onClick={onClose}>
              {t("common.cancel")}
            </Button>
            <Button type="submit" disabled={saving || supplierId === null}>
              {saving ? t("common.saving") : editing ? t("common.save") : t("payables.create")}
            </Button>
          </div>
        </div>
      </form>
    </Modal>
  );
}

/**
 * The payments settling one invoice: add, correct, or remove.
 *
 * Correcting the line that was wrong is the whole point — a compensating
 * opposite entry would leave two rows that both look like real money moving.
 */
function SettlementModal({
  invoiceId,
  canDelete,
  onClose,
}: {
  invoiceId: number;
  canDelete: boolean;
  onClose: () => void;
}) {
  const { t } = useI18n();
  const { data, loading } = useResource<{ data: PayableInvoice }>(`/payables/${invoiceId}`);
  const invoice = data?.data;

  const [editingPayment, setEditingPayment] = useState<Payment | null>(null);
  const [removingPayment, setRemovingPayment] = useState<Payment | null>(null);
  const [adding, setAdding] = useState(false);

  return (
    <Modal open onClose={onClose} title={t("payables.paymentsTitle")}>
      {loading || !invoice ? (
        <p className="text-sm text-zinc-500">{t("common.loading")}</p>
      ) : (
        <div className="space-y-4">
          <div className="grid grid-cols-3 gap-3 rounded-md bg-zinc-50 p-3 text-sm dark:bg-zinc-800">
            <Figure label={t("payables.original")} value={formatMoney(invoice.original_amount, invoice.currency)} />
            <Figure label={t("payables.paid")} value={formatMoney(invoice.paid_amount, invoice.currency)} />
            <Figure label={t("payables.remaining")} value={formatMoney(invoice.remaining_amount, invoice.currency)} />
          </div>

          {(invoice.payments ?? []).length === 0 ? (
            <p className="text-sm text-zinc-500">{t("payables.noPayments")}</p>
          ) : (
            <ul className="divide-y divide-zinc-900/5 dark:divide-white/8">
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
                    <Button variant="secondary" className="h-8 px-3" onClick={() => setEditingPayment(payment)}>
                      {t("common.edit")}
                    </Button>
                    {canDelete && (
                      <Button
                        variant="ghost"
                        className="h-8 px-3 text-red-600"
                        onClick={() => setRemovingPayment(payment)}
                      >
                        {t("payables.removePayment")}
                      </Button>
                    )}
                  </span>
                </li>
              ))}
            </ul>
          )}

          <div className="flex justify-between gap-2">
            <Button
              type="button"
              variant="secondary"
              disabled={invoice.remaining_amount <= 0}
              onClick={() => setAdding(true)}
            >
              {t("payables.recordPayment")}
            </Button>
            <Button type="button" onClick={onClose}>
              {t("common.close")}
            </Button>
          </div>

          {adding && (
            <PaymentForm
              invoice={invoice}
              onClose={() => setAdding(false)}
            />
          )}
          {removingPayment && (
            <RemovePaymentModal
              invoiceId={invoiceId}
              payment={removingPayment}
              onClose={() => setRemovingPayment(null)}
            />
          )}
          {editingPayment && (
            <PaymentForm
              invoice={invoice}
              payment={editingPayment}
              onClose={() => setEditingPayment(null)}
            />
          )}
        </div>
      )}
    </Modal>
  );
}

/**
 * Undoing a settlement — the two sides of it come apart together.
 *
 * Removing the payment always does two things on the server, in one database
 * transaction: the invoice's status is recomputed from the lines that are left
 * (so the last payment leaving puts it back to unpaid), and the bank movement it
 * was matched to stops being matched.
 *
 * Whether that movement is also *deleted* is asked here rather than assumed. A
 * matched movement is usually a line copied from a real bank statement: the
 * money left the account whatever happens to the invoice, so deleting it by
 * default would put the app's balance out of step with the bank's. The box is
 * for the other case — a movement entered only to record this payment, which
 * means nothing once the payment is gone.
 */
function RemovePaymentModal({
  invoiceId,
  payment,
  onClose,
}: {
  invoiceId: number;
  payment: Payment;
  onClose: () => void;
}) {
  const { t } = useI18n();
  const [deleteMovement, setDeleteMovement] = useState(false);
  const matched = payment.bank_transaction_id !== null;

  return (
    <PasswordConfirmModal
      title={t("payables.removePayment")}
      message={matched ? t("payables.removePaymentMatchedConfirm") : t("payables.removePaymentConfirm")}
      onClose={onClose}
      onConfirm={(password) =>
        apiFetch(`/payables/${invoiceId}/payments/${payment.id}`, {
          method: "DELETE",
          json: {
            current_password: password,
            delete_bank_transaction: matched && deleteMovement,
          },
        })
      }
    >
      {matched && (
        <div>
          <label className="flex items-start gap-2 text-sm text-zinc-700 dark:text-zinc-200">
            <input
              type="checkbox"
              className="mt-1"
              checked={deleteMovement}
              onChange={(e) => setDeleteMovement(e.target.checked)}
            />
            {t("payables.alsoDeleteMovement")}
          </label>
          <p className="mt-1 text-xs text-zinc-500">{t("payables.alsoDeleteMovementHint")}</p>
        </div>
      )}
    </PasswordConfirmModal>
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

function PaymentForm({
  invoice,
  payment,
  onClose,
}: {
  invoice: PayableInvoice;
  payment?: Payment;
  onClose: () => void;
}) {
  const { t } = useI18n();
  const editing = payment !== undefined;

  const [amount, setAmount] = useState(
    payment ? String(payment.amount) : String(invoice.remaining_amount),
  );
  const [paymentDate, setPaymentDate] = useState(payment?.payment_date ?? todayISO());
  const [method, setMethod] = useState(payment?.method ?? "cash");
  const [reference, setReference] = useState(payment?.reference ?? "");
  const [error, setError] = useState<string | null>(null);
  const [saving, setSaving] = useState(false);

  /*
   * A payment only reaches the balances, the cashflow and the dashboard if a
   * bank movement records it — those figures are summed from that table alone.
   * So a new payment defaults to booking one, which is what the operator means
   * by choosing cash/NLB/Lovćen; an edit defaults to changing nothing.
   */
  const [bankMode, setBankMode] = useState<BankRecordMode>(
    editing ? "keep" : canBook(method) ? "book" : "none",
  );
  const [movementId, setMovementId] = useState<number | null>(payment?.bank_transaction_id ?? null);

  function changeMethod(next: string) {
    setMethod(next);

    // `other` names no account, so there is nothing to book into.
    if (!canBook(next) && bankMode === "book") setBankMode("none");
    if (canBook(next) && bankMode === "none" && !editing) setBankMode("book");
  }

  async function submit(e: React.FormEvent) {
    e.preventDefault();
    setSaving(true);
    setError(null);

    try {
      await apiFetch(
        editing ? `/payables/${invoice.id}/payments/${payment.id}` : `/payables/${invoice.id}/payments`,
        {
          method: editing ? "PUT" : "POST",
          json: {
            amount: Number(amount),
            payment_date: paymentDate,
            method,
            reference: reference || null,
            ...bankRecordPayload(bankMode, movementId),
          },
        },
      );
      onClose();
    } catch (err) {
      setError(errorMessage(err));
    } finally {
      setSaving(false);
    }
  }

  const label = "mb-1.5 block px-4 text-[11px] font-medium uppercase tracking-[0.08em] text-zinc-500";

  return (
    <Modal open onClose={onClose} title={editing ? t("payables.editPayment") : t("payables.recordPayment")}>
      <form onSubmit={submit} className="space-y-4">
        <div className="grid grid-cols-2 gap-4">
          <div>
            <label className={label}>{t("payables.amount")}</label>
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
            <label className={label}>{t("payables.paymentDate")}</label>
            <Input type="date" value={paymentDate} onChange={(e) => setPaymentDate(e.target.value)} required />
          </div>
        </div>
        <div>
          <label className={label}>{t("payables.method")}</label>
          <Select value={method} onChange={(e) => changeMethod(e.target.value)}>
            {METHODS.map((m) => (
              <option key={m} value={m}>
                {t(`method.${m}`)}
              </option>
            ))}
          </Select>
        </div>
        <BankRecordField
          mode={bankMode}
          onModeChange={setBankMode}
          movementId={movementId}
          onMovementChange={setMovementId}
          method={method}
          editing={editing}
        />
        <div>
          <label className={label}>{t("loans.reference")}</label>
          <Input value={reference} onChange={(e) => setReference(e.target.value)} />
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

