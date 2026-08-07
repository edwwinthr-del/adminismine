"use client";

import { useState } from "react";
import { usePage } from "@/lib/data/use-page";
import { useResource, withQuery } from "@/lib/data/use-resource";
import { useWorksiteOptions } from "@/lib/data/use-options";
import { apiFetch, errorMessage } from "@/lib/api";
import { useI18n } from "@/lib/i18n/context";
import { formatDate, formatMoney } from "@/lib/format";
import { useDebouncedValue } from "@/lib/use-debounced-value";
import { AttachmentsModal, type FileAttachment } from "@/components/attachments-modal";
import { AsyncSelect, numeric } from "@/components/ui/async-select";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Card } from "@/components/ui/card";
import { Input } from "@/components/ui/input";
import { Modal } from "@/components/ui/modal";
import { Pagination, type PageMeta } from "@/components/ui/pagination";
import { Select } from "@/components/ui/select";

interface Machine {
  id: number;
  machine_type: string;
  brand: string | null;
  model: string | null;
  display_name: string;
  serial_number: string | null;
  purchase_date: string | null;
  supplier_id: number | null;
  seller_name: string | null;
  purchased_from: string | null;
  purchase_invoice_number: string | null;
  purchase_amount: number | null;
  currency: string;
  payable_invoice_id: number | null;
  bank_transaction_id: number | null;
  current_location: string | null;
  worksite_id: number | null;
  worksite?: { id: number; name: string } | null;
  status: string;
  attachment_count?: number;
  attachments?: FileAttachment[];
  notes: string | null;
}

interface Register {
  total: number;
  by_status: Record<string, number>;
  purchase_value: { currency: string; total: number }[];
  by_worksite: { worksite_id: number | null; name: string | null; count: number }[];
  by_type: { machine_type: string; count: number }[];
  unlinked_purchases: number;
}

const STATUSES = ["active", "maintenance", "inactive", "sold"] as const;

function statusTone(status: string): "green" | "amber" | "gray" | "indigo" {
  if (status === "active") return "green";
  if (status === "maintenance") return "amber";
  if (status === "sold") return "indigo";
  return "gray";
}

