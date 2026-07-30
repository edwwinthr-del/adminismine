"use client";

import { useCallback, useEffect, useState } from "react";
import { usePage } from "@/lib/data/use-page";
import { apiFetch, errorMessage } from "@/lib/api";
import { useI18n } from "@/lib/i18n/context";
import { formatDate } from "@/lib/format";
import { useDebouncedValue } from "@/lib/use-debounced-value";
import { AttachmentsModal } from "@/components/attachments-modal";
import { AsyncSelect, numeric, type LookupOption } from "@/components/ui/async-select";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Card } from "@/components/ui/card";
import { Input } from "@/components/ui/input";
import { Modal } from "@/components/ui/modal";
import { Pagination, type PageMeta } from "@/components/ui/pagination";
import { Select } from "@/components/ui/select";

interface CustomsDocument {
  id: number;
  document_type: string;
  document_number: string | null;
  cmr_number: string | null;
  issue_date: string | null;
  cmr_date: string | null;
  shipment_date: string | null;
  customs_company_id: number | null;
  customs_company_name: string | null;
  customs_company_label: string | null;
  customs_invoice_number: string | null;
  sender: string | null;
  receiver: string | null;
  carrier_name: string | null;
  vehicle_plate: string | null;
  driver_name: string | null;
  goods_description: string | null;
  quantity: number | null;
  unit: string | null;
  origin_place: string | null;
  destination_place: string | null;
  machine_id: number | null;
  machine?: { id: number; display_name: string; serial_number: string | null } | null;
  payable_invoice_id: number | null;
  payable_invoice?: { id: number; invoice_number: string | null } | null;
  client_id: number | null;
  supplier_id: number | null;
  status: string;
  has_scan: boolean;
  attachment_count?: number;
  notes: string | null;
}

interface Register {
  total: number;
  by_status: Record<string, number>;
  by_type: { document_type: string; count: number }[];
  open: number;
  missing: number;
  without_scan: number;
  unchecked: number;
  linked_to_machine: number;
  attention: {
    id: number;
    document_type: string;
    document_number: string | null;
    cmr_number: string | null;
    shipment_date: string | null;
    status: string;
    has_scan: boolean;
  }[];
}

const TYPES = [
  "cmr",
  "customs_declaration",
  "import_export",
  "delivery_note",
  "packing_list",
  "certificate_of_origin",
  "goods_invoice",
  "other",
] as const;

const STATUSES = ["draft", "received", "checked", "missing", "completed", "archived"] as const;

function statusTone(status: string): "gray" | "amber" | "green" | "red" | "indigo" {
  if (status === "completed" || status === "checked") return "green";
  if (status === "missing") return "red";
  if (status === "received") return "amber";
  if (status === "archived") return "indigo";
  return "gray";
}

