"use client";

import { useState } from "react";
import { apiFetch, errorMessage } from "@/lib/api";
import { usePage } from "@/lib/data/use-page";
import { useResource, withQuery } from "@/lib/data/use-resource";
import { useI18n } from "@/lib/i18n/context";
import { amountTone, formatDate, formatMoney, formatSignedMoney, todayISO } from "@/lib/format";
import { useDebouncedValue } from "@/lib/use-debounced-value";
import { AsyncSelect, type LookupOption } from "@/components/ui/async-select";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Card } from "@/components/ui/card";
import { Input } from "@/components/ui/input";
import { Modal } from "@/components/ui/modal";
import { Pagination, type PageMeta } from "@/components/ui/pagination";
import { PasswordConfirmModal } from "@/components/ui/password-confirm-modal";
import { Select } from "@/components/ui/select";
import { Checkbox } from "@/components/ui/checkbox";
interface BankTransaction {
  id: number;
  date: string | null;
  description_1: string | null;
  description_2: string | null;
  cash_amount: number;
  nlb_amount: number;
  lovcen_amount: number;
  net_amount: number;
  /** Balance of all three accounts as of this row, in ledger order. */
  running_balance?: number;
  category: string | null;
  is_uncategorized: boolean;
  possible_duplicate: boolean;
  currency: string;
  notes: string | null;
}

interface Balances {
  cash: number;
  nlb: number;
  lovcen: number;
  total: number;
}

const CATEGORIES = ["income", "expense", "transfer", "loan", "payroll", "housing", "travel", "other"] as const;

/**
 * The categories that fix a direction — the API forces the sign to match, so the
 * form says so rather than letting someone type a number that will be flipped
 * under them.
 */
const DIRECTIONAL: Record<string, "in" | "out"> = { income: "in", expense: "out" };

const PER_PAGE = 50;

