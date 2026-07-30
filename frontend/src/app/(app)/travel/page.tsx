"use client";

import { useCallback, useEffect, useState } from "react";
import { ApiError, apiFetch, errorMessage } from "@/lib/api";
import { useAuth } from "@/lib/auth/context";
import { useI18n } from "@/lib/i18n/context";
import { formatDate, formatMoney, todayISO } from "@/lib/format";
import { useDebouncedValue } from "@/lib/use-debounced-value";
import { AttachmentsModal } from "@/components/attachments-modal";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Card } from "@/components/ui/card";
import { Input } from "@/components/ui/input";
import { Modal } from "@/components/ui/modal";
import { Select } from "@/components/ui/select";

interface EmployeeOption {
  id: number;
  full_name: string;
}

interface FlightTicket {
  id: number;
  employee_id: number | null;
  employee?: { id: number; full_name: string };
  passenger_name: string | null;
  traveller_name: string | null;
  ticket_date: string | null;
  direction: string;
  route: string | null;
  airline: string | null;
  reference: string | null;
  currency: string;
  amount: number;
  exchange_rate: number | null;
  exchange_rate_date: string | null;
  amount_eur: number;
  paid_amount: number;
  remaining_amount: number;
  status: string;
  cost_status: string;
  attachment_count?: number;
  notes: string | null;
}

interface TravelExpense {
  id: number;
  employee_id: number | null;
  employee?: { id: number; full_name: string };
  person_name: string | null;
  traveller_name: string | null;
  expense_date: string | null;
  period_month: string | null;
  expense_type: string;
  flight_ticket_id: number | null;
  currency: string;
  amount: number;
  exchange_rate: number | null;
  amount_eur: number;
  paid_amount: number;
  remaining_amount: number;
  status: string;
  cost_status: string;
  attachment_count?: number;
  notes: string | null;
}

interface AssistancePayment {
  id: number;
  employee_id: number | null;
  employee?: { id: number; full_name: string };
  person_name: string | null;
  recipient_name: string | null;
  payment_date: string | null;
  entitlement_year: number;
  currency: string;
  amount: number;
  exchange_rate: number | null;
  amount_eur: number;
  method: string | null;
  reason: string | null;
  notes: string | null;
}

interface AssistanceSummary {
  year: number;
  entitlement: number;
  rows: {
    employee_id: number | null;
    full_name: string | null;
    paid: number;
    payable: number | null;
    payment_count: number;
  }[];
  totals: { paid: number; payable: number; workers_paid: number; payment_count: number };
}

interface Summary {
  month: string;
  totals: {
    tickets_eur: number;
    tickets_unpaid_eur: number;
    ticket_count: number;
    expenses_eur: number;
    expenses_unpaid_eur: number;
    expense_count: number;
    social_assistance_eur: number;
    social_assistance_count: number;
    travel_cost_eur: number;
  };
  alerts: {
    unwritten_tickets: {
      flight_ticket_id: number;
      traveller_name: string | null;
      ticket_date: string;
      amount_eur: number;
    }[];
    unwritten_tickets_eur: number;
    unwritten_expenses_eur: number;
  };
}

const DIRECTIONS = ["arrival", "departure", "round_trip"] as const;
const COST_STATUSES = ["not_written", "written"] as const;
const EXPENSE_TYPES = ["car", "flight", "bus", "taxi", "fuel", "accommodation", "meal", "other"] as const;
const METHODS = ["cash", "nlb", "lovcen", "other"] as const;
const CURRENCIES = ["TRY", "EUR"] as const;
const TABS = ["tickets", "expenses", "assistance"] as const;

type Tab = (typeof TABS)[number];

function currentMonth(): string {
  return todayISO().slice(0, 7);
}

function statusTone(status: string): "gray" | "amber" | "green" {
  if (status === "paid") return "green";
  if (status === "partial") return "amber";
  return "gray";
}

const labelClass = "mb-1 block text-sm font-medium text-zinc-700 dark:text-zinc-300";

