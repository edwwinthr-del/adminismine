"use client";

import { useState } from "react";
import { apiFetch, errorMessage } from "@/lib/api";
import { usePage } from "@/lib/data/use-page";
import { useResource, withQuery } from "@/lib/data/use-resource";
import { useI18n } from "@/lib/i18n/context";
import { formatDate, formatMoney, todayISO } from "@/lib/format";
import { useDebouncedValue } from "@/lib/use-debounced-value";
import { AsyncSelect, type LookupOption } from "@/components/ui/async-select";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Card } from "@/components/ui/card";
import { Input } from "@/components/ui/input";
import { Modal } from "@/components/ui/modal";
import { Pagination, type PageMeta } from "@/components/ui/pagination";
import { Select } from "@/components/ui/select";

interface BankTransaction {
  id: number;
  date: string | null;
  description_1: string | null;
  description_2: string | null;
  cash_amount: number;
  nlb_amount: number;
  lovcen_amount: number;
  net_amount: number;
  category: string | null;
  is_uncategorized: boolean;
  possible_duplicate: boolean;
  currency: string;
}

interface Balances {
  cash: number;
  nlb: number;
  lovcen: number;
  total: number;
}

const CATEGORIES = ["income", "expense", "transfer", "loan", "payroll", "housing", "travel", "other"] as const;

function amountClass(value: number): string {
  if (value > 0) return "text-green-600 dark:text-green-400";
  if (value < 0) return "text-red-600 dark:text-red-400";
  return "text-zinc-400";
}

const PER_PAGE = 50;

export default function BankPage() {
  const { t } = useI18n();
  const [category, setCategory] = useState("");
  const [uncategorized, setUncategorized] = useState(false);
  const [search, setSearch] = useState("");

  const debouncedSearch = useDebouncedValue(search);

  const [page, setPage] = usePage([category, uncategorized, debouncedSearch]);

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
        <h1 className="text-2xl font-semibold text-zinc-900 dark:text-zinc-50">{t("bank.title")}</h1>
        <NewTransactionButton />
      </div>

      <div className="grid grid-cols-2 gap-4 sm:grid-cols-4">
        {(["cash", "nlb", "lovcen", "total"] as const).map((k) => (
          <Card key={k}>
            <p className="text-sm text-zinc-500">{k === "total" ? t("bank.total") : t(`bank.${k}`)}</p>
            <p className={`mt-1 text-xl font-semibold ${amountClass(balances?.[k] ?? 0)}`}>
              {formatMoney(balances?.[k] ?? 0)}
            </p>
          </Card>
        ))}
      </div>

      <Card className="p-3">
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
          <label className="flex items-center gap-2 text-sm text-zinc-600 dark:text-zinc-300">
            <input type="checkbox" checked={uncategorized} onChange={(e) => setUncategorized(e.target.checked)} />
            {t("bank.uncategorizedOnly")}
          </label>
        </div>
      </Card>

      <Card className="overflow-x-auto p-0">
        <table className="w-full min-w-[920px] text-sm">
          <thead className="border-b border-zinc-200 text-left text-xs uppercase tracking-wider text-zinc-500 dark:border-zinc-800">
            <tr>
              <th className="px-4 py-3">{t("bank.date")}</th>
              <th className="px-4 py-3">{t("bank.description1")}</th>
              <th className="px-4 py-3 text-right">{t("bank.cash")}</th>
              <th className="px-4 py-3 text-right">{t("bank.nlb")}</th>
              <th className="px-4 py-3 text-right">{t("bank.lovcen")}</th>
              <th className="px-4 py-3 text-right">{t("bank.net")}</th>
              <th className="px-4 py-3">{t("bank.category")}</th>
              <th className="px-4 py-3 text-right">{t("bank.match")}</th>
            </tr>
          </thead>
          <tbody className="divide-y divide-zinc-100 dark:divide-zinc-800">
            {loading ? (
              <tr>
                <td colSpan={8} className="px-4 py-8 text-center text-zinc-500">
                  {t("common.loading")}
                </td>
              </tr>
            ) : rows.length === 0 ? (
              <tr>
                <td colSpan={8} className="px-4 py-8 text-center text-zinc-500">
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
                  <td className={`px-4 py-3 text-right tabular-nums ${amountClass(r.cash_amount)}`}>
                    {r.cash_amount ? formatMoney(r.cash_amount, r.currency) : "—"}
                  </td>
                  <td className={`px-4 py-3 text-right tabular-nums ${amountClass(r.nlb_amount)}`}>
                    {r.nlb_amount ? formatMoney(r.nlb_amount, r.currency) : "—"}
                  </td>
                  <td className={`px-4 py-3 text-right tabular-nums ${amountClass(r.lovcen_amount)}`}>
                    {r.lovcen_amount ? formatMoney(r.lovcen_amount, r.currency) : "—"}
                  </td>
                  <td className={`px-4 py-3 text-right font-medium tabular-nums ${amountClass(r.net_amount)}`}>
                    {formatMoney(r.net_amount, r.currency)}
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
                    <MatchButton transaction={r} />
                  </td>
                </tr>
              ))
            )}
          </tbody>
        </table>

        <Pagination meta={list.data?.meta} onPageChange={setPage} disabled={list.refreshing} />
      </Card>
    </div>
  );
}