export default function CustomsPage() {
  const { t } = useI18n();
  const [documents, setDocuments] = useState<CustomsDocument[]>([]);
  const [register, setRegister] = useState<Register | null>(null);
  const [meta, setMeta] = useState<PageMeta | null>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);

  const [search, setSearch] = useState("");
  const [documentType, setDocumentType] = useState("");
  const [status, setStatus] = useState("");
  const [missingOnly, setMissingOnly] = useState(false);

  const [creating, setCreating] = useState(false);
  const [editing, setEditing] = useState<CustomsDocument | null>(null);
  const [managingFiles, setManagingFiles] = useState<CustomsDocument | null>(null);

  const debouncedSearch = useDebouncedValue(search);

  const [page, setPage] = usePage([debouncedSearch, documentType, status, missingOnly]);

  const load = useCallback(async () => {
    setLoading(true);
    setError(null);
    const params = new URLSearchParams({ per_page: "25", page: String(page) });
    if (debouncedSearch) params.set("search", debouncedSearch);
    if (documentType) params.set("document_type", documentType);
    if (status) params.set("status", status);
    if (missingOnly) params.set("missing_paperwork", "1");

    try {
      const [list, summary] = await Promise.all([
        apiFetch<{ data: CustomsDocument[]; meta?: PageMeta }>(`/customs-documents?${params.toString()}`),
        apiFetch<{ data: Register }>("/customs-documents/register"),
      ]);
      setDocuments(list.data);
      setMeta(list.meta ?? null);
      setRegister(summary.data);
    } catch (err) {
      setError(errorMessage(err));
    } finally {
      setLoading(false);
    }
  }, [debouncedSearch, documentType, status, missingOnly, page]);

  useEffect(() => {
    void load();
  }, [load]);

  async function setDocumentStatus(document: CustomsDocument, next: string) {
    try {
      await apiFetch(`/customs-documents/${document.id}`, { method: "PUT", json: { status: next } });
      await load();
    } catch (err) {
      setError(errorMessage(err));
    }
  }

  async function remove(document: CustomsDocument) {
    if (!window.confirm(t("customs.deleteConfirm"))) return;
    try {
      await apiFetch(`/customs-documents/${document.id}`, { method: "DELETE" });
      await load();
    } catch (err) {
      setError(errorMessage(err));
    }
  }

  return (
    <div className="space-y-5">
      <div className="flex items-center justify-between">
        <h1 className="text-2xl font-semibold text-zinc-900 dark:text-zinc-50">{t("customs.title")}</h1>
        <Button onClick={() => setCreating(true)}>{t("customs.new")}</Button>
      </div>

      <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
        <Tile label={t("customs.total")} value={String(register?.total ?? 0)} />
        <Tile
          label={t("customs.missing")}
          value={String(register?.missing ?? 0)}
          hint={t("customs.withoutScanHint", { count: register?.without_scan ?? 0 })}
        />
        <Tile label={t("customs.unchecked")} value={String(register?.unchecked ?? 0)} />
        <Tile label={t("customs.linkedToMachine")} value={String(register?.linked_to_machine ?? 0)} />
      </div>

      <Card className="p-3">
        <div className="flex flex-wrap items-center gap-3">
          <Input
            className="max-w-sm"
            placeholder={t("customs.search")}
            value={search}
            onChange={(e) => setSearch(e.target.value)}
          />
          <Select className="max-w-[14rem]" value={documentType} onChange={(e) => setDocumentType(e.target.value)}>
            <option value="">{t("customs.allTypes")}</option>
            {TYPES.map((value) => (
              <option key={value} value={value}>
                {t(`documentType.${value}`)}
              </option>
            ))}
          </Select>
          <Select className="max-w-[12rem]" value={status} onChange={(e) => setStatus(e.target.value)}>
            <option value="">{t("customs.allStatuses")}</option>
            {STATUSES.map((value) => (
              <option key={value} value={value}>
                {t(`documentStatus.${value}`)}
              </option>
            ))}
          </Select>
          <label className="flex items-center gap-2 text-sm text-zinc-600 dark:text-zinc-300">
            <input type="checkbox" checked={missingOnly} onChange={(e) => setMissingOnly(e.target.checked)} />
            {t("customs.missingOnly")}
          </label>
        </div>
      </Card>

      {error && <p className="text-sm text-red-600">{error}</p>}

      <Card className="overflow-x-auto p-0">
        <table className="w-full min-w-[1100px] text-sm">
          <thead className="border-b border-zinc-200 text-left text-xs uppercase tracking-wider text-zinc-500 dark:border-zinc-800">
            <tr>
              <th className="px-4 py-3">{t("customs.document")}</th>
              <th className="px-4 py-3">{t("customs.dates")}</th>
              <th className="px-4 py-3">{t("customs.route")}</th>
              <th className="px-4 py-3">{t("customs.carrier")}</th>
              <th className="px-4 py-3">{t("customs.goods")}</th>
              <th className="px-4 py-3">{t("customs.links")}</th>
              <th className="px-4 py-3">{t("customs.status")}</th>
              <th className="px-4 py-3 text-right">{t("customs.actions")}</th>
            </tr>
          </thead>
          <tbody className="divide-y divide-zinc-100 dark:divide-zinc-800">
            {loading ? (
              <tr>
                <td colSpan={8} className="px-4 py-8 text-center text-zinc-500">
                  {t("common.loading")}
                </td>
              </tr>
            ) : documents.length === 0 ? (
              <tr>
                <td colSpan={8} className="px-4 py-8 text-center text-zinc-500">
                  {t("customs.none")}
                </td>
              </tr>
            ) : (
              documents.map((document) => (
                <tr key={document.id} className="text-zinc-800 dark:text-zinc-200">
                  <td className="px-4 py-3">
                    <span className="flex flex-col">
                      <span className="flex items-center gap-2">
                        <Badge tone="gray">{t(`documentType.${document.document_type}`)}</Badge>
                        <span className="font-medium">
                          {document.cmr_number ?? document.document_number ?? `#${document.id}`}
                        </span>
                      </span>
                      {document.customs_company_label && (
                        <span className="mt-1 text-xs text-zinc-500">
                          {document.customs_company_label}
                          {document.customs_invoice_number && ` · ${document.customs_invoice_number}`}
                        </span>
                      )}
                    </span>
                  </td>
                  <td className="px-4 py-3 text-xs text-zinc-600 dark:text-zinc-300">
                    <span className="flex flex-col">
                      {document.cmr_date && <span>{t("customs.cmrDateShort", { date: formatDate(document.cmr_date) })}</span>}
                      {document.shipment_date && (
                        <span>{t("customs.shipmentDateShort", { date: formatDate(document.shipment_date) })}</span>
                      )}
                    </span>
                  </td>
                  <td className="px-4 py-3">
                    <span className="flex flex-col text-xs text-zinc-600 dark:text-zinc-300">
                      <span>{document.sender ?? "—"} →</span>
                      <span>{document.receiver ?? "—"}</span>
                      {(document.origin_place || document.destination_place) && (
                        <span className="text-zinc-500">
                          {document.origin_place ?? "?"} → {document.destination_place ?? "?"}
                        </span>
                      )}
                    </span>
                  </td>
                  <td className="px-4 py-3">
                    <span className="flex flex-col text-xs text-zinc-600 dark:text-zinc-300">
                      <span>{document.carrier_name ?? "—"}</span>
                      {document.vehicle_plate && <span className="font-medium">{document.vehicle_plate}</span>}
                      {document.driver_name && <span className="text-zinc-500">{document.driver_name}</span>}
                    </span>
                  </td>
                  <td className="px-4 py-3">
                    <span className="flex flex-col text-xs text-zinc-600 dark:text-zinc-300">
                      <span>{document.goods_description ?? "—"}</span>
                      {document.quantity !== null && (
                        <span className="tabular-nums text-zinc-500">
                          {document.quantity} {document.unit ?? ""}
                        </span>
                      )}
                    </span>
                  </td>
                  <td className="px-4 py-3">
                    <span className="flex flex-col gap-1">
                      {document.machine && <Badge tone="indigo">{document.machine.display_name}</Badge>}
                      {document.payable_invoice && (
                        <Badge tone="indigo">
                          {document.payable_invoice.invoice_number ?? `#${document.payable_invoice.id}`}
                        </Badge>
                      )}
                      {!document.machine && !document.payable_invoice && (
                        <span className="text-xs text-zinc-500">—</span>
                      )}
                    </span>
                  </td>
                  <td className="px-4 py-3">
                    <span className="flex flex-col gap-1">
                      <Badge tone={statusTone(document.status)}>{t(`documentStatus.${document.status}`)}</Badge>
                      {!document.has_scan && <Badge tone="red">{t("customs.noScan")}</Badge>}
                    </span>
                  </td>
                  <td className="px-4 py-3 text-right">
                    <div className="flex flex-wrap justify-end gap-2">
                      <Button variant="secondary" className="h-8 px-3" onClick={() => setManagingFiles(document)}>
                        {t("customs.scans")}
                        {document.attachment_count ? ` (${document.attachment_count})` : ""}
                      </Button>
                      {document.status !== "checked" && document.status !== "completed" && (
                        <Button
                          variant="secondary"
                          className="h-8 px-3"
                          onClick={() => void setDocumentStatus(document, "checked")}
                        >
                          {t("customs.markChecked")}
                        </Button>
                      )}
                      <Button variant="secondary" className="h-8 px-3" onClick={() => setEditing(document)}>
                        {t("customs.edit")}
                      </Button>
                      <Button variant="secondary" className="h-8 px-3" onClick={() => void remove(document)}>
                        {t("customs.delete")}
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

      {register && register.attention.length > 0 && (
        <Card className="p-4">
          <p className="mb-3 text-xs font-semibold uppercase tracking-wider text-zinc-500">
            {t("customs.needsAttention")}
          </p>
          <ul className="grid gap-2 sm:grid-cols-2 lg:grid-cols-3">
            {register.attention.map((row) => (
              <li
                key={row.id}
                className="flex items-center justify-between gap-3 rounded-lg border border-zinc-200 px-3 py-2 text-sm dark:border-zinc-800"
              >
                <span className="min-w-0">
                  <span className="block truncate text-zinc-800 dark:text-zinc-100">
                    {row.cmr_number ?? row.document_number ?? `#${row.id}`}
                  </span>
                  <span className="block text-xs text-zinc-500">
                    {t(`documentType.${row.document_type}`)} · {formatDate(row.shipment_date)}
                  </span>
                </span>
                <Badge tone={row.status === "missing" ? "red" : "amber"}>
                  {row.status === "missing" ? t("documentStatus.missing") : t("customs.noScan")}
                </Badge>
              </li>
            ))}
          </ul>
        </Card>
      )}

      {(creating || editing) && (
        <DocumentModal
          document={editing ?? undefined}
          onClose={() => {
            setCreating(false);
            setEditing(null);
          }}
          onSaved={load}
        />
      )}
      {managingFiles && (
        <AttachmentsModal
          title={`${t("customs.scans")} — ${managingFiles.cmr_number ?? managingFiles.document_number ?? `#${managingFiles.id}`}`}
          basePath={`/customs-documents/${managingFiles.id}/attachments`}
          defaultKind="cmr"
          onClose={() => setManagingFiles(null)}
          onChanged={load}
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

function DocumentModal({
  document,
  onClose,
  onSaved,
}: {
  document?: CustomsDocument;
  onClose: () => void;
  onSaved: () => Promise<void>;
}) {
  const { t } = useI18n();
  const [form, setForm] = useState({
    document_type: document?.document_type ?? "cmr",
    document_number: document?.document_number ?? "",
    cmr_number: document?.cmr_number ?? "",
    issue_date: document?.issue_date ?? "",
    cmr_date: document?.cmr_date ?? "",
    shipment_date: document?.shipment_date ?? "",
    customs_company_id: document?.customs_company_id ? String(document.customs_company_id) : "",
    customs_company_name: document?.customs_company_name ?? "",
    customs_invoice_number: document?.customs_invoice_number ?? "",
    sender: document?.sender ?? "",
    receiver: document?.receiver ?? "",
    carrier_name: document?.carrier_name ?? "",
    vehicle_plate: document?.vehicle_plate ?? "",
    driver_name: document?.driver_name ?? "",
    goods_description: document?.goods_description ?? "",
    quantity: document?.quantity == null ? "" : String(document.quantity),
    unit: document?.unit ?? "",
    origin_place: document?.origin_place ?? "",
    destination_place: document?.destination_place ?? "",
    machine_id: document?.machine_id ? String(document.machine_id) : "",
    payable_invoice_id: document?.payable_invoice_id ? String(document.payable_invoice_id) : "",
    client_id: document?.client_id ? String(document.client_id) : "",
    supplier_id: document?.supplier_id ? String(document.supplier_id) : "",
    status: document?.status ?? "draft",
    notes: document?.notes ?? "",
  });
  const [error, setError] = useState<string | null>(null);
  const [saving, setSaving] = useState(false);

  function set(key: keyof typeof form, value: string) {
    setForm((prev) => ({ ...prev, [key]: value }));
  }

  /** Picking the machine offers its purchase invoice, the usual link for CMRs. */
  /**
   * Picking a machine offers the invoice it was bought on, which is the link
   * this paperwork almost always belongs to. An invoice already chosen is left
   * alone — the suggestion never overrides a decision.
   */
  function selectMachine(value: number | null, option: LookupOption | null) {
    const suggested = option?.meta?.payable_invoice_id;

    setForm((prev) => ({
      ...prev,
      machine_id: value === null ? "" : String(value),
      payable_invoice_id:
        prev.payable_invoice_id === "" && typeof suggested === "number"
          ? String(suggested)
          : prev.payable_invoice_id,
    }));
  }

  async function submit(e: React.FormEvent) {
    e.preventDefault();
    setSaving(true);
    setError(null);
    try {
      await apiFetch(document ? `/customs-documents/${document.id}` : "/customs-documents", {
        method: document ? "PUT" : "POST",
        json: {
          document_type: form.document_type,
          document_number: form.document_number.trim() || null,
          cmr_number: form.cmr_number.trim() || null,
          issue_date: form.issue_date || null,
          cmr_date: form.cmr_date || null,
          shipment_date: form.shipment_date || null,
          customs_company_id: form.customs_company_id ? Number(form.customs_company_id) : null,
          customs_company_name: form.customs_company_name.trim() || null,
          customs_invoice_number: form.customs_invoice_number.trim() || null,
          sender: form.sender.trim() || null,
          receiver: form.receiver.trim() || null,
          carrier_name: form.carrier_name.trim() || null,
          vehicle_plate: form.vehicle_plate.trim() || null,
          driver_name: form.driver_name.trim() || null,
          goods_description: form.goods_description.trim() || null,
          quantity: form.quantity === "" ? null : Number(form.quantity),
          unit: form.unit.trim() || null,
          origin_place: form.origin_place.trim() || null,
          destination_place: form.destination_place.trim() || null,
          machine_id: form.machine_id ? Number(form.machine_id) : null,
          payable_invoice_id: form.payable_invoice_id ? Number(form.payable_invoice_id) : null,
          client_id: form.client_id ? Number(form.client_id) : null,
          supplier_id: form.supplier_id ? Number(form.supplier_id) : null,
          status: form.status,
          notes: form.notes.trim() || null,
        },
      });
      await onSaved();
      onClose();
    } catch (err) {
      setError(errorMessage(err));
    } finally {
      setSaving(false);
    }
  }

  const label = "mb-1 block text-sm font-medium text-zinc-700 dark:text-zinc-300";
  const section = "text-xs font-semibold uppercase tracking-wider text-zinc-500";
  const isCmr = form.document_type === "cmr";

  return (
    <Modal open onClose={onClose} title={document ? t("customs.edit") : t("customs.new")}>
      <form onSubmit={submit} className="space-y-5">
        <p className={section}>{t("customs.documentSection")}</p>
        <div className="grid grid-cols-2 gap-4">
          <div>
            <label className={label}>{t("customs.type")}</label>
            <Select value={form.document_type} onChange={(e) => set("document_type", e.target.value)}>
              {TYPES.map((value) => (
                <option key={value} value={value}>
                  {t(`documentType.${value}`)}
                </option>
              ))}
            </Select>
          </div>
          <div>
            <label className={label}>{t("customs.status")}</label>
            <Select value={form.status} onChange={(e) => set("status", e.target.value)}>
              {STATUSES.map((value) => (
                <option key={value} value={value}>
                  {t(`documentStatus.${value}`)}
                </option>
              ))}
            </Select>
          </div>
          <div>
            <label className={label}>{t("customs.documentNumber")}</label>
            <Input value={form.document_number} onChange={(e) => set("document_number", e.target.value)} />
          </div>
          <div>
            <label className={label}>
              {t("customs.cmrNumber")}
              {isCmr && " *"}
            </label>
            <Input value={form.cmr_number} onChange={(e) => set("cmr_number", e.target.value)} required={isCmr} />
          </div>
          <div>
            <label className={label}>{t("customs.issueDate")}</label>
            <Input type="date" value={form.issue_date} onChange={(e) => set("issue_date", e.target.value)} />
          </div>
          <div>
            <label className={label}>{t("customs.cmrDate")}</label>
            <Input type="date" value={form.cmr_date} onChange={(e) => set("cmr_date", e.target.value)} />
          </div>
          <div>
            <label className={label}>{t("customs.shipmentDate")}</label>
            <Input type="date" value={form.shipment_date} onChange={(e) => set("shipment_date", e.target.value)} />
          </div>
        </div>

        <p className={section}>{t("customs.customsCompanySection")}</p>
        <div className="grid grid-cols-2 gap-4">
          <div>
            <label className={label}>{t("customs.customsCompany")}</label>
            <AsyncSelect
              resource="suppliers"
              value={numeric(form.customs_company_id)}
              onChange={(value) => set("customs_company_id", value === null ? "" : String(value))}
              params={{ active_only: true }}
              emptyLabel={t("customs.noCustomsCompany")}
            />
          </div>
          <div>
            <label className={label}>{t("customs.customsCompanyName")}</label>
            <Input
              value={form.customs_company_name}
              onChange={(e) => set("customs_company_name", e.target.value)}
            />
          </div>
          <div>
            <label className={label}>{t("customs.customsInvoice")}</label>
            <Input
              value={form.customs_invoice_number}
              onChange={(e) => set("customs_invoice_number", e.target.value)}
            />
          </div>
        </div>

        <p className={section}>{t("customs.transportSection")}</p>
        <div className="grid grid-cols-2 gap-4">
          <div>
            <label className={label}>{t("customs.sender")}</label>
            <Input value={form.sender} onChange={(e) => set("sender", e.target.value)} />
          </div>
          <div>
            <label className={label}>{t("customs.receiver")}</label>
            <Input value={form.receiver} onChange={(e) => set("receiver", e.target.value)} />
          </div>
          <div>
            <label className={label}>{t("customs.carrier")}</label>
            <Input value={form.carrier_name} onChange={(e) => set("carrier_name", e.target.value)} />
          </div>
          <div>
            <label className={label}>{t("customs.plate")}</label>
            <Input value={form.vehicle_plate} onChange={(e) => set("vehicle_plate", e.target.value)} />
          </div>
          <div>
            <label className={label}>{t("customs.driver")}</label>
            <Input value={form.driver_name} onChange={(e) => set("driver_name", e.target.value)} />
          </div>
          <div>
            <label className={label}>{t("customs.origin")}</label>
            <Input value={form.origin_place} onChange={(e) => set("origin_place", e.target.value)} />
          </div>
          <div>
            <label className={label}>{t("customs.destination")}</label>
            <Input value={form.destination_place} onChange={(e) => set("destination_place", e.target.value)} />
          </div>
        </div>

        <p className={section}>{t("customs.goodsSection")}</p>
        <div className="grid grid-cols-2 gap-4">
          <div className="col-span-2">
            <label className={label}>{t("customs.goods")}</label>
            <Input value={form.goods_description} onChange={(e) => set("goods_description", e.target.value)} />
          </div>
          <div>
            <label className={label}>{t("customs.quantity")}</label>
            <Input
              type="number"
              step="0.001"
              min="0"
              value={form.quantity}
              onChange={(e) => set("quantity", e.target.value)}
            />
          </div>
          <div>
            <label className={label}>{t("customs.unit")}</label>
            <Input value={form.unit} onChange={(e) => set("unit", e.target.value)} />
          </div>
        </div>

        <p className={section}>{t("customs.linksSection")}</p>
        <div className="grid grid-cols-1 gap-4">
          <div>
            <label className={label}>{t("customs.machine")}</label>
            <AsyncSelect
              resource="machines"
              value={numeric(form.machine_id)}
              onChange={selectMachine}
              emptyLabel={t("customs.noMachine")}
            />
          </div>
          <div>
            <label className={label}>{t("customs.payableInvoice")}</label>
            <AsyncSelect
              resource="payable-invoices"
              value={numeric(form.payable_invoice_id)}
              onChange={(value) => set("payable_invoice_id", value === null ? "" : String(value))}
              placeholder={t("machines.searchInvoice")}
              emptyLabel={t("customs.notLinked")}
            />
          </div>
          <div className="grid grid-cols-2 gap-4">
            <div>
              <label className={label}>{t("customs.client")}</label>
              <AsyncSelect
                resource="clients"
                value={numeric(form.client_id)}
                onChange={(value) => set("client_id", value === null ? "" : String(value))}
                emptyLabel={t("customs.notLinked")}
              />
            </div>
            <div>
              <label className={label}>{t("customs.supplier")}</label>
              <AsyncSelect
                resource="suppliers"
                value={numeric(form.supplier_id)}
                onChange={(value) => set("supplier_id", value === null ? "" : String(value))}
                params={{ active_only: true }}
                emptyLabel={t("customs.notLinked")}
              />
            </div>
          </div>
        </div>

        <div>
          <label className={label}>{t("customs.notes")}</label>
          <Input value={form.notes} onChange={(e) => set("notes", e.target.value)} />
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
