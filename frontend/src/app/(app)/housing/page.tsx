"use client";

import { useState } from "react";
import { ApiError, apiFetch } from "@/lib/api";
import { useResource } from "@/lib/data/use-resource";
import { useEmployeeOptions, type EmployeeOption } from "@/lib/data/use-options";
import { useI18n } from "@/lib/i18n/context";
import { formatDate, formatMoney, todayISO } from "@/lib/format";
import { AttachmentsModal } from "@/components/attachments-modal";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Card } from "@/components/ui/card";
import { Input } from "@/components/ui/input";
import { Modal } from "@/components/ui/modal";
import { Select } from "@/components/ui/select";

interface Occupant {
  employee_id: number;
  full_name: string | null;
  room: string | null;
  occupancy_id?: number;
  moved_in_at?: string | null;
}

interface House {
  id: number;
  name: string;
  address: string | null;
  landlord_name: string | null;
  landlord_phone: string | null;
  landlord_id_number: string | null;
  landlord_bank_account: string | null;
  monthly_rent: number | null;
  deposit: number | null;
  currency: string;
  contract_start_date: string | null;
  contract_end_date: string | null;
  rent_due_day: number | null;
  is_active: boolean;
  occupant_count?: number;
  occupants?: Occupant[];
  notes: string | null;
}

interface Occupancy {
  id: number;
  house_id: number;
  house?: { id: number; name: string };
  employee_id: number;
  employee?: { id: number; full_name: string };
  room: string | null;
  moved_in_at: string | null;
  moved_out_at: string | null;
  is_current: boolean;
}

interface RentPayment {
  id: number;
  house_id: number;
  house?: { id: number; name: string; rent_due_day: number | null };
  month: string | null;
  currency: string;
  rent_amount_due: number;
  paid_amount: number;
  remaining_amount: number;
  status: string;
  due_date: string;
  is_overdue: boolean;
  cost_bearer: string;
  exception_reason: string | null;
}

interface UtilityBill {
  id: number;
  house_id: number;
  house?: { id: number; name: string };
  bill_type: string;
  billing_period: string | null;
  amount: number;
  currency: string;
  due_date: string | null;
  paid_date: string | null;
  paid_amount: number;
  remaining_amount: number;
  status: string;
  is_overdue: boolean;
  cost_bearer: string;
  exception_reason: string | null;
  attachment_count?: number;
}

interface HousingDeduction {
  id: number;
  employee_id: number;
  employee?: { id: number; full_name: string };
  house_id: number;
  house?: { id: number; name: string };
  month: string | null;
  currency: string;
  rent_share: number;
  utility_share: number;
  amount_deducted: number;
  remaining_amount: number;
  reason: string;
}

interface Summary {
  month: string;
  houses: {
    house_id: number;
    name: string;
    is_active: boolean;
    currency: string;
    occupants: Occupant[];
    occupant_count: number;
    rent_due: number;
    rent_paid: number;
    rent_unpaid: number;
    rent_overdue: number;
    bills_total: number;
    bills_unpaid: number;
    bills_overdue: number;
    monthly_cost: number;
    occupancy_changes: number;
  }[];
  totals: {
    rent_due: number;
    rent_paid: number;
    rent_unpaid: number;
    bills_total: number;
    bills_unpaid: number;
    monthly_cost: number;
    charged_to_workers: number;
    occupants: number;
    active_houses: number;
  };
  alerts: {
    overdue_rent: { rent_payment_id: number; house: string | null; due_date: string; remaining: number }[];
    overdue_bills: {
      utility_bill_id: number;
      house: string | null;
      bill_type: string;
      due_date: string | null;
      remaining: number;
    }[];
  };
}

const BILL_TYPES = ["electricity", "water", "internet", "heating", "garbage", "maintenance", "other"] as const;
const METHODS = ["cash", "nlb", "lovcen", "other"] as const;
const TABS = ["houses", "rent", "bills", "deductions"] as const;

type Tab = (typeof TABS)[number];

function currentMonth(): string {
  return todayISO().slice(0, 7);
}

function statusTone(status: string): "gray" | "amber" | "green" {
  if (status === "paid") return "green";
  if (status === "partial") return "amber";
  return "gray";
}