export default function MachinesPage() {
  const { t } = useI18n();
  const [error, setError] = useState<string | null>(null);

  const [search, setSearch] = useState("");
  const [status, setStatus] = useState("");
  const [worksiteId, setWorksiteId] = useState("");
  const [machineType, setMachineType] = useState("");

  const [creating, setCreating] = useState(false);
  const [editing, setEditing] = useState<Machine | null>(null);
  const [managingFiles, setManagingFiles] = useState<Machine | null>(null);

  const debouncedSearch = useDebouncedValue(search);

  const [page, setPage] = usePage([debouncedSearch, status, worksiteId, machineType]);

  // Two resources rather than one Promise.all: the register is a whole-fleet
  // summary that does not depend on the filters, so as its own cache key it is
  // fetched once instead of again on every keystroke and page change.
  const { data: list, loading, error: listError } = useResource<{
    data: Machine[];
    meta?: PageMeta;
  }>(
    withQuery("/machines", {
      per_page: 25,
      page,
      search: debouncedSearch,
      status,
      worksite_id: worksiteId,
      machine_type: machineType,
    }),
  );

  const { data: summary } = useResource<{ data: Register }>("/machines/register");

  const machines = list?.data ?? [];
  const meta = list?.meta ?? null;
  const register = summary?.data ?? null;

  // Only the filter dropdown needs a preloaded list, and worksites are a short
  // one. Everything the form points at is searched on demand instead.
  const { options: worksites } = useWorksiteOptions();

  // No manual refetch: apiFetch's markMutated() revalidates both resources above.
  async function remove(machine: Machine) {
    if (!window.confirm(t("machines.deleteConfirm", { name: machine.display_name }))) return;
    try {
      await apiFetch(`/machines/${machine.id}`, { method: "DELETE" });
    } catch (err) {
      setError(errorMessage(err));
    }
  }

  const invested = register?.purchase_value[0];

  return (
    <div className="space-y-5">
      <div className="flex items-center justify-between">
        <h1 className="text-[2.5rem] font-light leading-none tracking-[-0.02em] text-zinc-900 dark:text-zinc-50">{t("machines.title")}</h1>
        <Button onClick={() => setCreating(true)}>{t("machines.new")}</Button>
      </div>

      <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
        <Tile label={t("machines.total")} value={String(register?.total ?? 0)} />
        <Tile
          label={t("machineStatus.active")}
          value={String(register?.by_status.active ?? 0)}
          hint={t("machines.inMaintenanceCount", { count: register?.by_status.maintenance ?? 0 })}
        />
        <Tile
          label={t("machines.invested")}
          value={invested ? formatMoney(invested.total, invested.currency) : formatMoney(0)}
        />
        <Tile label={t("machines.unlinked")} value={String(register?.unlinked_purchases ?? 0)} />
      </div>

      <Card className="p-4">
        <div className="flex flex-wrap items-center gap-3">
          <Input
            className="max-w-xs"
            placeholder={t("machines.search")}
            value={search}
            onChange={(e) => setSearch(e.target.value)}
          />
          <Select className="max-w-[12rem]" value={status} onChange={(e) => setStatus(e.target.value)}>
            <option value="">{t("machines.allStatuses")}</option>
            {STATUSES.map((value) => (
              <option key={value} value={value}>
                {t(`machineStatus.${value}`)}
              </option>
            ))}
          </Select>
          <Select className="max-w-[13rem]" value={worksiteId} onChange={(e) => setWorksiteId(e.target.value)}>
            <option value="">{t("machines.allWorksites")}</option>
            {worksites.map((worksite) => (
              <option key={worksite.id} value={worksite.id}>
                {worksite.name}
              </option>
            ))}
          </Select>
          <Select className="max-w-[13rem]" value={machineType} onChange={(e) => setMachineType(e.target.value)}>
            <option value="">{t("machines.allTypes")}</option>
            {(register?.by_type ?? []).map((row) => (
              <option key={row.machine_type} value={row.machine_type}>
                {row.machine_type} ({row.count})
              </option>
            ))}
          </Select>
        </div>
      </Card>

      {(error ?? listError) && <p className="text-sm text-red-600">{error ?? listError}</p>}

      <Card className="table-quiet overflow-x-auto p-0">
        <table className="w-full min-w-[1020px] text-sm">
          <thead className="border-b border-zinc-900/8 text-left text-[11px] uppercase tracking-[0.1em] text-zinc-500 dark:border-white/10">
            <tr>
              <th className="px-4 py-3">{t("machines.machine")}</th>
              <th className="px-4 py-3">{t("machines.serial")}</th>
              <th className="px-4 py-3">{t("machines.purchase")}</th>
              <th className="px-4 py-3">{t("machines.purchasedFrom")}</th>
              <th className="px-4 py-3">{t("machines.location")}</th>
              <th className="px-4 py-3">{t("machines.status")}</th>
              <th className="px-4 py-3 text-right">{t("machines.files")}</th>
              <th className="px-4 py-3 text-right">{t("machines.actions")}</th>
            </tr>
          </thead>
          <tbody className="divide-y divide-zinc-900/5 dark:divide-white/8">
            {loading ? (
              <tr>
                <td colSpan={8} className="px-4 py-14 text-center text-sm text-zinc-500">
                  {t("common.loading")}
                </td>
              </tr>
            ) : machines.length === 0 ? (
              <tr>
                <td colSpan={8} className="px-4 py-14 text-center text-sm text-zinc-500">
                  {t("machines.none")}
                </td>
              </tr>
            ) : (
              machines.map((machine) => (
                <tr key={machine.id} className="text-zinc-800 dark:text-zinc-200">
                  <td className="px-4 py-3">
                    <span className="font-medium">{machine.display_name}</span>
                    <span className="ml-2 text-xs text-zinc-500">{machine.machine_type}</span>
                  </td>
                  <td className="px-4 py-3">{machine.serial_number ?? "â€”"}</td>
                  <td className="px-4 py-3">
                    <span className="flex flex-col">
                      <span className="tabular-nums">
                        {machine.purchase_amount === null
                          ? "â€”"
                          : formatMoney(machine.purchase_amount, machine.currency)}
                      </span>
                      <span className="text-xs text-zinc-500">
                        {formatDate(machine.purchase_date)}
                        {machine.purchase_invoice_number && ` Â· ${machine.purchase_invoice_number}`}
                      </span>
                    </span>
                  </td>
                  <td className="px-4 py-3">
                    <span className="flex flex-col">
                      {machine.purchased_from ?? "â€”"}
                      {(machine.payable_invoice_id || machine.bank_transaction_id) && (
                        <span className="mt-1 flex gap-1">
                          {machine.payable_invoice_id && <Badge tone="indigo">{t("machines.linkedInvoice")}</Badge>}
                          {machine.bank_transaction_id && <Badge tone="indigo">{t("machines.linkedPayment")}</Badge>}
                        </span>
                      )}
                    </span>
                  </td>
                  <td className="px-4 py-3">
                    <span className="flex flex-col">
                      {machine.worksite?.name ?? "â€”"}
                      {machine.current_location && (
                        <span className="text-xs text-zinc-500">{machine.current_location}</span>
                      )}
                    </span>
                  </td>
                  <td className="px-4 py-3">
                    <Badge tone={statusTone(machine.status)}>{t(`machineStatus.${machine.status}`)}</Badge>
                  </td>
                  <td className="px-4 py-3 text-right tabular-nums">{machine.attachment_count ?? 0}</td>
                  <td className="px-4 py-3 text-right">
                    <div className="flex justify-end gap-2">
                      <Button variant="secondary" className="h-8 px-3" onClick={() => setManagingFiles(machine)}>
                        {t("machines.files")}
                      </Button>
                      <Button variant="secondary" className="h-8 px-3" onClick={() => setEditing(machine)}>
                        {t("machines.edit")}
                      </Button>
                      <Button variant="secondary" className="h-8 px-3" onClick={() => void remove(machine)}>
                        {t("machines.delete")}
                      </Button>
                    </div>
                  </td>
                </tr>
              ))
            )}
          </tbody>
        </table>

        <Pagination meta={meta} onPageChange={setPage} disabled={loading} />
      </Card>

      {(creating || editing) && (
        <MachineModal
          machine={editing ?? undefined}
          onClose={() => {
            setCreating(false);
            setEditing(null);
          }}
        />
      )}
      {managingFiles && (
        <AttachmentsModal
          title={`${t("machines.files")} â€” ${managingFiles.display_name}`}
          basePath={`/machines/${managingFiles.id}/attachments`}
          defaultKind="invoice"
          onClose={() => setManagingFiles(null)}
        />
      )}
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

function MachineModal({
  machine,
  onClose,
}: {
  machine?: Machine;
  onClose: () => void;
}) {
  const { t } = useI18n();
  const [form, setForm] = useState({
    machine_type: machine?.machine_type ?? "",
    brand: machine?.brand ?? "",
    model: machine?.model ?? "",
    serial_number: machine?.serial_number ?? "",
    purchase_date: machine?.purchase_date ?? "",
    supplier_id: machine?.supplier_id ? String(machine.supplier_id) : "",
    seller_name: machine?.seller_name ?? "",
    purchase_invoice_number: machine?.purchase_invoice_number ?? "",
    purchase_amount: machine?.purchase_amount == null ? "" : String(machine.purchase_amount),
    currency: machine?.currency ?? "EUR",
    payable_invoice_id: machine?.payable_invoice_id ? String(machine.payable_invoice_id) : "",
    bank_transaction_id: machine?.bank_transaction_id ? String(machine.bank_transaction_id) : "",
    current_location: machine?.current_location ?? "",
    worksite_id: machine?.worksite_id ? String(machine.worksite_id) : "",
    status: machine?.status ?? "active",
    notes: machine?.notes ?? "",
  });
  const [error, setError] = useState<string | null>(null);
  const [saving, setSaving] = useState(false);

  function set(key: keyof typeof form, value: string) {
    setForm((prev) => ({ ...prev, [key]: value }));
  }

  async function submit(e: React.FormEvent) {
    e.preventDefault();
    setSaving(true);
    setError(null);
    try {
      await apiFetch(machine ? `/machines/${machine.id}` : "/machines", {
        method: machine ? "PUT" : "POST",
        json: {
          machine_type: form.machine_type.trim(),
          brand: form.brand.trim() || null,
          model: form.model.trim() || null,
          serial_number: form.serial_number.trim() || null,
          purchase_date: form.purchase_date || null,
          supplier_id: form.supplier_id ? Number(form.supplier_id) : null,
          seller_name: form.seller_name.trim() || null,
          purchase_invoice_number: form.purchase_invoice_number.trim() || null,
          purchase_amount: form.purchase_amount === "" ? null : Number(form.purchase_amount),
          currency: form.currency,
          payable_invoice_id: form.payable_invoice_id ? Number(form.payable_invoice_id) : null,
          bank_transaction_id: form.bank_transaction_id ? Number(form.bank_transaction_id) : null,
          current_location: form.current_location.trim() || null,
          worksite_id: form.worksite_id ? Number(form.worksite_id) : null,
          status: form.status,
          notes: form.notes.trim() || null,
        },
      });
      onClose();
    } catch (err) {
      setError(errorMessage(err));
    } finally {
      setSaving(false);
    }
  }

  const label = "mb-1.5 block px-4 text-[11px] font-medium uppercase tracking-[0.08em] text-zinc-500";
  const section = "text-xs font-semibold uppercase tracking-wider text-zinc-500";

  return (
    <Modal open onClose={onClose} title={machine ? t("machines.edit") : t("machines.new")}>
      <form onSubmit={submit} className="space-y-5">
        <p className={section}>{t("machines.identity")}</p>
        <div className="grid grid-cols-2 gap-4">
          <div>
            <label className={label}>{t("machines.type")}</label>
            <Input value={form.machine_type} onChange={(e) => set("machine_type", e.target.value)} required />
          </div>
          <div>
            <label className={label}>{t("machines.brand")}</label>
            <Input value={form.brand} onChange={(e) => set("brand", e.target.value)} />
          </div>
          <div>
            <label className={label}>{t("machines.model")}</label>
            <Input value={form.model} onChange={(e) => set("model", e.target.value)} />
          </div>
          <div>
            <label className={label}>{t("machines.serial")}</label>
            <Input value={form.serial_number} onChange={(e) => set("serial_number", e.target.value)} />
          </div>
        </div>

        <p className={section}>{t("machines.purchase")}</p>
        <div className="grid grid-cols-2 gap-4">
          <div>
            <label className={label}>{t("machines.purchaseDate")}</label>
            <Input type="date" value={form.purchase_date} onChange={(e) => set("purchase_date", e.target.value)} />
          </div>
          <div>
            <label className={label}>{t("machines.amount")}</label>
            <Input
              type="number"
              step="0.01"
              min="0"
              value={form.purchase_amount}
              onChange={(e) => set("purchase_amount", e.target.value)}
            />
          </div>
          <div>
            <label className={label}>{t("machines.currency")}</label>
            <Select value={form.currency} onChange={(e) => set("currency", e.target.value)}>
              <option value="EUR">EUR</option>
              <option value="TRY">TRY</option>
            </Select>
          </div>
          <div>
            <label className={label}>{t("machines.invoiceNumber")}</label>
            <Input
              value={form.purchase_invoice_number}
              onChange={(e) => set("purchase_invoice_number", e.target.value)}
            />
          </div>
          <div>
            <label className={label}>{t("machines.supplier")}</label>
            <AsyncSelect
              resource="suppliers"
              value={numeric(form.supplier_id)}
              onChange={(value) => set("supplier_id", value === null ? "" : String(value))}
              params={{ active_only: true }}
              emptyLabel={t("machines.noSupplier")}
            />
          </div>
          <div>
            <label className={label}>{t("machines.seller")}</label>
            <Input value={form.seller_name} onChange={(e) => set("seller_name", e.target.value)} />
          </div>
        </div>

        <p className={section}>{t("machines.paperTrail")}</p>
        <div className="grid grid-cols-1 gap-4">
          <div>
            <label className={label}>{t("machines.payableInvoice")}</label>
            {/*
              Searched on the server: the paper trail has to stay linkable to any
              invoice ever entered, not just the hundred most recent ones.
            */}
            <AsyncSelect
              resource="payable-invoices"
              value={numeric(form.payable_invoice_id)}
              onChange={(value) => set("payable_invoice_id", value === null ? "" : String(value))}
              placeholder={t("machines.searchInvoice")}
              emptyLabel={t("machines.notLinked")}
            />
          </div>
          <div>
            <label className={label}>{t("machines.bankTransaction")}</label>
            <AsyncSelect
              resource="bank-transactions"
              value={numeric(form.bank_transaction_id)}
              onChange={(value) => set("bank_transaction_id", value === null ? "" : String(value))}
              placeholder={t("machines.searchTransaction")}
              emptyLabel={t("machines.notLinked")}
            />
          </div>
        </div>

        <p className={section}>{t("machines.deployment")}</p>
        <div className="grid grid-cols-2 gap-4">
          <div>
            <label className={label}>{t("machines.worksite")}</label>
            <AsyncSelect
              resource="worksites"
              value={numeric(form.worksite_id)}
              onChange={(value) => set("worksite_id", value === null ? "" : String(value))}
              params={{ active_only: true }}
              emptyLabel={t("machines.noWorksite")}
            />
          </div>
          <div>
            <label className={label}>{t("machines.location")}</label>
            <Input value={form.current_location} onChange={(e) => set("current_location", e.target.value)} />
          </div>
          <div>
            <label className={label}>{t("machines.status")}</label>
            <Select value={form.status} onChange={(e) => set("status", e.target.value)}>
              {STATUSES.map((value) => (
                <option key={value} value={value}>
                  {t(`machineStatus.${value}`)}
                </option>
              ))}
            </Select>
          </div>
          <div>
            <label className={label}>{t("machines.notes")}</label>
            <Input value={form.notes} onChange={(e) => set("notes", e.target.value)} />
          </div>
        </div>

        {error && <p className="text-sm text-red-600">{error}</p>}
        <div className="flex justify-end gap-2">
          <Button type="button" variant="secondary" onClick={onClose}>
            {t("common.cancel")}
          </Button>
          <Button type="submit" disabled={saving || form.machine_type.trim() === ""}>
            {saving ? t("common.saving") : t("common.save")}
          </Button>
        </div>
      </form>
    </Modal>
  );
}