export default function TravelPage() {
  const { t } = useI18n();
  const [tab, setTab] = useState<Tab>("tickets");
  const [month, setMonth] = useState(currentMonth);
  const [summary, setSummary] = useState<Summary | null>(null);
  const [error, setError] = useState<string | null>(null);

  const loadSummary = useCallback(async () => {
    try {
      const res = await apiFetch<{ data: Summary }>(`/travel/summary?month=${month}`);
      setSummary(res.data);
    } catch (err) {
      setError(err instanceof ApiError ? err.message : "Error");
    }
  }, [month]);

  useEffect(() => {
    void loadSummary();
  }, [loadSummary]);

  return (
    <div className="space-y-5">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <h1 className="text-2xl font-semibold text-zinc-900 dark:text-zinc-50">{t("travel.title")}</h1>
        <Input className="w-[10rem]" type="month" value={month} onChange={(e) => setMonth(e.target.value)} />
      </div>

      <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
        <Tile label={t("travel.travelCost")} value={formatMoney(summary?.totals.travel_cost_eur ?? 0)} />
        <Tile
          label={t("travel.ticketsTotal")}
          value={formatMoney(summary?.totals.tickets_eur ?? 0)}
          hint={`${t("travel.unpaid")}: ${formatMoney(summary?.totals.tickets_unpaid_eur ?? 0)}`}
        />
        <Tile
          label={t("travel.unwritten")}
          value={formatMoney(summary?.alerts.unwritten_tickets_eur ?? 0)}
          hint={t("travel.unwrittenHint", { count: summary?.alerts.unwritten_tickets.length ?? 0 })}
        />
        <Tile
          label={t("travel.assistanceTotal")}
          value={formatMoney(summary?.totals.social_assistance_eur ?? 0)}
          hint={t("travel.assistanceHint", { count: summary?.totals.social_assistance_count ?? 0 })}
        />
      </div>

      {error && <p className="text-sm text-red-600">{error}</p>}

      <div className="flex flex-wrap gap-2 border-b border-zinc-200 dark:border-zinc-800">
        {TABS.map((value) => (
          <button
            key={value}
            onClick={() => setTab(value)}
            className={
              tab === value
                ? "-mb-px border-b-2 border-zinc-900 px-3 py-2 text-sm font-medium text-zinc-900 dark:border-zinc-100 dark:text-zinc-50"
                : "-mb-px border-b-2 border-transparent px-3 py-2 text-sm text-zinc-500 hover:text-zinc-800 dark:hover:text-zinc-200"
            }
          >
            {t(`travel.tab.${value}`)}
          </button>
        ))}
      </div>

      {tab === "tickets" && <TicketsTab month={month} onChanged={loadSummary} />}
      {tab === "expenses" && <ExpensesTab month={month} onChanged={loadSummary} />}
      {tab === "assistance" && <AssistanceTab month={month} onChanged={loadSummary} />}
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

/** The workers list is shared by all three tabs' forms. */
function useEmployees(): EmployeeOption[] {
  const [employees, setEmployees] = useState<EmployeeOption[]>([]);

  useEffect(() => {
    void apiFetch<{ data: EmployeeOption[] }>("/employees?status=active&per_page=200")
      .then((res) => setEmployees(res.data))
      .catch(() => setEmployees([]));
  }, []);

  return employees;
}

function TicketsTab({ month, onChanged }: { month: string; onChanged: () => Promise<void> }) {
  const { t } = useI18n();
  const employees = useEmployees();
  const [tickets, setTickets] = useState<FlightTicket[]>([]);
  const [loading, setLoading] = useState(true);
  const [creating, setCreating] = useState(false);
  const [editing, setEditing] = useState<FlightTicket | null>(null);
  const [paying, setPaying] = useState<FlightTicket | null>(null);
  const [files, setFiles] = useState<FlightTicket | null>(null);
  const [onlyUnwritten, setOnlyUnwritten] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const load = useCallback(async () => {
    setLoading(true);
    try {
      const query = new URLSearchParams({ month });
      if (onlyUnwritten) query.set("unwritten", "1");
      const res = await apiFetch<{ data: FlightTicket[] }>(`/travel/tickets?${query.toString()}`);
      setTickets(res.data);
    } finally {
      setLoading(false);
    }
  }, [month, onlyUnwritten]);

  useEffect(() => {
    void load();
  }, [load]);

  async function remove(ticket: FlightTicket) {
    if (!window.confirm(t("travel.removeTicketConfirm"))) return;
    try {
      await apiFetch(`/travel/tickets/${ticket.id}`, { method: "DELETE" });
      await load();
      await onChanged();
    } catch (err) {
      setError(err instanceof ApiError ? err.message : "Error");
    }
  }

  return (
    <div className="space-y-4">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <label className="flex items-center gap-2 text-sm text-zinc-600 dark:text-zinc-300">
          <input
            type="checkbox"
            checked={onlyUnwritten}
            onChange={(e) => setOnlyUnwritten(e.target.checked)}
            className="h-4 w-4 rounded border-zinc-300 dark:border-zinc-700"
          />
          {t("costStatus.not_written")}
        </label>
        <Button onClick={() => setCreating(true)}>{t("travel.newTicket")}</Button>
      </div>
      {error && <p className="text-sm text-red-600">{error}</p>}

      <Card className="overflow-x-auto p-0">
        <table className="w-full min-w-[1040px] text-sm">
          <thead className="border-b border-zinc-200 text-left text-xs uppercase tracking-wider text-zinc-500 dark:border-zinc-800">
            <tr>
              <th className="px-4 py-3">{t("travel.traveller")}</th>
              <th className="px-4 py-3">{t("travel.ticketDate")}</th>
              <th className="px-4 py-3">{t("travel.direction")}</th>
              <th className="px-4 py-3 text-right">{t("travel.amount")}</th>
              <th className="px-4 py-3 text-right">{t("travel.amountEur")}</th>
              <th className="px-4 py-3 text-right">{t("travel.remaining")}</th>
              <th className="px-4 py-3">{t("travel.status")}</th>
              <th className="px-4 py-3">{t("travel.costStatus")}</th>
              <th className="px-4 py-3 text-right">{t("travel.actions")}</th>
            </tr>
          </thead>
          <tbody className="divide-y divide-zinc-100 dark:divide-zinc-800">
            {loading ? (
              <tr>
                <td colSpan={9} className="px-4 py-8 text-center text-zinc-500">
                  {t("common.loading")}
                </td>
              </tr>
            ) : tickets.length === 0 ? (
              <tr>
                <td colSpan={9} className="px-4 py-8 text-center text-zinc-500">
                  {t("travel.noTickets")}
                </td>
              </tr>
            ) : (
              tickets.map((ticket) => (
                <tr key={ticket.id} className="text-zinc-800 dark:text-zinc-200">
                  <td className="px-4 py-3">
                    <span className="flex flex-col">
                      <span className="font-medium">{ticket.traveller_name ?? "—"}</span>
                      {(ticket.route || ticket.airline) && (
                        <span className="text-xs text-zinc-500">
                          {[ticket.route, ticket.airline].filter(Boolean).join(" · ")}
                        </span>
                      )}
                    </span>
                  </td>
                  <td className="px-4 py-3">{formatDate(ticket.ticket_date)}</td>
                  <td className="px-4 py-3">{t(`direction.${ticket.direction}`)}</td>
                  <td className="px-4 py-3 text-right tabular-nums">
                    <span className="flex flex-col">
                      <span>{formatMoney(ticket.amount, ticket.currency)}</span>
                      {ticket.exchange_rate !== null && (
                        <span className="text-xs text-zinc-500">
                          {t("travel.rateUsed", { rate: ticket.exchange_rate })}
                        </span>
                      )}
                    </span>
                  </td>
                  <td className="px-4 py-3 text-right tabular-nums">{formatMoney(ticket.amount_eur)}</td>
                  <td className="px-4 py-3 text-right tabular-nums">{formatMoney(ticket.remaining_amount)}</td>
                  <td className="px-4 py-3">
                    <Badge tone={statusTone(ticket.status)}>{t(`status.${ticket.status}`)}</Badge>
                  </td>
                  <td className="px-4 py-3">
                    <Badge tone={ticket.cost_status === "written" ? "green" : "amber"}>
                      {t(`costStatus.${ticket.cost_status}`)}
                    </Badge>
                  </td>
                  <td className="px-4 py-3 text-right">
                    <div className="flex flex-wrap justify-end gap-2">
                      {ticket.remaining_amount > 0 && (
                        <Button variant="secondary" className="h-8 px-3" onClick={() => setPaying(ticket)}>
                          {t("travel.recordPayment")}
                        </Button>
                      )}
                      <Button variant="secondary" className="h-8 px-3" onClick={() => setFiles(ticket)}>
                        {t("travel.files")}
                        {ticket.attachment_count ? ` (${ticket.attachment_count})` : ""}
                      </Button>
                      <Button variant="secondary" className="h-8 px-3" onClick={() => setEditing(ticket)}>
                        {t("travel.edit")}
                      </Button>
                      <Button variant="secondary" className="h-8 px-3" onClick={() => void remove(ticket)}>
                        {t("travel.remove")}
                      </Button>
                    </div>
                  </td>
                </tr>
              ))
            )}
          </tbody>
        </table>
      </Card>

      {(creating || editing) && (
        <TicketModal
          ticket={editing ?? undefined}
          employees={employees}
          onClose={() => {
            setCreating(false);
            setEditing(null);
          }}
          onSaved={async () => {
            await load();
            await onChanged();
          }}
        />
      )}
      {paying && (
        <PaymentModal
          title={t("travel.recordPayment")}
          endpoint={`/travel/tickets/${paying.id}/payments`}
          remaining={paying.remaining_amount}
          onClose={() => setPaying(null)}
          onSaved={async () => {
            await load();
            await onChanged();
          }}
        />
      )}
      {files && (
        <AttachmentsModal
          title={`${t("travel.files")} — ${files.traveller_name ?? ""}`}
          basePath={`/travel/tickets/${files.id}/attachments`}
          defaultKind="invoice"
          onClose={() => setFiles(null)}
          onChanged={load}
        />
      )}
    </div>
  );
}

function TicketModal({
  ticket,
  employees,
  onClose,
  onSaved,
}: {
  ticket?: FlightTicket;
  employees: EmployeeOption[];
  onClose: () => void;
  onSaved: () => Promise<void>;
}) {
  const { t } = useI18n();
  const [employeeId, setEmployeeId] = useState(ticket?.employee_id ? String(ticket.employee_id) : "");
  const [passengerName, setPassengerName] = useState(ticket?.passenger_name ?? "");
  const [ticketDate, setTicketDate] = useState(ticket?.ticket_date ?? todayISO());
  const [direction, setDirection] = useState(ticket?.direction ?? "departure");
  const [route, setRoute] = useState(ticket?.route ?? "");
  const [airline, setAirline] = useState(ticket?.airline ?? "");
  const [reference, setReference] = useState(ticket?.reference ?? "");
  const [currency, setCurrency] = useState(ticket?.currency ?? "TRY");
  const [amount, setAmount] = useState(ticket ? String(ticket.amount) : "");
  const [rate, setRate] = useState(ticket?.exchange_rate == null ? "" : String(ticket.exchange_rate));
  const [costStatus, setCostStatus] = useState(ticket?.cost_status ?? "not_written");
  const [notes, setNotes] = useState(ticket?.notes ?? "");
  const [error, setError] = useState<string | null>(null);
  const [saving, setSaving] = useState(false);

  async function submit(e: React.FormEvent) {
    e.preventDefault();
    setSaving(true);
    setError(null);
    try {
      await apiFetch(ticket ? `/travel/tickets/${ticket.id}` : "/travel/tickets", {
        method: ticket ? "PUT" : "POST",
        json: {
          employee_id: employeeId ? Number(employeeId) : null,
          passenger_name: passengerName.trim() || null,
          ticket_date: ticketDate,
          direction,
          route: route.trim() || null,
          airline: airline.trim() || null,
          reference: reference.trim() || null,
          currency,
          amount: Number(amount),
          // Empty means "use the stored rate for the date".
          exchange_rate: rate === "" ? null : Number(rate),
          cost_status: costStatus,
          notes: notes.trim() || null,
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

  return (
    <Modal open onClose={onClose} title={ticket ? t("travel.editTicket") : t("travel.newTicket")}>
      <form onSubmit={submit} className="space-y-4">
        <div className="grid grid-cols-2 gap-4">
          <div>
            <label className={labelClass}>{t("travel.worker")}</label>
            <Select value={employeeId} onChange={(e) => setEmployeeId(e.target.value)}>
              <option value="">{t("travel.noWorkerRecord")}</option>
              {employees.map((employee) => (
                <option key={employee.id} value={employee.id}>
                  {employee.full_name}
                </option>
              ))}
            </Select>
          </div>
          <div>
            <label className={labelClass}>{t("travel.passengerName")}</label>
            <Input
              value={passengerName}
              onChange={(e) => setPassengerName(e.target.value)}
              required={employeeId === ""}
            />
          </div>
          <div>
            <label className={labelClass}>{t("travel.ticketDate")}</label>
            <Input type="date" value={ticketDate} onChange={(e) => setTicketDate(e.target.value)} required />
          </div>
          <div>
            <label className={labelClass}>{t("travel.direction")}</label>
            <Select value={direction} onChange={(e) => setDirection(e.target.value)}>
              {DIRECTIONS.map((value) => (
                <option key={value} value={value}>
                  {t(`direction.${value}`)}
                </option>
              ))}
            </Select>
          </div>
          <div>
            <label className={labelClass}>{t("travel.currency")}</label>
            <Select value={currency} onChange={(e) => setCurrency(e.target.value)}>
              {CURRENCIES.map((value) => (
                <option key={value} value={value}>
                  {value}
                </option>
              ))}
            </Select>
          </div>
          <div>
            <label className={labelClass}>{t("travel.amount")}</label>
            <Input
              type="number"
              step="0.01"
              min="0"
              value={amount}
              onChange={(e) => setAmount(e.target.value)}
              required
            />
          </div>
          <div className="col-span-2">
            <label className={labelClass}>{t("travel.exchangeRate")}</label>
            <Input
              type="number"
              step="0.0001"
              min="0"
              value={rate}
              onChange={(e) => setRate(e.target.value)}
              disabled={currency === "EUR"}
            />
            <p className="mt-1 text-xs text-zinc-500">{t("travel.rateHint")}</p>
          </div>
          <div>
            <label className={labelClass}>{t("travel.route")}</label>
            <Input value={route} onChange={(e) => setRoute(e.target.value)} placeholder="IST-TGD" />
          </div>
          <div>
            <label className={labelClass}>{t("travel.airline")}</label>
            <Input value={airline} onChange={(e) => setAirline(e.target.value)} />
          </div>
          <div>
            <label className={labelClass}>{t("travel.reference")}</label>
            <Input value={reference} onChange={(e) => setReference(e.target.value)} />
          </div>
          <div>
            <label className={labelClass}>{t("travel.costStatus")}</label>
            <Select value={costStatus} onChange={(e) => setCostStatus(e.target.value)}>
              {COST_STATUSES.map((value) => (
                <option key={value} value={value}>
                  {t(`costStatus.${value}`)}
                </option>
              ))}
            </Select>
          </div>
          <div className="col-span-2">
            <label className={labelClass}>{t("travel.notes")}</label>
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

function ExpensesTab({ month, onChanged }: { month: string; onChanged: () => Promise<void> }) {
  const { t } = useI18n();
  const employees = useEmployees();
  const [expenses, setExpenses] = useState<TravelExpense[]>([]);
  const [loading, setLoading] = useState(true);
  const [creating, setCreating] = useState(false);
  const [editing, setEditing] = useState<TravelExpense | null>(null);
  const [paying, setPaying] = useState<TravelExpense | null>(null);
  const [files, setFiles] = useState<TravelExpense | null>(null);
  const [error, setError] = useState<string | null>(null);

  const load = useCallback(async () => {
    setLoading(true);
    try {
      const res = await apiFetch<{ data: TravelExpense[] }>(`/travel/expenses?month=${month}`);
      setExpenses(res.data);
    } finally {
      setLoading(false);
    }
  }, [month]);

  useEffect(() => {
    void load();
  }, [load]);

  async function remove(expense: TravelExpense) {
    if (!window.confirm(t("travel.removeExpenseConfirm"))) return;
    try {
      await apiFetch(`/travel/expenses/${expense.id}`, { method: "DELETE" });
      await load();
      await onChanged();
    } catch (err) {
      setError(err instanceof ApiError ? err.message : "Error");
    }
  }

  return (
    <div className="space-y-4">
      <div className="flex justify-end">
        <Button onClick={() => setCreating(true)}>{t("travel.newExpense")}</Button>
      </div>
      {error && <p className="text-sm text-red-600">{error}</p>}

      <Card className="overflow-x-auto p-0">
        <table className="w-full min-w-[980px] text-sm">
          <thead className="border-b border-zinc-200 text-left text-xs uppercase tracking-wider text-zinc-500 dark:border-zinc-800">
            <tr>
              <th className="px-4 py-3">{t("travel.traveller")}</th>
              <th className="px-4 py-3">{t("travel.expenseDate")}</th>
              <th className="px-4 py-3">{t("travel.expenseType")}</th>
              <th className="px-4 py-3 text-right">{t("travel.amount")}</th>
              <th className="px-4 py-3 text-right">{t("travel.amountEur")}</th>
              <th className="px-4 py-3 text-right">{t("travel.remaining")}</th>
              <th className="px-4 py-3">{t("travel.status")}</th>
              <th className="px-4 py-3">{t("travel.costStatus")}</th>
              <th className="px-4 py-3 text-right">{t("travel.actions")}</th>
            </tr>
          </thead>
          <tbody className="divide-y divide-zinc-100 dark:divide-zinc-800">
            {loading ? (
              <tr>
                <td colSpan={9} className="px-4 py-8 text-center text-zinc-500">
                  {t("common.loading")}
                </td>
              </tr>
            ) : expenses.length === 0 ? (
              <tr>
                <td colSpan={9} className="px-4 py-8 text-center text-zinc-500">
                  {t("travel.noExpenses")}
                </td>
              </tr>
            ) : (
              expenses.map((expense) => (
                <tr key={expense.id} className="text-zinc-800 dark:text-zinc-200">
                  <td className="px-4 py-3 font-medium">{expense.traveller_name ?? "—"}</td>
                  <td className="px-4 py-3">{formatDate(expense.expense_date)}</td>
                  <td className="px-4 py-3">{t(`expenseType.${expense.expense_type}`)}</td>
                  <td className="px-4 py-3 text-right tabular-nums">
                    {formatMoney(expense.amount, expense.currency)}
                  </td>
                  <td className="px-4 py-3 text-right tabular-nums">{formatMoney(expense.amount_eur)}</td>
                  <td className="px-4 py-3 text-right tabular-nums">{formatMoney(expense.remaining_amount)}</td>
                  <td className="px-4 py-3">
                    <Badge tone={statusTone(expense.status)}>{t(`status.${expense.status}`)}</Badge>
                  </td>
                  <td className="px-4 py-3">
                    <Badge tone={expense.cost_status === "written" ? "green" : "amber"}>
                      {t(`costStatus.${expense.cost_status}`)}
                    </Badge>
                  </td>
                  <td className="px-4 py-3 text-right">
                    <div className="flex flex-wrap justify-end gap-2">
                      {expense.remaining_amount > 0 && (
                        <Button variant="secondary" className="h-8 px-3" onClick={() => setPaying(expense)}>
                          {t("travel.recordPayment")}
                        </Button>
                      )}
                      <Button variant="secondary" className="h-8 px-3" onClick={() => setFiles(expense)}>
                        {t("travel.files")}
                        {expense.attachment_count ? ` (${expense.attachment_count})` : ""}
                      </Button>
                      <Button variant="secondary" className="h-8 px-3" onClick={() => setEditing(expense)}>
                        {t("travel.edit")}
                      </Button>
                      <Button variant="secondary" className="h-8 px-3" onClick={() => void remove(expense)}>
                        {t("travel.remove")}
                      </Button>
                    </div>
                  </td>
                </tr>
              ))
            )}
          </tbody>
        </table>
      </Card>

      {(creating || editing) && (
        <ExpenseModal
          expense={editing ?? undefined}
          employees={employees}
          month={month}
          onClose={() => {
            setCreating(false);
            setEditing(null);
          }}
          onSaved={async () => {
            await load();
            await onChanged();
          }}
        />
      )}
      {paying && (
        <PaymentModal
          title={t("travel.recordPayment")}
          endpoint={`/travel/expenses/${paying.id}/payments`}
          remaining={paying.remaining_amount}
          onClose={() => setPaying(null)}
          onSaved={async () => {
            await load();
            await onChanged();
          }}
        />
      )}
      {files && (
        <AttachmentsModal
          title={`${t("travel.files")} — ${files.traveller_name ?? ""}`}
          basePath={`/travel/expenses/${files.id}/attachments`}
          defaultKind="invoice"
          onClose={() => setFiles(null)}
          onChanged={load}
        />
      )}
    </div>
  );
}

function ExpenseModal({
  expense,
  employees,
  month,
  onClose,
  onSaved,
}: {
  expense?: TravelExpense;
  employees: EmployeeOption[];
  month: string;
  onClose: () => void;
  onSaved: () => Promise<void>;
}) {
  const { t } = useI18n();
  const [employeeId, setEmployeeId] = useState(expense?.employee_id ? String(expense.employee_id) : "");
  const [personName, setPersonName] = useState(expense?.person_name ?? "");
  const [expenseDate, setExpenseDate] = useState(expense?.expense_date ?? todayISO());
  const [periodMonth, setPeriodMonth] = useState((expense?.period_month ?? `${month}-01`).slice(0, 7));
  const [expenseType, setExpenseType] = useState(expense?.expense_type ?? "car");
  const [currency, setCurrency] = useState(expense?.currency ?? "EUR");
  const [amount, setAmount] = useState(expense ? String(expense.amount) : "");
  const [rate, setRate] = useState(expense?.exchange_rate == null ? "" : String(expense.exchange_rate));
  const [costStatus, setCostStatus] = useState(expense?.cost_status ?? "not_written");
  const [notes, setNotes] = useState(expense?.notes ?? "");
  const [error, setError] = useState<string | null>(null);
  const [saving, setSaving] = useState(false);

  async function submit(e: React.FormEvent) {
    e.preventDefault();
    setSaving(true);
    setError(null);
    try {
      await apiFetch(expense ? `/travel/expenses/${expense.id}` : "/travel/expenses", {
        method: expense ? "PUT" : "POST",
        json: {
          employee_id: employeeId ? Number(employeeId) : null,
          person_name: personName.trim() || null,
          expense_date: expenseDate,
          period_month: periodMonth,
          expense_type: expenseType,
          currency,
          amount: Number(amount),
          exchange_rate: rate === "" ? null : Number(rate),
          cost_status: costStatus,
          notes: notes.trim() || null,
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

  return (
    <Modal open onClose={onClose} title={expense ? t("travel.editExpense") : t("travel.newExpense")}>
      <form onSubmit={submit} className="space-y-4">
        <div className="grid grid-cols-2 gap-4">
          <div>
            <label className={labelClass}>{t("travel.worker")}</label>
            <Select value={employeeId} onChange={(e) => setEmployeeId(e.target.value)}>
              <option value="">{t("travel.noWorkerRecord")}</option>
              {employees.map((employee) => (
                <option key={employee.id} value={employee.id}>
                  {employee.full_name}
                </option>
              ))}
            </Select>
          </div>
          <div>
            <label className={labelClass}>{t("travel.passengerName")}</label>
            <Input
              value={personName}
              onChange={(e) => setPersonName(e.target.value)}
              required={employeeId === ""}
            />
          </div>
          <div>
            <label className={labelClass}>{t("travel.expenseDate")}</label>
            <Input type="date" value={expenseDate} onChange={(e) => setExpenseDate(e.target.value)} required />
          </div>
          <div>
            <label className={labelClass}>{t("travel.periodMonth")}</label>
            <Input type="month" value={periodMonth} onChange={(e) => setPeriodMonth(e.target.value)} required />
          </div>
          <div>
            <label className={labelClass}>{t("travel.expenseType")}</label>
            <Select value={expenseType} onChange={(e) => setExpenseType(e.target.value)}>
              {EXPENSE_TYPES.map((value) => (
                <option key={value} value={value}>
                  {t(`expenseType.${value}`)}
                </option>
              ))}
            </Select>
          </div>
          <div>
            <label className={labelClass}>{t("travel.costStatus")}</label>
            <Select value={costStatus} onChange={(e) => setCostStatus(e.target.value)}>
              {COST_STATUSES.map((value) => (
                <option key={value} value={value}>
                  {t(`costStatus.${value}`)}
                </option>
              ))}
            </Select>
          </div>
          <div>
            <label className={labelClass}>{t("travel.currency")}</label>
            <Select value={currency} onChange={(e) => setCurrency(e.target.value)}>
              {CURRENCIES.map((value) => (
                <option key={value} value={value}>
                  {value}
                </option>
              ))}
            </Select>
          </div>
          <div>
            <label className={labelClass}>{t("travel.amount")}</label>
            <Input
              type="number"
              step="0.01"
              min="0"
              value={amount}
              onChange={(e) => setAmount(e.target.value)}
              required
            />
          </div>
          <div className="col-span-2">
            <label className={labelClass}>{t("travel.exchangeRate")}</label>
            <Input
              type="number"
              step="0.0001"
              min="0"
              value={rate}
              onChange={(e) => setRate(e.target.value)}
              disabled={currency === "EUR"}
            />
            <p className="mt-1 text-xs text-zinc-500">{t("travel.rateHint")}</p>
          </div>
          <div className="col-span-2">
            <label className={labelClass}>{t("travel.notes")}</label>
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

function AssistanceTab({ month, onChanged }: { month: string; onChanged: () => Promise<void> }) {
  const { t } = useI18n();
  const { hasPermission } = useAuth();
  const employees = useEmployees();
  const year = Number(month.slice(0, 4));

  const [payments, setPayments] = useState<AssistancePayment[]>([]);
  const [summary, setSummary] = useState<AssistanceSummary | null>(null);
  const [loading, setLoading] = useState(true);
  const [search, setSearch] = useState("");
  const [creating, setCreating] = useState(false);
  const [editing, setEditing] = useState<AssistancePayment | null>(null);
  const [viewing, setViewing] = useState<AssistancePayment | null>(null);
  const [prefillEmployee, setPrefillEmployee] = useState<number | null>(null);
  const [error, setError] = useState<string | null>(null);

  const debouncedSearch = useDebouncedValue(search);
  // Every action here writes through `travel.manage`; there is no finer
  // permission, so that is what decides whether they are offered at all.
  const canManage = hasPermission("travel.manage");

  const load = useCallback(async () => {
    setLoading(true);
    const params = new URLSearchParams({ year: String(year) });
    if (debouncedSearch) params.set("search", debouncedSearch);

    try {
      const [list, totals] = await Promise.all([
        apiFetch<{ data: AssistancePayment[] }>(`/travel/social-assistance?${params.toString()}`),
        apiFetch<{ data: AssistanceSummary }>(`/travel/social-assistance/summary?year=${year}`),
      ]);
      setPayments(list.data);
      setSummary(totals.data);
    } finally {
      setLoading(false);
    }
  }, [year, debouncedSearch]);

  useEffect(() => {
    void load();
  }, [load]);

  async function remove(payment: AssistancePayment) {
    if (!window.confirm(t("travel.removeAssistanceConfirm"))) return;
    try {
      await apiFetch(`/travel/social-assistance/${payment.id}`, { method: "DELETE" });
      await load();
      await onChanged();
    } catch (err) {
      setError(errorMessage(err));
    }
  }

  function payWorker(employeeId: number | null) {
    setPrefillEmployee(employeeId);
    setCreating(true);
  }

  // Only workers who have been paid or still have something owed are worth showing.
  const outstanding = (summary?.rows ?? []).filter((row) => row.paid > 0 || (row.payable ?? 0) > 0);

  return (
    <div className="space-y-4">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <p className="text-sm text-zinc-500">
          {t("travel.assistanceHelp")}
          {summary && ` ${t("travel.entitlement")}: ${formatMoney(summary.entitlement)} / ${summary.year}.`}
        </p>
        <div className="flex flex-wrap items-center gap-2">
          <Input
            className="w-56"
            placeholder={t("travel.searchAssistance")}
            value={search}
            onChange={(e) => setSearch(e.target.value)}
          />
          {canManage && <Button onClick={() => payWorker(null)}>{t("travel.newAssistance")}</Button>}
        </div>
      </div>
      {error && <p className="text-sm text-red-600">{error}</p>}

      <Card className="overflow-x-auto p-0">
        <table className="w-full min-w-[720px] text-sm">
          <thead className="border-b border-zinc-200 text-left text-xs uppercase tracking-wider text-zinc-500 dark:border-zinc-800">
            <tr>
              <th className="px-4 py-3">{t("travel.recipient")}</th>
              <th className="px-4 py-3 text-right">{t("travel.assistancePaid")}</th>
              <th className="px-4 py-3 text-right">{t("travel.assistancePayable")}</th>
              <th className="px-4 py-3 text-right">{t("travel.actions")}</th>
            </tr>
          </thead>
          <tbody className="divide-y divide-zinc-100 dark:divide-zinc-800">
            {outstanding.length === 0 ? (
              <tr>
                <td colSpan={4} className="px-4 py-6 text-center text-zinc-500">
                  {t("travel.noAssistance")}
                </td>
              </tr>
            ) : (
              outstanding.map((row) => (
                <tr key={`${row.employee_id ?? row.full_name}`} className="text-zinc-800 dark:text-zinc-200">
                  <td className="px-4 py-3">{row.full_name ?? "—"}</td>
                  <td className="px-4 py-3 text-right tabular-nums">{formatMoney(row.paid)}</td>
                  <td className="px-4 py-3 text-right tabular-nums">
                    {row.payable === null ? "—" : formatMoney(row.payable)}
                  </td>
                  <td className="px-4 py-3 text-right">
                    {/* Paying a worker from the row that says what they are owed. */}
                    {canManage && row.employee_id !== null && (row.payable ?? 0) > 0 && (
                      <Button
                        variant="secondary"
                        className="h-8 px-3"
                        onClick={() => payWorker(row.employee_id)}
                      >
                        {t("travel.newAssistance")}
                      </Button>
                    )}
                  </td>
                </tr>
              ))
            )}
          </tbody>
        </table>
      </Card>

      <Card className="overflow-x-auto p-0">
        <table className="w-full min-w-[900px] text-sm">
          <thead className="border-b border-zinc-200 text-left text-xs uppercase tracking-wider text-zinc-500 dark:border-zinc-800">
            <tr>
              <th className="px-4 py-3">{t("travel.recipient")}</th>
              <th className="px-4 py-3">{t("travel.paymentDate")}</th>
              <th className="px-4 py-3">{t("travel.method")}</th>
              <th className="px-4 py-3 text-right">{t("travel.amount")}</th>
              <th className="px-4 py-3 text-right">{t("travel.amountEur")}</th>
              <th className="px-4 py-3">{t("travel.assistanceReason")}</th>
              <th className="px-4 py-3 text-right">{t("travel.actions")}</th>
            </tr>
          </thead>
          <tbody className="divide-y divide-zinc-100 dark:divide-zinc-800">
            {loading ? (
              <tr>
                <td colSpan={7} className="px-4 py-8 text-center text-zinc-500">
                  {t("common.loading")}
                </td>
              </tr>
            ) : payments.length === 0 ? (
              <tr>
                <td colSpan={7} className="px-4 py-8 text-center text-zinc-500">
                  {t("travel.noAssistance")}
                </td>
              </tr>
            ) : (
              payments.map((payment) => (
                <tr key={payment.id} className="text-zinc-800 dark:text-zinc-200">
                  <td className="px-4 py-3 font-medium">{payment.recipient_name ?? "—"}</td>
                  <td className="px-4 py-3">{formatDate(payment.payment_date)}</td>
                  <td className="px-4 py-3">{payment.method ? t(`method.${payment.method}`) : "—"}</td>
                  <td className="px-4 py-3 text-right tabular-nums">
                    {formatMoney(payment.amount, payment.currency)}
                  </td>
                  <td className="px-4 py-3 text-right tabular-nums">{formatMoney(payment.amount_eur)}</td>
                  <td className="px-4 py-3 text-xs text-zinc-500">{payment.reason ?? "—"}</td>
                  <td className="px-4 py-3 text-right">
                    {/* Viewing is always available; writing follows the permission. */}
                    <div className="flex flex-wrap justify-end gap-2">
                      <Button variant="secondary" className="h-8 px-3" onClick={() => setViewing(payment)}>
                        {t("travel.view")}
                      </Button>
                      {canManage && (
                        <>
                          <Button variant="secondary" className="h-8 px-3" onClick={() => setEditing(payment)}>
                            {t("travel.edit")}
                          </Button>
                          <Button
                            variant="ghost"
                            className="h-8 px-3 text-red-600"
                            onClick={() => void remove(payment)}
                          >
                            {t("travel.remove")}
                          </Button>
                        </>
                      )}
                    </div>
                  </td>
                </tr>
              ))
            )}
          </tbody>
        </table>
      </Card>

      {viewing && (
        <AssistanceDetails
          payment={viewing}
          canManage={canManage}
          onEdit={() => {
            setEditing(viewing);
            setViewing(null);
          }}
          onClose={() => setViewing(null)}
        />
      )}

      {(creating || editing) && (
        <AssistanceModal
          payment={editing ?? undefined}
          employees={employees}
          year={year}
          defaultEmployeeId={prefillEmployee}
          onClose={() => {
            setCreating(false);
            setEditing(null);
            setPrefillEmployee(null);
          }}
          onSaved={async () => {
            await load();
            await onChanged();
          }}
        />
      )}
    </div>
  );
}

/** One payout in full, including the rate that produced its EUR value. */
function AssistanceDetails({
  payment,
  canManage,
  onEdit,
  onClose,
}: {
  payment: AssistancePayment;
  canManage: boolean;
  onEdit: () => void;
  onClose: () => void;
}) {
  const { t } = useI18n();

  const rows: Array<[string, string]> = [
    [t("travel.recipient"), payment.recipient_name ?? "—"],
    [t("travel.paymentDate"), formatDate(payment.payment_date)],
    [t("travel.entitlementYear"), String(payment.entitlement_year)],
    [t("travel.method"), payment.method ? t(`method.${payment.method}`) : "—"],
    [t("travel.amount"), formatMoney(payment.amount, payment.currency)],
    [t("travel.amountEur"), formatMoney(payment.amount_eur)],
    [
      t("travel.exchangeRate"),
      payment.exchange_rate ? t("travel.rateUsed", { rate: payment.exchange_rate }) : "—",
    ],
    [t("travel.assistanceReason"), payment.reason ?? "—"],
  ];

  return (
    <Modal open onClose={onClose} title={t("travel.assistanceDetails")}>
      <div className="space-y-4">
        <dl className="grid grid-cols-2 gap-x-4 gap-y-2 text-sm">
          {rows.map(([label, value]) => (
            <div key={label} className="flex flex-col">
              <dt className="text-xs uppercase tracking-wider text-zinc-500">{label}</dt>
              <dd className="text-zinc-900 dark:text-zinc-100">{value}</dd>
            </div>
          ))}
        </dl>

        {payment.notes && (
          <div>
            <p className="text-xs uppercase tracking-wider text-zinc-500">{t("travel.notes")}</p>
            <p className="whitespace-pre-wrap text-sm text-zinc-800 dark:text-zinc-100">{payment.notes}</p>
          </div>
        )}

        <div className="flex justify-end gap-2">
          {canManage && (
            <Button variant="secondary" onClick={onEdit}>
              {t("travel.edit")}
            </Button>
          )}
          <Button onClick={onClose}>{t("common.close")}</Button>
        </div>
      </div>
    </Modal>
  );
}

function AssistanceModal({
  payment,
  employees,
  year,
  defaultEmployeeId,
  onClose,
  onSaved,
}: {
  payment?: AssistancePayment;
  employees: EmployeeOption[];
  year: number;
  /** Pre-selected worker when the form is opened from a summary row. */
  defaultEmployeeId?: number | null;
  onClose: () => void;
  onSaved: () => Promise<void>;
}) {
  const { t } = useI18n();
  const [employeeId, setEmployeeId] = useState(
    String(payment?.employee_id ?? defaultEmployeeId ?? ""),
  );
  const [personName, setPersonName] = useState(payment?.person_name ?? "");
  const [paymentDate, setPaymentDate] = useState(payment?.payment_date ?? todayISO());
  const [entitlementYear, setEntitlementYear] = useState(String(payment?.entitlement_year ?? year));
  const [currency, setCurrency] = useState(payment?.currency ?? "EUR");
  const [amount, setAmount] = useState(payment ? String(payment.amount) : "");
  const [rate, setRate] = useState(payment?.exchange_rate == null ? "" : String(payment.exchange_rate));
  const [method, setMethod] = useState(payment?.method ?? "cash");
  const [reason, setReason] = useState(payment?.reason ?? "");
  const [notes, setNotes] = useState(payment?.notes ?? "");
  const [error, setError] = useState<string | null>(null);
  const [saving, setSaving] = useState(false);

  async function submit(e: React.FormEvent) {
    e.preventDefault();
    setSaving(true);
    setError(null);
    try {
      await apiFetch(payment ? `/travel/social-assistance/${payment.id}` : "/travel/social-assistance", {
        method: payment ? "PUT" : "POST",
        json: {
          employee_id: employeeId ? Number(employeeId) : null,
          person_name: personName.trim() || null,
          payment_date: paymentDate,
          entitlement_year: Number(entitlementYear),
          currency,
          amount: Number(amount),
          exchange_rate: rate === "" ? null : Number(rate),
          method,
          reason: reason.trim() || null,
          notes: notes.trim() || null,
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

  return (
    <Modal open onClose={onClose} title={payment ? t("travel.editAssistance") : t("travel.newAssistance")}>
      <form onSubmit={submit} className="space-y-4">
        <div className="grid grid-cols-2 gap-4">
          <div>
            <label className={labelClass}>{t("travel.worker")}</label>
            <Select value={employeeId} onChange={(e) => setEmployeeId(e.target.value)}>
              <option value="">{t("travel.noWorkerRecord")}</option>
              {employees.map((employee) => (
                <option key={employee.id} value={employee.id}>
                  {employee.full_name}
                </option>
              ))}
            </Select>
          </div>
          <div>
            <label className={labelClass}>{t("travel.recipient")}</label>
            <Input
              value={personName}
              onChange={(e) => setPersonName(e.target.value)}
              required={employeeId === ""}
            />
          </div>
          <div>
            <label className={labelClass}>{t("travel.paymentDate")}</label>
            <Input type="date" value={paymentDate} onChange={(e) => setPaymentDate(e.target.value)} required />
          </div>
          <div>
            <label className={labelClass}>{t("travel.entitlementYear")}</label>
            <Input
              type="number"
              min="2000"
              max="2100"
              value={entitlementYear}
              onChange={(e) => setEntitlementYear(e.target.value)}
              required
            />
          </div>
          <div>
            <label className={labelClass}>{t("travel.currency")}</label>
            <Select value={currency} onChange={(e) => setCurrency(e.target.value)}>
              {CURRENCIES.map((value) => (
                <option key={value} value={value}>
                  {value}
                </option>
              ))}
            </Select>
          </div>
          <div>
            <label className={labelClass}>{t("travel.amount")}</label>
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
            <label className={labelClass}>{t("travel.exchangeRate")}</label>
            <Input
              type="number"
              step="0.0001"
              min="0"
              value={rate}
              onChange={(e) => setRate(e.target.value)}
              disabled={currency === "EUR"}
            />
          </div>
          <div>
            <label className={labelClass}>{t("travel.method")}</label>
            <Select value={method} onChange={(e) => setMethod(e.target.value)}>
              {METHODS.map((value) => (
                <option key={value} value={value}>
                  {t(`method.${value}`)}
                </option>
              ))}
            </Select>
          </div>
          <div className="col-span-2">
            <label className={labelClass}>{t("travel.assistanceReason")}</label>
            <Input value={reason} onChange={(e) => setReason(e.target.value)} />
          </div>
          <div className="col-span-2">
            <label className={labelClass}>{t("travel.notes")}</label>
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

/** Settling a ticket or an expense — always in EUR, capped at what is left. */
function PaymentModal({
  title,
  endpoint,
  remaining,
  onClose,
  onSaved,
}: {
  title: string;
  endpoint: string;
  remaining: number;
  onClose: () => void;
  onSaved: () => Promise<void>;
}) {
  const { t } = useI18n();
  const [amount, setAmount] = useState(String(remaining));
  const [paymentDate, setPaymentDate] = useState(todayISO());
  const [method, setMethod] = useState<string>("cash");
  const [notes, setNotes] = useState("");
  const [error, setError] = useState<string | null>(null);
  const [saving, setSaving] = useState(false);

  async function submit(e: React.FormEvent) {
    e.preventDefault();
    setSaving(true);
    setError(null);
    try {
      await apiFetch(endpoint, {
        method: "POST",
        json: {
          amount: Number(amount),
          payment_date: paymentDate,
          method,
          notes: notes.trim() || null,
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

  return (
    <Modal open onClose={onClose} title={title}>
      <form onSubmit={submit} className="space-y-4">
        <div className="grid grid-cols-2 gap-4">
          <div>
            <label className={labelClass}>{t("travel.amount")}</label>
            <Input
              type="number"
              step="0.01"
              min="0.01"
              max={remaining}
              value={amount}
              onChange={(e) => setAmount(e.target.value)}
              required
            />
            <p className="mt-1 text-xs text-zinc-500">
              {t("travel.remaining")}: {formatMoney(remaining)}
            </p>
          </div>
          <div>
            <label className={labelClass}>{t("travel.paymentDate")}</label>
            <Input type="date" value={paymentDate} onChange={(e) => setPaymentDate(e.target.value)} required />
          </div>
          <div>
            <label className={labelClass}>{t("travel.method")}</label>
            <Select value={method} onChange={(e) => setMethod(e.target.value)}>
              {METHODS.map((value) => (
                <option key={value} value={value}>
                  {t(`method.${value}`)}
                </option>
              ))}
            </Select>
          </div>
          <div>
            <label className={labelClass}>{t("travel.notes")}</label>
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