export default function HousingPage() {
  const { t } = useI18n();
  const [tab, setTab] = useState<Tab>("houses");
  const [month, setMonth] = useState(currentMonth);
  // The tabs used to be handed an `onChanged` callback so a write inside one
  // could refresh these totals. They no longer need to: every write goes through
  // apiFetch, whose markMutated() revalidates this resource wherever it happened.
  const { data, error } = useResource<{ data: Summary }>(`/housing/summary?month=${month}`);
  const summary = data?.data ?? null;

  return (
    <div className="space-y-5">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <h1 className="text-[2.5rem] font-light leading-none tracking-[-0.02em] text-zinc-900 dark:text-zinc-50">{t("housing.title")}</h1>
        <Input className="w-[10rem]" type="month" value={month} onChange={(e) => setMonth(e.target.value)} />
      </div>

      <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
        <Tile label={t("housing.monthlyCost")} value={formatMoney(summary?.totals.monthly_cost ?? 0)} />
        <Tile
          label={t("housing.unpaidRent")}
          value={formatMoney(summary?.totals.rent_unpaid ?? 0)}
          hint={t("housing.overdueRentHint", { count: summary?.alerts.overdue_rent.length ?? 0 })}
        />
        <Tile
          label={t("housing.unpaidBills")}
          value={formatMoney(summary?.totals.bills_unpaid ?? 0)}
          hint={t("housing.overdueBillsHint", { count: summary?.alerts.overdue_bills.length ?? 0 })}
        />
        <Tile
          label={t("housing.occupants")}
          value={String(summary?.totals.occupants ?? 0)}
          hint={t("housing.activeHousesHint", { count: summary?.totals.active_houses ?? 0 })}
        />
      </div>

      {summary && summary.totals.charged_to_workers > 0 && (
        <p className="text-sm text-amber-700 dark:text-amber-400">
          {t("housing.chargedToWorkers", { amount: formatMoney(summary.totals.charged_to_workers) })}
        </p>
      )}
      {error && <p className="text-sm text-red-600">{error}</p>}

      <div className="control-surface inline-flex flex-wrap gap-1 rounded-full p-1">
        {TABS.map((value) => (
          <button
            key={value}
            onClick={() => setTab(value)}
            className={
              tab === value
                ? "rounded-full bg-white px-4 py-1.5 text-sm font-medium text-zinc-900 shadow-[0_1px_2px_rgb(13_12_11/0.06),0_4px_12px_-6px_rgb(13_12_11/0.25)] dark:bg-white/15 dark:text-zinc-50"
                : "rounded-full px-4 py-1.5 text-sm text-zinc-500 transition-colors hover:bg-white/60 hover:text-zinc-800 dark:hover:bg-white/10 dark:hover:text-zinc-200"
            }
          >
            {t(`housing.tab.${value}`)}
          </button>
        ))}
      </div>

      {tab === "houses" && <HousesTab summary={summary} />}
      {tab === "rent" && <RentTab month={month} />}
      {tab === "bills" && <BillsTab month={month} />}
      {tab === "deductions" && <DeductionsTab month={month} />}
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

function HousesTab({ summary }: { summary: Summary | null }) {
  const { t } = useI18n();
  const [creating, setCreating] = useState(false);
  const [editing, setEditing] = useState<House | null>(null);
  const [managing, setManaging] = useState<House | null>(null);
  const [error, setError] = useState<string | null>(null);

  const { data, loading } = useResource<{ data: House[] }>("/houses?with_occupants=1");
  const houses = data?.data ?? [];

  const { options: employees } = useEmployeeOptions();

  async function remove(house: House) {
    if (!window.confirm(t("housing.removeHouseConfirm", { name: house.name }))) return;
    try {
      await apiFetch(`/houses/${house.id}`, { method: "DELETE" });
    } catch (err) {
      setError(err instanceof ApiError ? err.message : "Error");
    }
  }

  const costByHouse = new Map((summary?.houses ?? []).map((row) => [row.house_id, row]));

  return (
    <div className="space-y-4">
      <div className="flex justify-end">
        <Button onClick={() => setCreating(true)}>{t("housing.newHouse")}</Button>
      </div>
      {error && <p className="text-sm text-red-600">{error}</p>}

      <Card className="table-quiet overflow-x-auto p-0">
        <table className="w-full min-w-[980px] text-sm">
          <thead className="border-b border-zinc-900/8 text-left text-[11px] uppercase tracking-[0.1em] text-zinc-500 dark:border-white/10">
            <tr>
              <th className="px-4 py-3">{t("housing.house")}</th>
              <th className="px-4 py-3">{t("housing.landlord")}</th>
              <th className="px-4 py-3 text-right">{t("housing.rent")}</th>
              <th className="px-4 py-3">{t("housing.occupantsColumn")}</th>
              <th className="px-4 py-3 text-right">{t("housing.monthCost")}</th>
              <th className="px-4 py-3">{t("housing.status")}</th>
              <th className="px-4 py-3 text-right">{t("housing.actions")}</th>
            </tr>
          </thead>
          <tbody className="divide-y divide-zinc-900/5 dark:divide-white/8">
            {loading ? (
              <tr>
                <td colSpan={7} className="px-4 py-14 text-center text-sm text-zinc-500">
                  {t("common.loading")}
                </td>
              </tr>
            ) : houses.length === 0 ? (
              <tr>
                <td colSpan={7} className="px-4 py-14 text-center text-sm text-zinc-500">
                  {t("housing.noHouses")}
                </td>
              </tr>
            ) : (
              houses.map((house) => {
                const cost = costByHouse.get(house.id);

                return (
                  <tr key={house.id} className="text-zinc-800 dark:text-zinc-200">
                    <td className="px-4 py-3">
                      <span className="flex flex-col">
                        <span className="font-medium">{house.name}</span>
                        {house.address && <span className="text-xs text-zinc-500">{house.address}</span>}
                      </span>
                    </td>
                    <td className="px-4 py-3">
                      <span className="flex flex-col text-xs text-zinc-600 dark:text-zinc-300">
                        <span>{house.landlord_name ?? "—"}</span>
                        {house.landlord_phone && <span className="text-zinc-500">{house.landlord_phone}</span>}
                      </span>
                    </td>
                    <td className="px-4 py-3 text-right tabular-nums">
                      <span className="flex flex-col">
                        <span>
                          {house.monthly_rent === null ? "—" : formatMoney(house.monthly_rent, house.currency)}
                        </span>
                        {house.rent_due_day && (
                          <span className="text-xs text-zinc-500">
                            {t("housing.dueDayShort", { day: house.rent_due_day })}
                          </span>
                        )}
                      </span>
                    </td>
                    <td className="px-4 py-3">
                      {house.occupants && house.occupants.length > 0 ? (
                        <span className="flex flex-wrap gap-1">
                          {house.occupants.map((occupant) => (
                            <Badge key={occupant.employee_id} tone="indigo">
                              {occupant.full_name}
                              {occupant.room ? ` · ${occupant.room}` : ""}
                            </Badge>
                          ))}
                        </span>
                      ) : (
                        <span className="text-xs text-zinc-500">{t("housing.empty")}</span>
                      )}
                    </td>
                    <td className="px-4 py-3 text-right tabular-nums">
                      {cost ? formatMoney(cost.monthly_cost, cost.currency) : "—"}
                    </td>
                    <td className="px-4 py-3">
                      <Badge tone={house.is_active ? "green" : "gray"}>
                        {house.is_active ? t("housing.active") : t("housing.inactive")}
                      </Badge>
                    </td>
                    <td className="px-4 py-3 text-right">
                      <div className="flex flex-wrap justify-end gap-2">
                        <Button variant="secondary" className="h-8 px-3" onClick={() => setManaging(house)}>
                          {t("housing.manageOccupants")}
                        </Button>
                        <Button variant="secondary" className="h-8 px-3" onClick={() => setEditing(house)}>
                          {t("housing.edit")}
                        </Button>
                        <Button variant="secondary" className="h-8 px-3" onClick={() => void remove(house)}>
                          {t("housing.remove")}
                        </Button>
                      </div>
                    </td>
                  </tr>
                );
              })
            )}
          </tbody>
        </table>
      </Card>

      {(creating || editing) && (
        <HouseModal
          house={editing ?? undefined}
          onClose={() => {
            setCreating(false);
            setEditing(null);
          }}
        />
      )}
      {managing && (
        <OccupantsModal
          house={managing}
          employees={employees}
          onClose={() => setManaging(null)}
        />
      )}
    </div>
  );
}

function HouseModal({
  house,
  onClose,
}: {
  house?: House;
  onClose: () => void;
}) {
  const { t } = useI18n();
  const [form, setForm] = useState({
    name: house?.name ?? "",
    address: house?.address ?? "",
    landlord_name: house?.landlord_name ?? "",
    landlord_phone: house?.landlord_phone ?? "",
    landlord_id_number: house?.landlord_id_number ?? "",
    landlord_bank_account: house?.landlord_bank_account ?? "",
    monthly_rent: house?.monthly_rent == null ? "" : String(house.monthly_rent),
    deposit: house?.deposit == null ? "" : String(house.deposit),
    currency: house?.currency ?? "EUR",
    contract_start_date: house?.contract_start_date ?? "",
    contract_end_date: house?.contract_end_date ?? "",
    rent_due_day: house?.rent_due_day == null ? "" : String(house.rent_due_day),
    notes: house?.notes ?? "",
  });
  const [isActive, setIsActive] = useState(house?.is_active ?? true);
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
      await apiFetch(house ? `/houses/${house.id}` : "/houses", {
        method: house ? "PUT" : "POST",
        json: {
          name: form.name.trim(),
          address: form.address.trim() || null,
          landlord_name: form.landlord_name.trim() || null,
          landlord_phone: form.landlord_phone.trim() || null,
          landlord_id_number: form.landlord_id_number.trim() || null,
          landlord_bank_account: form.landlord_bank_account.trim() || null,
          monthly_rent: form.monthly_rent === "" ? null : Number(form.monthly_rent),
          deposit: form.deposit === "" ? null : Number(form.deposit),
          currency: form.currency,
          contract_start_date: form.contract_start_date || null,
          contract_end_date: form.contract_end_date || null,
          rent_due_day: form.rent_due_day === "" ? null : Number(form.rent_due_day),
          is_active: isActive,
          notes: form.notes.trim() || null,
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
    <Modal open onClose={onClose} title={house ? t("housing.editHouse") : t("housing.newHouse")}>
      <form onSubmit={submit} className="space-y-5">
        <div className="grid grid-cols-2 gap-4">
          <div>
            <label className={label}>{t("housing.houseName")}</label>
            <Input value={form.name} onChange={(e) => set("name", e.target.value)} required />
          </div>
          <div>
            <label className={label}>{t("housing.address")}</label>
            <Input value={form.address} onChange={(e) => set("address", e.target.value)} />
          </div>
        </div>

        <p className={section}>{t("housing.landlordSection")}</p>
        <div className="grid grid-cols-2 gap-4">
          <div>
            <label className={label}>{t("housing.landlordName")}</label>
            <Input value={form.landlord_name} onChange={(e) => set("landlord_name", e.target.value)} />
          </div>
          <div>
            <label className={label}>{t("housing.landlordPhone")}</label>
            <Input value={form.landlord_phone} onChange={(e) => set("landlord_phone", e.target.value)} />
          </div>
          <div>
            <label className={label}>{t("housing.landlordId")}</label>
            <Input value={form.landlord_id_number} onChange={(e) => set("landlord_id_number", e.target.value)} />
          </div>
          <div>
            <label className={label}>{t("housing.landlordAccount")}</label>
            <Input
              value={form.landlord_bank_account}
              onChange={(e) => set("landlord_bank_account", e.target.value)}
            />
          </div>
        </div>

        <p className={section}>{t("housing.contractSection")}</p>
        <div className="grid grid-cols-2 gap-4">
          <div>
            <label className={label}>{t("housing.monthlyRent")}</label>
            <Input
              type="number"
              step="0.01"
              min="0"
              value={form.monthly_rent}
              onChange={(e) => set("monthly_rent", e.target.value)}
            />
          </div>
          <div>
            <label className={label}>{t("housing.deposit")}</label>
            <Input
              type="number"
              step="0.01"
              min="0"
              value={form.deposit}
              onChange={(e) => set("deposit", e.target.value)}
            />
          </div>
          <div>
            <label className={label}>{t("housing.currency")}</label>
            <Select value={form.currency} onChange={(e) => set("currency", e.target.value)}>
              <option value="EUR">EUR</option>
              <option value="TRY">TRY</option>
            </Select>
          </div>
          <div>
            <label className={label}>{t("housing.rentDueDay")}</label>
            <Input
              type="number"
              min="1"
              max="31"
              value={form.rent_due_day}
              onChange={(e) => set("rent_due_day", e.target.value)}
            />
          </div>
          <div>
            <label className={label}>{t("housing.contractStart")}</label>
            <Input
              type="date"
              value={form.contract_start_date}
              onChange={(e) => set("contract_start_date", e.target.value)}
            />
          </div>
          <div>
            <label className={label}>{t("housing.contractEnd")}</label>
            <Input
              type="date"
              value={form.contract_end_date}
              onChange={(e) => set("contract_end_date", e.target.value)}
            />
          </div>
        </div>

        <div>
          <label className={label}>{t("housing.notes")}</label>
          <Input value={form.notes} onChange={(e) => set("notes", e.target.value)} />
        </div>
        <label className="flex items-center gap-2 text-sm text-zinc-600 dark:text-zinc-300">
          <input type="checkbox" checked={isActive} onChange={(e) => setIsActive(e.target.checked)} />
          {t("housing.active")}
        </label>

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

/** Move workers in and out; history is kept, so a move closes the previous stay. */
function OccupantsModal({
  house,
  employees,
  onClose,
}: {
  house: House;
  employees: EmployeeOption[];
  onClose: () => void;
}) {
  const { t } = useI18n();
  const [employeeId, setEmployeeId] = useState("");
  const [room, setRoom] = useState("");
  const [movedInAt, setMovedInAt] = useState(todayISO());
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);

  // Read-only list, so it renders the cache directly — moving someone in or out
  // writes through apiFetch, and markMutated() brings this back current.
  const { data, loading } = useResource<{ data: Occupancy[] }>(
    `/housing/occupancies?house_id=${house.id}`,
  );
  const stays = data?.data ?? [];

  async function moveIn(e: React.FormEvent) {
    e.preventDefault();
    setBusy(true);
    setError(null);
    try {
      await apiFetch("/housing/occupancies", {
        method: "POST",
        json: {
          house_id: house.id,
          employee_id: Number(employeeId),
          room: room.trim() || null,
          moved_in_at: movedInAt,
        },
      });
      setEmployeeId("");
      setRoom("");
    } catch (err) {
      setError(err instanceof ApiError ? err.message : "Error");
    } finally {
      setBusy(false);
    }
  }

  async function moveOut(stay: Occupancy) {
    const date = window.prompt(t("housing.moveOutPrompt"), todayISO());
    if (!date) return;
    setBusy(true);
    setError(null);
    try {
      await apiFetch(`/housing/occupancies/${stay.id}`, { method: "PUT", json: { moved_out_at: date } });
    } catch (err) {
      setError(err instanceof ApiError ? err.message : "Error");
    } finally {
      setBusy(false);
    }
  }

  const label = "mb-1.5 block px-4 text-[11px] font-medium uppercase tracking-[0.08em] text-zinc-500";

  return (
    <Modal open onClose={onClose} title={`${t("housing.manageOccupants")} — ${house.name}`}>
      <div className="space-y-5">
        {loading ? (
          <p className="text-sm text-zinc-500">{t("common.loading")}</p>
        ) : stays.length === 0 ? (
          <p className="text-sm text-zinc-500">{t("housing.noStays")}</p>
        ) : (
          <ul className="max-h-64 space-y-2 overflow-y-auto">
            {stays.map((stay) => (
              <li
                key={stay.id}
                className="flex items-center justify-between gap-3 rounded-lg border border-zinc-200 px-3 py-2 dark:border-zinc-800"
              >
                <span className="min-w-0">
                  <span className="flex items-center gap-2">
                    <span className="truncate text-sm text-zinc-800 dark:text-zinc-100">
                      {stay.employee?.full_name ?? `#${stay.employee_id}`}
                    </span>
                    {stay.is_current ? (
                      <Badge tone="green">{t("housing.living")}</Badge>
                    ) : (
                      <Badge tone="gray">{t("housing.movedOut")}</Badge>
                    )}
                  </span>
                  <span className="mt-1 block text-xs text-zinc-500">
                    {formatDate(stay.moved_in_at)} → {stay.moved_out_at ? formatDate(stay.moved_out_at) : "…"}
                    {stay.room ? ` · ${stay.room}` : ""}
                  </span>
                </span>
                {stay.is_current && (
                  <Button variant="secondary" className="h-8 px-3" disabled={busy} onClick={() => void moveOut(stay)}>
                    {t("housing.moveOut")}
                  </Button>
                )}
              </li>
            ))}
          </ul>
        )}

        <form onSubmit={moveIn} className="space-y-3 border-t border-zinc-200 pt-4 dark:border-zinc-800">
          <p className="text-xs font-semibold uppercase tracking-wider text-zinc-500">{t("housing.moveIn")}</p>
          <p className="text-xs text-zinc-500">{t("housing.moveInHint")}</p>
          <div className="grid grid-cols-2 gap-4">
            <div>
              <label className={label}>{t("housing.worker")}</label>
              <Select value={employeeId} onChange={(e) => setEmployeeId(e.target.value)} required>
                <option value="">{t("housing.selectWorker")}</option>
                {employees.map((employee) => (
                  <option key={employee.id} value={employee.id}>
                    {employee.full_name}
                  </option>
                ))}
              </Select>
            </div>
            <div>
              <label className={label}>{t("housing.moveInDate")}</label>
              <Input type="date" value={movedInAt} onChange={(e) => setMovedInAt(e.target.value)} required />
            </div>
            <div>
              <label className={label}>{t("housing.room")}</label>
              <Input value={room} onChange={(e) => setRoom(e.target.value)} />
            </div>
          </div>
          {error && <p className="text-sm text-red-600">{error}</p>}
          <div className="flex justify-end gap-2">
            <Button type="button" variant="secondary" onClick={onClose}>
              {t("common.cancel")}
            </Button>
            <Button type="submit" disabled={busy || !employeeId}>
              {busy ? t("common.saving") : t("housing.moveIn")}
            </Button>
          </div>
        </form>
      </div>
    </Modal>
  );
}

function RentTab({ month }: { month: string }) {
  const { t } = useI18n();
  const [paying, setPaying] = useState<RentPayment | null>(null);
  const [preview, setPreview] = useState<{
    data: RentPayment[];
    meta: { created_count: number; skipped: { house_id: number; name: string; reason: string }[]; already_existing_count: number };
  } | null>(null);
  const [error, setError] = useState<string | null>(null);

  const { data, loading } = useResource<{ data: RentPayment[] }>(`/housing/rent?month=${month}`);
  const rows = data?.data ?? [];

  async function openPreview() {
    setError(null);
    try {
      const res = await apiFetch<typeof preview>("/housing/rent/generate", {
        method: "POST",
        json: { month, preview: true },
      });
      setPreview(res);
    } catch (err) {
      setError(err instanceof ApiError ? err.message : "Error");
    }
  }

  async function confirmGenerate() {
    await apiFetch("/housing/rent/generate", { method: "POST", json: { month } });
    setPreview(null);
  }

  return (
    <div className="space-y-4">
      <div className="flex justify-end">
        <Button onClick={() => void openPreview()}>{t("housing.generateRent")}</Button>
      </div>
      {error && <p className="text-sm text-red-600">{error}</p>}

      <Card className="table-quiet overflow-x-auto p-0">
        <table className="w-full min-w-[900px] text-sm">
          <thead className="border-b border-zinc-900/8 text-left text-[11px] uppercase tracking-[0.1em] text-zinc-500 dark:border-white/10">
            <tr>
              <th className="px-4 py-3">{t("housing.house")}</th>
              <th className="px-4 py-3">{t("housing.dueDate")}</th>
              <th className="px-4 py-3 text-right">{t("housing.due")}</th>
              <th className="px-4 py-3 text-right">{t("housing.paid")}</th>
              <th className="px-4 py-3 text-right">{t("housing.remaining")}</th>
              <th className="px-4 py-3">{t("housing.status")}</th>
              <th className="px-4 py-3">{t("housing.costBearer")}</th>
              <th className="px-4 py-3 text-right">{t("housing.actions")}</th>
            </tr>
          </thead>
          <tbody className="divide-y divide-zinc-900/5 dark:divide-white/8">
            {loading ? (
              <tr>
                <td colSpan={8} className="px-4 py-14 text-center text-sm text-zinc-500">
                  {t("common.loading")}
                </td>
              </tr>
            ) : rows.length === 0 ? (
              <tr>
                <td colSpan={8} className="px-4 py-14 text-center text-sm text-zinc-500">
                  {t("housing.noRent")}
                </td>
              </tr>
            ) : (
              rows.map((row) => (
                <tr key={row.id} className="text-zinc-800 dark:text-zinc-200">
                  <td className="px-4 py-3 font-medium">{row.house?.name ?? `#${row.house_id}`}</td>
                  <td className="px-4 py-3">
                    <span className="flex items-center gap-2">
                      {formatDate(row.due_date)}
                      {row.is_overdue && <Badge tone="red">{t("housing.overdue")}</Badge>}
                    </span>
                  </td>
                  <td className="px-4 py-3 text-right tabular-nums">
                    {formatMoney(row.rent_amount_due, row.currency)}
                  </td>
                  <td className="px-4 py-3 text-right tabular-nums">{formatMoney(row.paid_amount, row.currency)}</td>
                  <td className="px-4 py-3 text-right font-medium tabular-nums">
                    {formatMoney(row.remaining_amount, row.currency)}
                  </td>
                  <td className="px-4 py-3">
                    <Badge tone={statusTone(row.status)}>{t(`status.${row.status}`)}</Badge>
                  </td>
                  <td className="px-4 py-3">
                    <span className="flex flex-col">
                      <Badge tone={row.cost_bearer === "company" ? "gray" : "amber"}>
                        {t(`costBearer.${row.cost_bearer}`)}
                      </Badge>
                      {row.exception_reason && (
                        <span className="mt-1 text-xs text-zinc-500">{row.exception_reason}</span>
                      )}
                    </span>
                  </td>
                  <td className="px-4 py-3 text-right">
                    {row.status !== "paid" && (
                      <Button variant="secondary" className="h-8 px-3" onClick={() => setPaying(row)}>
                        {t("housing.recordPayment")}
                      </Button>
                    )}
                  </td>
                </tr>
              ))
            )}
          </tbody>
        </table>
      </Card>

      {preview && (
        <Modal open onClose={() => setPreview(null)} title={t("housing.generateRent")}>
          <div className="space-y-4">
            <p className="text-sm text-zinc-600 dark:text-zinc-300">
              {t("housing.willCreate", { count: preview.meta.created_count })}
              {preview.meta.already_existing_count > 0 &&
                ` · ${t("housing.alreadyExists", { count: preview.meta.already_existing_count })}`}
            </p>
            {preview.data.length > 0 && (
              <ul className="max-h-52 space-y-1 overflow-y-auto text-sm">
                {preview.data.map((row) => (
                  <li key={row.house_id} className="flex justify-between gap-4">
                    <span>{row.house?.name ?? `#${row.house_id}`}</span>
                    <span className="tabular-nums">{formatMoney(row.rent_amount_due, row.currency)}</span>
                  </li>
                ))}
              </ul>
            )}
            {preview.meta.skipped.length > 0 && (
              <div>
                <p className="mb-1 text-xs font-semibold uppercase tracking-wider text-zinc-500">
                  {t("housing.skipped")}
                </p>
                <ul className="space-y-1 text-sm text-zinc-500">
                  {preview.meta.skipped.map((row) => (
                    <li key={row.house_id} className="flex justify-between gap-4">
                      <span>{row.name}</span>
                      <span>{t(`housing.reason.${row.reason}`)}</span>
                    </li>
                  ))}
                </ul>
              </div>
            )}
            <div className="flex justify-end gap-2">
              <Button type="button" variant="secondary" onClick={() => setPreview(null)}>
                {t("common.cancel")}
              </Button>
              <Button onClick={() => void confirmGenerate()} disabled={preview.meta.created_count === 0}>
                {t("housing.confirmGenerate")}
              </Button>
            </div>
          </div>
        </Modal>
      )}

      {paying && (
        <PaymentModal
          title={t("housing.recordPayment")}
          subtitle={paying.house?.name ?? ""}
          remaining={paying.remaining_amount}
          currency={paying.currency}
          path={`/housing/rent/${paying.id}/payments`}
          onClose={() => setPaying(null)}
          onSaved={() => setPaying(null)}
        />
      )}
    </div>
  );
}

function BillsTab({ month }: { month: string }) {
  const { t } = useI18n();
  const [creating, setCreating] = useState(false);
  const [paying, setPaying] = useState<UtilityBill | null>(null);
  const [splitting, setSplitting] = useState<UtilityBill | null>(null);
  const [files, setFiles] = useState<UtilityBill | null>(null);
  const [error, setError] = useState<string | null>(null);

  const { data, loading } = useResource<{ data: UtilityBill[] }>(`/housing/bills?month=${month}`);
  const rows = data?.data ?? [];

  const { data: houseData } = useResource<{ data: House[] }>("/houses?active_only=1");
  const houses = houseData?.data ?? [];

  return (
    <div className="space-y-4">
      <div className="flex justify-end">
        <Button onClick={() => setCreating(true)}>{t("housing.newBill")}</Button>
      </div>
      {error && <p className="text-sm text-red-600">{error}</p>}

      <Card className="table-quiet overflow-x-auto p-0">
        <table className="w-full min-w-[1000px] text-sm">
          <thead className="border-b border-zinc-900/8 text-left text-[11px] uppercase tracking-[0.1em] text-zinc-500 dark:border-white/10">
            <tr>
              <th className="px-4 py-3">{t("housing.house")}</th>
              <th className="px-4 py-3">{t("housing.billType")}</th>
              <th className="px-4 py-3">{t("housing.dueDate")}</th>
              <th className="px-4 py-3 text-right">{t("housing.amount")}</th>
              <th className="px-4 py-3 text-right">{t("housing.remaining")}</th>
              <th className="px-4 py-3">{t("housing.status")}</th>
              <th className="px-4 py-3">{t("housing.costBearer")}</th>
              <th className="px-4 py-3 text-right">{t("housing.actions")}</th>
            </tr>
          </thead>
          <tbody className="divide-y divide-zinc-900/5 dark:divide-white/8">
            {loading ? (
              <tr>
                <td colSpan={8} className="px-4 py-14 text-center text-sm text-zinc-500">
                  {t("common.loading")}
                </td>
              </tr>
            ) : rows.length === 0 ? (
              <tr>
                <td colSpan={8} className="px-4 py-14 text-center text-sm text-zinc-500">
                  {t("housing.noBills")}
                </td>
              </tr>
            ) : (
              rows.map((row) => (
                <tr key={row.id} className="text-zinc-800 dark:text-zinc-200">
                  <td className="px-4 py-3 font-medium">{row.house?.name ?? `#${row.house_id}`}</td>
                  <td className="px-4 py-3">{t(`billType.${row.bill_type}`)}</td>
                  <td className="px-4 py-3">
                    <span className="flex items-center gap-2">
                      {formatDate(row.due_date)}
                      {row.is_overdue && <Badge tone="red">{t("housing.overdue")}</Badge>}
                    </span>
                  </td>
                  <td className="px-4 py-3 text-right tabular-nums">{formatMoney(row.amount, row.currency)}</td>
                  <td className="px-4 py-3 text-right font-medium tabular-nums">
                    {formatMoney(row.remaining_amount, row.currency)}
                  </td>
                  <td className="px-4 py-3">
                    <Badge tone={statusTone(row.status)}>{t(`status.${row.status}`)}</Badge>
                  </td>
                  <td className="px-4 py-3">
                    <span className="flex flex-col">
                      <Badge tone={row.cost_bearer === "company" ? "gray" : "amber"}>
                        {t(`costBearer.${row.cost_bearer}`)}
                      </Badge>
                      {row.exception_reason && (
                        <span className="mt-1 text-xs text-zinc-500">{row.exception_reason}</span>
                      )}
                    </span>
                  </td>
                  <td className="px-4 py-3 text-right">
                    <div className="flex flex-wrap justify-end gap-2">
                      {row.status !== "paid" && (
                        <Button variant="secondary" className="h-8 px-3" onClick={() => setPaying(row)}>
                          {t("housing.recordPayment")}
                        </Button>
                      )}
                      <Button variant="secondary" className="h-8 px-3" onClick={() => setFiles(row)}>
                        {t("housing.scan")}
                        {row.attachment_count ? ` (${row.attachment_count})` : ""}
                      </Button>
                      <Button variant="secondary" className="h-8 px-3" onClick={() => setSplitting(row)}>
                        {t("housing.split")}
                      </Button>
                    </div>
                  </td>
                </tr>
              ))
            )}
          </tbody>
        </table>
      </Card>

      {creating && (
        <BillModal
          houses={houses}
          month={month}
          onClose={() => setCreating(false)}
        />
      )}
      {paying && (
        <PaymentModal
          title={t("housing.recordPayment")}
          subtitle={`${paying.house?.name ?? ""} · ${t(`billType.${paying.bill_type}`)}`}
          remaining={paying.remaining_amount}
          currency={paying.currency}
          path={`/housing/bills/${paying.id}/payments`}
          onClose={() => setPaying(null)}
          onSaved={() => setPaying(null)}
        />
      )}
      {splitting && (
        <SplitModal
          bill={splitting}
          onClose={() => setSplitting(null)}
          onSaved={() => setSplitting(null)}
          onError={setError}
        />
      )}
      {files && (
        <AttachmentsModal
          title={`${t("housing.scan")} — ${files.house?.name ?? ""}`}
          basePath={`/housing/bills/${files.id}/attachments`}
          defaultKind="invoice"
          onClose={() => setFiles(null)}
        />
      )}
    </div>
  );
}

function BillModal({
  houses,
  month,
  onClose,
}: {
  houses: House[];
  month: string;
  onClose: () => void;
}) {
  const { t } = useI18n();
  const [houseId, setHouseId] = useState("");
  const [billType, setBillType] = useState<string>("electricity");
  const [period, setPeriod] = useState(month);
  const [amount, setAmount] = useState("");
  const [dueDate, setDueDate] = useState("");
  const [notes, setNotes] = useState("");
  const [error, setError] = useState<string | null>(null);
  const [saving, setSaving] = useState(false);

  async function submit(e: React.FormEvent) {
    e.preventDefault();
    setSaving(true);
    setError(null);
    try {
      await apiFetch("/housing/bills", {
        method: "POST",
        json: {
          house_id: Number(houseId),
          bill_type: billType,
          billing_period: period,
          amount: Number(amount),
          due_date: dueDate || null,
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

  const label = "mb-1.5 block px-4 text-[11px] font-medium uppercase tracking-[0.08em] text-zinc-500";

  return (
    <Modal open onClose={onClose} title={t("housing.newBill")}>
      <form onSubmit={submit} className="space-y-4">
        <p className="text-xs text-zinc-500">{t("housing.companyCostHint")}</p>
        <div className="grid grid-cols-2 gap-4">
          <div>
            <label className={label}>{t("housing.house")}</label>
            <Select value={houseId} onChange={(e) => setHouseId(e.target.value)} required>
              <option value="">{t("housing.selectHouse")}</option>
              {houses.map((house) => (
                <option key={house.id} value={house.id}>
                  {house.name}
                </option>
              ))}
            </Select>
          </div>
          <div>
            <label className={label}>{t("housing.billType")}</label>
            <Select value={billType} onChange={(e) => setBillType(e.target.value)}>
              {BILL_TYPES.map((value) => (
                <option key={value} value={value}>
                  {t(`billType.${value}`)}
                </option>
              ))}
            </Select>
          </div>
          <div>
            <label className={label}>{t("housing.billingPeriod")}</label>
            <Input type="month" value={period} onChange={(e) => setPeriod(e.target.value)} required />
          </div>
          <div>
            <label className={label}>{t("housing.amount")}</label>
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
            <label className={label}>{t("housing.dueDate")}</label>
            <Input type="date" value={dueDate} onChange={(e) => setDueDate(e.target.value)} />
          </div>
          <div>
            <label className={label}>{t("housing.notes")}</label>
            <Input value={notes} onChange={(e) => setNotes(e.target.value)} />
          </div>
        </div>

        {error && <p className="text-sm text-red-600">{error}</p>}
        <div className="flex justify-end gap-2">
          <Button type="button" variant="secondary" onClick={onClose}>
            {t("common.cancel")}
          </Button>
          <Button type="submit" disabled={saving || !houseId}>
            {saving ? t("common.saving") : t("common.save")}
          </Button>
        </div>
      </form>
    </Modal>
  );
}

/** Charging a bill to the occupants is an exception — the reason is required. */
function SplitModal({
  bill,
  onClose,
  onSaved,
  onError,
}: {
  bill: UtilityBill;
  onClose: () => void;
  onSaved: () => void;
  onError: (message: string) => void;
}) {
  const { t } = useI18n();
  const [reason, setReason] = useState("");
  const [amount, setAmount] = useState(String(bill.amount));
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState<string | null>(null);

  async function submit(e: React.FormEvent) {
    e.preventDefault();
    setSaving(true);
    setError(null);
    try {
      await apiFetch(`/housing/bills/${bill.id}/split`, {
        method: "POST",
        json: { reason: reason.trim(), amount: Number(amount) },
      });
      // Only closes the modal — the lists behind it are revalidated by the
      // markMutated() that the POST above already triggered.
      onSaved();
    } catch (err) {
      const message = err instanceof ApiError ? err.message : "Error";
      setError(message);
      onError(message);
    } finally {
      setSaving(false);
    }
  }

  const label = "mb-1.5 block px-4 text-[11px] font-medium uppercase tracking-[0.08em] text-zinc-500";

  return (
    <Modal open onClose={onClose} title={t("housing.splitTitle")}>
      <form onSubmit={submit} className="space-y-4">
        <p className="text-sm text-amber-700 dark:text-amber-400">{t("housing.splitWarning")}</p>
        <div className="grid grid-cols-2 gap-4">
          <div>
            <label className={label}>{t("housing.amount")}</label>
            <Input
              type="number"
              step="0.01"
              min="0"
              value={amount}
              onChange={(e) => setAmount(e.target.value)}
              required
            />
          </div>
        </div>
        <div>
          <label className={label}>{t("housing.splitReason")}</label>
          <Input value={reason} onChange={(e) => setReason(e.target.value)} required />
        </div>
        {error && <p className="text-sm text-red-600">{error}</p>}
        <div className="flex justify-end gap-2">
          <Button type="button" variant="secondary" onClick={onClose}>
            {t("common.cancel")}
          </Button>
          <Button type="submit" disabled={saving || reason.trim() === ""}>
            {saving ? t("common.saving") : t("housing.split")}
          </Button>
        </div>
      </form>
    </Modal>
  );
}

function PaymentModal({
  title,
  subtitle,
  remaining,
  currency,
  path,
  onClose,
  onSaved,
}: {
  title: string;
  subtitle: string;
  remaining: number;
  currency: string;
  path: string;
  onClose: () => void;
  onSaved: () => void;
}) {
  const { t } = useI18n();
  const [amount, setAmount] = useState(String(remaining));
  const [paymentDate, setPaymentDate] = useState(todayISO());
  const [method, setMethod] = useState<string>("cash");
  const [error, setError] = useState<string | null>(null);
  const [saving, setSaving] = useState(false);

  async function submit(e: React.FormEvent) {
    e.preventDefault();
    setSaving(true);
    setError(null);
    try {
      await apiFetch(path, {
        method: "POST",
        json: { amount: Number(amount), payment_date: paymentDate, method },
      });
      // Only closes the modal — the lists behind it are revalidated by the
      // markMutated() that the POST above already triggered.
      onSaved();
    } catch (err) {
      setError(err instanceof ApiError ? err.message : "Error");
    } finally {
      setSaving(false);
    }
  }

  const label = "mb-1.5 block px-4 text-[11px] font-medium uppercase tracking-[0.08em] text-zinc-500";

  return (
    <Modal open onClose={onClose} title={title}>
      <form onSubmit={submit} className="space-y-4">
        <p className="text-sm text-zinc-500">
          {subtitle} · {formatMoney(remaining, currency)}
        </p>
        <div className="grid grid-cols-2 gap-4">
          <div>
            <label className={label}>{t("housing.amount")}</label>
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
            <label className={label}>{t("housing.paymentDate")}</label>
            <Input type="date" value={paymentDate} onChange={(e) => setPaymentDate(e.target.value)} required />
          </div>
        </div>
        <div>
          <label className={label}>{t("housing.method")}</label>
          <Select value={method} onChange={(e) => setMethod(e.target.value)}>
            {METHODS.map((value) => (
              <option key={value} value={value}>
                {t(`method.${value}`)}
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
            {saving ? t("common.saving") : title}
          </Button>
        </div>
      </form>
    </Modal>
  );
}

function DeductionsTab({ month }: { month: string }) {
  const { t } = useI18n();
  const [creating, setCreating] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const { data, loading } = useResource<{ data: HousingDeduction[] }>(
    `/housing/deductions?month=${month}`,
  );
  const rows = data?.data ?? [];

  const { data: houseData } = useResource<{ data: House[] }>("/houses");
  const houses = houseData?.data ?? [];

  const { options: employees } = useEmployeeOptions();

  async function remove(row: HousingDeduction) {
    if (!window.confirm(t("housing.removeDeductionConfirm"))) return;
    try {
      await apiFetch(`/housing/deductions/${row.id}`, { method: "DELETE" });
    } catch (err) {
      setError(err instanceof ApiError ? err.message : "Error");
    }
  }

  return (
    <div className="space-y-4">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <p className="text-sm text-zinc-500">{t("housing.deductionsHint")}</p>
        <Button onClick={() => setCreating(true)}>{t("housing.newDeduction")}</Button>
      </div>
      {error && <p className="text-sm text-red-600">{error}</p>}

      <Card className="table-quiet overflow-x-auto p-0">
        <table className="w-full min-w-[920px] text-sm">
          <thead className="border-b border-zinc-900/8 text-left text-[11px] uppercase tracking-[0.1em] text-zinc-500 dark:border-white/10">
            <tr>
              <th className="px-4 py-3">{t("housing.worker")}</th>
              <th className="px-4 py-3">{t("housing.house")}</th>
              <th className="px-4 py-3 text-right">{t("housing.rentShare")}</th>
              <th className="px-4 py-3 text-right">{t("housing.utilityShare")}</th>
              <th className="px-4 py-3 text-right">{t("housing.deducted")}</th>
              <th className="px-4 py-3 text-right">{t("housing.remaining")}</th>
              <th className="px-4 py-3">{t("housing.reason")}</th>
              <th className="px-4 py-3 text-right">{t("housing.actions")}</th>
            </tr>
          </thead>
          <tbody className="divide-y divide-zinc-900/5 dark:divide-white/8">
            {loading ? (
              <tr>
                <td colSpan={8} className="px-4 py-14 text-center text-sm text-zinc-500">
                  {t("common.loading")}
                </td>
              </tr>
            ) : rows.length === 0 ? (
              <tr>
                <td colSpan={8} className="px-4 py-14 text-center text-sm text-zinc-500">
                  {t("housing.noDeductions")}
                </td>
              </tr>
            ) : (
              rows.map((row) => (
                <tr key={row.id} className="text-zinc-800 dark:text-zinc-200">
                  <td className="px-4 py-3 font-medium">{row.employee?.full_name ?? `#${row.employee_id}`}</td>
                  <td className="px-4 py-3">{row.house?.name ?? `#${row.house_id}`}</td>
                  <td className="px-4 py-3 text-right tabular-nums">{formatMoney(row.rent_share, row.currency)}</td>
                  <td className="px-4 py-3 text-right tabular-nums">
                    {formatMoney(row.utility_share, row.currency)}
                  </td>
                  <td className="px-4 py-3 text-right tabular-nums">
                    {formatMoney(row.amount_deducted, row.currency)}
                  </td>
                  <td className="px-4 py-3 text-right font-medium tabular-nums">
                    {formatMoney(row.remaining_amount, row.currency)}
                  </td>
                  <td className="max-w-[16rem] px-4 py-3 text-xs text-zinc-600 dark:text-zinc-300">{row.reason}</td>
                  <td className="px-4 py-3 text-right">
                    <Button variant="secondary" className="h-8 px-3" onClick={() => void remove(row)}>
                      {t("housing.remove")}
                    </Button>
                  </td>
                </tr>
              ))
            )}
          </tbody>
        </table>
      </Card>

      {creating && (
        <DeductionModal
          houses={houses}
          employees={employees}
          month={month}
          onClose={() => setCreating(false)}
        />
      )}
    </div>
  );
}

function DeductionModal({
  houses,
  employees,
  month,
  onClose,
}: {
  houses: House[];
  employees: EmployeeOption[];
  month: string;
  onClose: () => void;
}) {
  const { t } = useI18n();
  const [employeeId, setEmployeeId] = useState("");
  const [houseId, setHouseId] = useState("");
  const [rentShare, setRentShare] = useState("");
  const [utilityShare, setUtilityShare] = useState("");
  const [deducted, setDeducted] = useState("");
  const [reason, setReason] = useState("");
  const [error, setError] = useState<string | null>(null);
  const [saving, setSaving] = useState(false);

  async function submit(e: React.FormEvent) {
    e.preventDefault();
    setSaving(true);
    setError(null);
    try {
      await apiFetch("/housing/deductions", {
        method: "POST",
        json: {
          employee_id: Number(employeeId),
          house_id: Number(houseId),
          month,
          rent_share: rentShare === "" ? 0 : Number(rentShare),
          utility_share: utilityShare === "" ? 0 : Number(utilityShare),
          amount_deducted: deducted === "" ? 0 : Number(deducted),
          reason: reason.trim(),
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

  return (
    <Modal open onClose={onClose} title={t("housing.newDeduction")}>
      <form onSubmit={submit} className="space-y-4">
        <p className="text-sm text-amber-700 dark:text-amber-400">{t("housing.deductionWarning")}</p>
        <div className="grid grid-cols-2 gap-4">
          <div>
            <label className={label}>{t("housing.worker")}</label>
            <Select value={employeeId} onChange={(e) => setEmployeeId(e.target.value)} required>
              <option value="">{t("housing.selectWorker")}</option>
              {employees.map((employee) => (
                <option key={employee.id} value={employee.id}>
                  {employee.full_name}
                </option>
              ))}
            </Select>
          </div>
          <div>
            <label className={label}>{t("housing.house")}</label>
            <Select value={houseId} onChange={(e) => setHouseId(e.target.value)} required>
              <option value="">{t("housing.selectHouse")}</option>
              {houses.map((house) => (
                <option key={house.id} value={house.id}>
                  {house.name}
                </option>
              ))}
            </Select>
          </div>
          <div>
            <label className={label}>{t("housing.rentShare")}</label>
            <Input
              type="number"
              step="0.01"
              min="0"
              value={rentShare}
              onChange={(e) => setRentShare(e.target.value)}
            />
          </div>
          <div>
            <label className={label}>{t("housing.utilityShare")}</label>
            <Input
              type="number"
              step="0.01"
              min="0"
              value={utilityShare}
              onChange={(e) => setUtilityShare(e.target.value)}
            />
          </div>
          <div>
            <label className={label}>{t("housing.deducted")}</label>
            <Input
              type="number"
              step="0.01"
              min="0"
              value={deducted}
              onChange={(e) => setDeducted(e.target.value)}
            />
          </div>
        </div>
        <div>
          <label className={label}>{t("housing.reason")}</label>
          <Input value={reason} onChange={(e) => setReason(e.target.value)} required />
        </div>
        {error && <p className="text-sm text-red-600">{error}</p>}
        <div className="flex justify-end gap-2">
          <Button type="button" variant="secondary" onClick={onClose}>
            {t("common.cancel")}
          </Button>
          <Button type="submit" disabled={saving || !employeeId || !houseId || reason.trim() === ""}>
            {saving ? t("common.saving") : t("common.save")}
          </Button>
        </div>
      </form>
    </Modal>
  );
}