export default function BankPage() {
  const { t } = useI18n();
  const [category, setCategory] = useState("");
  const [uncategorized, setUncategorized] = useState(false);
  const [search, setSearch] = useState("");

  const debouncedSearch = useDebouncedValue(search);

  const [page, setPage] = usePage([category, uncategorized, debouncedSearch]);

  const [creating, setCreating] = useState(false);
  const [editing, setEditing] = useState<BankTransaction | null>(null);
  const [deleting, setDeleting] = useState<BankTransaction | null>(null);

  const list = useResource<{ data: BankTransaction[]; meta: PageMeta }>(
    withQuery("/bank-transactions", {
      category,
      uncategorized,
      search: debouncedSearch,
      page,
      per_page: PER_PAGE,
    }),
  );
  // A separate resource, so the balances are not refetched on every filter
  // change — only when something is actually written.
  const { data: balanceData } = useResource<{ data: Balances }>("/bank-transactions/balances");

  const rows = list.data?.data ?? [];
  const balances = balanceData?.data ?? null;
  const loading = list.loading;

  return (
    <div className="space-y-5">
      <div className="flex items-center justify-between">
        <h1 className="text-[2.5rem] font-light leading-none tracking-[-0.02em] text-zinc-900 dark:text-zinc-50">{t("bank.title")}</h1>
        <Button onClick={() => setCreating(true)}>{t("bank.new")}</Button>
      </div>

      <div className="grid grid-cols-2 gap-4 sm:grid-cols-4">
        {(["cash", "nlb", "lovcen", "total"] as const).map((k) => (
          <Card key={k}>
            <p className="text-sm text-zinc-500">{k === "total" ? t("bank.total") : t(`bank.${k}`)}</p>
            {/*
              A balance is a signed figure: money out has already been subtracted
              from it, so it is shown and coloured by the same rule as any other
              amount (lib/format).
            */}
            <p className={`mt-1 text-xl font-semibold ${amountTone(balances?.[k] ?? 0)}`}>
              {formatMoney(balances?.[k] ?? 0)}
            </p>
          </Card>
        ))}
      </div>

      <Card className="p-4">
        <div className="flex flex-wrap items-center gap-3">
          <Input
            className="max-w-xs"
            placeholder={t("bank.search")}
            value={search}
            onChange={(e) => setSearch(e.target.value)}
          />
          <Select className="max-w-[12rem]" value={category} onChange={(e) => setCategory(e.target.value)}>
            <option value="">{t("bank.allCategories")}</option>
            {CATEGORIES.map((c) => (
              <option key={c} value={c}>
                {t(`category.${c}`)}
              </option>
            ))}
          </Select>
          <label className="flex cursor-pointer items-center gap-2 text-sm text-zinc-600 dark:text-zinc-300">
            <Checkbox size="sm" checked={uncategorized} onChange={(e) => setUncategorized(e.target.checked)}/>
            <span>{t("bank.uncategorizedOnly")}</span>
          </label>
        </div>
      </Card>

      <Card className="table-quiet scroll-quiet overflow-x-auto p-0">
        <table className="w-full min-w-[1080px] text-sm">
          <thead className="border-b border-zinc-900/8 text-left text-[11px] uppercase tracking-[0.1em] text-zinc-500 dark:border-white/10">
            <tr>
              <th className="px-4 py-3">{t("bank.date")}</th>
              <th className="px-4 py-3">{t("bank.description1")}</th>
              <th className="px-4 py-3 text-right">{t("bank.cash")}</th>
              <th className="px-4 py-3 text-right">{t("bank.nlb")}</th>
              <th className="px-4 py-3 text-right">{t("bank.lovcen")}</th>
              <th className="px-4 py-3 text-right">{t("bank.net")}</th>
              <th className="px-4 py-3 text-right">{t("bank.runningBalance")}</th>
              <th className="px-4 py-3">{t("bank.category")}</th>
              <th className="px-4 py-3 text-right">{t("common.actions")}</th>
            </tr>
          </thead>
          <tbody className="divide-y divide-zinc-900/5 dark:divide-white/8">
            {loading ? (
              <tr>
                <td colSpan={9} className="px-4 py-14 text-center text-sm text-zinc-500">
                  {t("common.loading")}
                </td>
              </tr>
            ) : rows.length === 0 ? (
              <tr>
                <td colSpan={9} className="px-4 py-14 text-center text-sm text-zinc-500">
                  {t("bank.none")}
                </td>
              </tr>
            ) : (
              rows.map((r) => (
                <tr key={r.id} className="text-zinc-800 dark:text-zinc-200">
                  <td className="px-4 py-3 whitespace-nowrap">{formatDate(r.date)}</td>
                  <td className="px-4 py-3">
                    <div className="flex items-center gap-2">
                      <span>{r.description_1 ?? "—"}</span>
                      {r.possible_duplicate && <Badge tone="amber">{t("bank.duplicate")}</Badge>}
                    </div>
                  </td>
                  <Amount value={r.cash_amount} currency={r.currency} />
                  <Amount value={r.nlb_amount} currency={r.currency} />
                  <Amount value={r.lovcen_amount} currency={r.currency} />
                  <Amount value={r.net_amount} currency={r.currency} emphasis alwaysShow />
                  {/*
                    Not signed: this is where the account stands, not a movement.
                    Coloured only when it has gone negative, which is the one
                    thing about a balance that needs to be noticed.
                  */}
                  <td
                    className={`px-4 py-3 text-right tabular-nums ${
                      (r.running_balance ?? 0) < 0 ? "text-red-600 dark:text-red-400" : "text-zinc-500"
                    }`}
                  >
                    {r.running_balance === undefined ? "—" : formatMoney(r.running_balance, r.currency)}
                  </td>
                  <td className="px-4 py-3">
                    {r.category ? (
                      <Badge tone={r.category === "income" ? "green" : r.category === "expense" ? "red" : "indigo"}>
                        {t(`category.${r.category}`)}
                      </Badge>
                    ) : (
                      <Badge tone="amber">{t("bank.uncategorized")}</Badge>
                    )}
                  </td>
                  <td className="px-4 py-3 text-right">
                    <div className="flex justify-end gap-2">
                      <MatchButton transaction={r} />
                      <Button variant="secondary" className="h-8 px-3" onClick={() => setEditing(r)}>
                        {t("common.edit")}
                      </Button>
                      <Button
                        variant="ghost"
                        className="h-8 px-3 text-red-600"
                        onClick={() => setDeleting(r)}
                      >
                        {t("common.delete")}
                      </Button>
                    </div>
                  </td>
                </tr>
              ))
            )}
          </tbody>
        </table>

        <Pagination meta={list.data?.meta} onPageChange={setPage} disabled={list.refreshing} />
      </Card>

      {creating && <TransactionModal onClose={() => setCreating(false)} />}
      {editing && <TransactionModal transaction={editing} onClose={() => setEditing(null)} />}
      {deleting && (
        <PasswordConfirmModal
          title={t("bank.remove")}
          message={t("bank.removeConfirm", {
            amount: formatSignedMoney(deleting.net_amount, deleting.currency),
            date: formatDate(deleting.date),
          })}
          onClose={() => setDeleting(null)}
          onConfirm={(password) =>
            apiFetch(`/bank-transactions/${deleting.id}`, {
              method: "DELETE",
              json: { current_password: password },
            })
          }
        />
      )}
    </div>
  );
}