function NewTransactionButton() {
  const { t } = useI18n();
  const [open, setOpen] = useState(false);
  const [date, setDate] = useState(todayISO());
  const [desc1, setDesc1] = useState("");
  const [desc2, setDesc2] = useState("");
  const [cash, setCash] = useState("");
  const [nlb, setNlb] = useState("");
  const [lovcen, setLovcen] = useState("");
  const [category, setCategory] = useState("expense");
  const [error, setError] = useState<string | null>(null);
  const [saving, setSaving] = useState(false);

  async function submit(e: React.FormEvent) {
    e.preventDefault();
    setSaving(true);
    setError(null);
    try {
      await apiFetch("/bank-transactions", {
        method: "POST",
        json: {
          date,
          description_1: desc1 || null,
          description_2: desc2 || null,
          cash_amount: cash ? Number(cash) : 0,
          nlb_amount: nlb ? Number(nlb) : 0,
          lovcen_amount: lovcen ? Number(lovcen) : 0,
          category,
        },
      });
      setOpen(false);
      setDesc1("");
      setDesc2("");
      setCash("");
      setNlb("");
      setLovcen("");
    } catch (err) {
      setError(errorMessage(err));
    } finally {
      setSaving(false);
    }
  }

  return (
    <>
      <Button onClick={() => setOpen(true)}>{t("bank.new")}</Button>
      <Modal open={open} onClose={() => setOpen(false)} title={t("bank.new")}>
        <form onSubmit={submit} className="space-y-4">
          <div className="grid grid-cols-2 gap-4">
            <div>
              <label className="mb-1 block text-sm font-medium text-zinc-700 dark:text-zinc-300">{t("bank.date")}</label>
              <Input type="date" value={date} onChange={(e) => setDate(e.target.value)} required />
            </div>
            <div>
              <label className="mb-1 block text-sm font-medium text-zinc-700 dark:text-zinc-300">
                {t("bank.category")}
              </label>
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
            <label className="mb-1 block text-sm font-medium text-zinc-700 dark:text-zinc-300">
              {t("bank.description1")}
            </label>
            <Input value={desc1} onChange={(e) => setDesc1(e.target.value)} />
          </div>
          <div>
            <label className="mb-1 block text-sm font-medium text-zinc-700 dark:text-zinc-300">
              {t("bank.description2")}
            </label>
            <Input value={desc2} onChange={(e) => setDesc2(e.target.value)} />
          </div>
          <div className="grid grid-cols-3 gap-3">
            <div>
              <label className="mb-1 block text-sm font-medium text-zinc-700 dark:text-zinc-300">{t("bank.cash")}</label>
              <Input type="number" step="0.01" value={cash} onChange={(e) => setCash(e.target.value)} placeholder="0" />
            </div>
            <div>
              <label className="mb-1 block text-sm font-medium text-zinc-700 dark:text-zinc-300">{t("bank.nlb")}</label>
              <Input type="number" step="0.01" value={nlb} onChange={(e) => setNlb(e.target.value)} placeholder="0" />
            </div>
            <div>
              <label className="mb-1 block text-sm font-medium text-zinc-700 dark:text-zinc-300">
                {t("bank.lovcen")}
              </label>
              <Input type="number" step="0.01" value={lovcen} onChange={(e) => setLovcen(e.target.value)} placeholder="0" />
            </div>
          </div>
          <p className="text-xs text-zinc-500">+ in, − out</p>
          {error && <p className="text-sm text-red-600">{error}</p>}
          <div className="flex justify-end gap-2">
            <Button type="button" variant="secondary" onClick={() => setOpen(false)}>
              {t("common.cancel")}
            </Button>
            <Button type="submit" disabled={saving}>
              {saving ? t("common.saving") : t("bank.create")}
            </Button>
          </div>
        </form>
      </Modal>
    </>
  );
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
            <label className="mb-1 block text-sm font-medium text-zinc-700 dark:text-zinc-300">
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
            <label className="mb-1 block text-sm font-medium text-zinc-700 dark:text-zinc-300">{t("bank.amount")}</label>
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