/** One money cell: sign and colour from the shared helper, never per-table. */
function Amount({
  value,
  currency,
  emphasis,
  alwaysShow,
}: {
  value: number;
  currency: string;
  emphasis?: boolean;
  alwaysShow?: boolean;
}) {
  return (
    <td
      className={`px-4 py-3 text-right tabular-nums ${emphasis ? "font-medium" : ""} ${amountTone(value)}`}
    >
      {value || alwaysShow ? formatSignedMoney(value, currency) : "—"}
    </td>
  );
}

/** Record a movement, or correct one. The same form serves both. */
function TransactionModal({
  transaction,
  onClose,
}: {
  transaction?: BankTransaction;
  onClose: () => void;
}) {
  const { t } = useI18n();
  const editing = transaction !== undefined;

  const [date, setDate] = useState(transaction?.date ?? todayISO());
  const [desc1, setDesc1] = useState(transaction?.description_1 ?? "");
  const [desc2, setDesc2] = useState(transaction?.description_2 ?? "");
  // Amounts open exactly as stored, signs and all. For income and expense the
  // server settles the sign anyway, but for a transfer the sign *is* the
  // movement — showing a magnitude there would turn "-500 out of cash" into
  // "+500 into cash" the moment the row was saved again.
  const [cash, setCash] = useState(amountField(transaction?.cash_amount));
  const [nlb, setNlb] = useState(amountField(transaction?.nlb_amount));
  const [lovcen, setLovcen] = useState(amountField(transaction?.lovcen_amount));
  const [category, setCategory] = useState(transaction?.category ?? "expense");
  const [notes, setNotes] = useState(transaction?.notes ?? "");
  const [error, setError] = useState<string | null>(null);
  const [saving, setSaving] = useState(false);

  const direction = DIRECTIONAL[category];

  async function submit(e: React.FormEvent) {
    e.preventDefault();
    setSaving(true);
    setError(null);

    const body = {
      date,
      description_1: desc1 || null,
      description_2: desc2 || null,
      cash_amount: cash ? Number(cash) : 0,
      nlb_amount: nlb ? Number(nlb) : 0,
      lovcen_amount: lovcen ? Number(lovcen) : 0,
      category,
      notes: notes || null,
    };

    try {
      await apiFetch(editing ? `/bank-transactions/${transaction.id}` : "/bank-transactions", {
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
    <Modal open onClose={onClose} title={editing ? t("bank.edit") : t("bank.new")}>
      <form onSubmit={submit} className="space-y-4">
        <div className="grid grid-cols-2 gap-4">
          <div>
            <label className={label}>{t("bank.date")}</label>
            <Input type="date" value={date} onChange={(e) => setDate(e.target.value)} required />
          </div>
          <div>
            <label className={label}>{t("bank.category")}</label>
            <Select value={category} onChange={(e) => setCategory(e.target.value)}>
              {CATEGORIES.map((c) => (
                <option key={c} value={c}>
                  {t(`category.${c}`)}
                </option>
              ))}
            </Select>
          </div>
        </div>
        <div>
          <label className={label}>{t("bank.description1")}</label>
          <Input value={desc1} onChange={(e) => setDesc1(e.target.value)} />
        </div>
        <div>
          <label className={label}>{t("bank.description2")}</label>
          <Input value={desc2} onChange={(e) => setDesc2(e.target.value)} />
        </div>
        <div className="grid grid-cols-3 gap-3">
          <div>
            <label className={label}>{t("bank.cash")}</label>
            <Input type="number" step="0.01" value={cash} onChange={(e) => setCash(e.target.value)} placeholder="0" />
          </div>
          <div>
            <label className={label}>{t("bank.nlb")}</label>
            <Input type="number" step="0.01" value={nlb} onChange={(e) => setNlb(e.target.value)} placeholder="0" />
          </div>
          <div>
            <label className={label}>{t("bank.lovcen")}</label>
            <Input type="number" step="0.01" value={lovcen} onChange={(e) => setLovcen(e.target.value)} placeholder="0" />
          </div>
        </div>
        {/*
          Says which way the money will go before it is saved. For income and
          expense the server settles the sign, so the figure typed here is a
          magnitude; for everything else (a transfer is negative on one account
          and positive on another) the sign entered is the sign stored.
        */}
        <p className="text-xs text-zinc-500">
          {direction === "in"
            ? t("bank.signIncomeHint")
            : direction === "out"
              ? t("bank.signExpenseHint")
              : t("bank.signFreeHint")}
        </p>
        <div>
          <label className={label}>{t("bank.notes")}</label>
          <Input value={notes} onChange={(e) => setNotes(e.target.value)} />
        </div>

        {error && <p className="text-sm text-red-600">{error}</p>}

        <div className="flex justify-end gap-2">
          <Button type="button" variant="secondary" onClick={onClose}>
            {t("common.cancel")}
          </Button>
          <Button type="submit" disabled={saving}>
            {saving ? t("common.saving") : editing ? t("common.save") : t("bank.create")}
          </Button>
        </div>
      </form>
    </Modal>
  );
}

/** An amount as a form value: blank for an untouched account, as stored otherwise. */
function amountField(value: number | undefined): string {
  if (value === undefined || value === 0) return "";

  return String(value);
}

function MatchButton({ transaction }: { transaction: BankTransaction }) {
  const { t } = useI18n();
  const [open, setOpen] = useState(false);
  const [target, setTarget] = useState<"payable" | "receivable">("payable");
  const [invoiceId, setInvoiceId] = useState<number | null>(null);
  const [remaining, setRemaining] = useState<number | null>(null);
  const [amount, setAmount] = useState(String(Math.abs(transaction.net_amount)));
  const [error, setError] = useState<string | null>(null);
  const [saving, setSaving] = useState(false);

  // Switching side invalidates the chosen invoice.
  function switchTarget(next: "payable" | "receivable") {
    setTarget(next);
    setInvoiceId(null);
    setRemaining(null);
  }

  function chooseInvoice(id: number | null, option: LookupOption | null) {
    setInvoiceId(id);
    const outstanding = option?.meta?.remaining;
    setRemaining(typeof outstanding === "number" ? outstanding : null);
  }

  async function submit(e: React.FormEvent) {
    e.preventDefault();
    setSaving(true);
    setError(null);
    try {
      await apiFetch(`/bank-transactions/${transaction.id}/match`, {
        method: "POST",
        json: { target, invoice_id: invoiceId, amount: Number(amount) },
      });
      setOpen(false);
    } catch (err) {
      setError(errorMessage(err));
    } finally {
      setSaving(false);
    }
  }

  return (
    <>
      <Button variant="secondary" className="h-8 px-3" onClick={() => setOpen(true)}>
        {t("bank.match")}
      </Button>
      <Modal open={open} onClose={() => setOpen(false)} title={t("bank.matchTo")}>
        <form onSubmit={submit} className="space-y-4">
          <Select value={target} onChange={(e) => switchTarget(e.target.value as "payable" | "receivable")}>
            <option value="payable">{t("bank.payable")}</option>
            <option value="receivable">{t("bank.receivable")}</option>
          </Select>
          <div>
            <label className="mb-1.5 block px-4 text-[11px] font-medium uppercase tracking-[0.08em] text-zinc-500">
              {t("bank.selectInvoice")}
            </label>
            {/*
              Only invoices with something still owing, searched on the server —
              there is no point downloading a hundred paid ones to filter them
              out in the browser.
            */}
            <AsyncSelect
              key={target}
              resource={target === "payable" ? "payable-invoices" : "receivable-invoices"}
              value={invoiceId}
              onChange={chooseInvoice}
              params={{ outstanding: true }}
              placeholder={t("bank.selectInvoice")}
              required
            />
            {remaining !== null && (
              <p className="mt-1 text-xs text-zinc-500">
                {t("payables.remaining")}: {formatMoney(remaining)}
              </p>
            )}
          </div>
          <div>
            <label className="mb-1.5 block px-4 text-[11px] font-medium uppercase tracking-[0.08em] text-zinc-500">{t("bank.amount")}</label>
            <Input type="number" step="0.01" min="0" value={amount} onChange={(e) => setAmount(e.target.value)} required />
          </div>
          {error && <p className="text-sm text-red-600">{error}</p>}
          <div className="flex justify-end gap-2">
            <Button type="button" variant="secondary" onClick={() => setOpen(false)}>
              {t("common.cancel")}
            </Button>
            <Button type="submit" disabled={saving || invoiceId === null}>
              {saving ? t("common.saving") : t("bank.match")}
            </Button>
          </div>
        </form>
      </Modal>
    </>
  );
}
