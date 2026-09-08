"use client";

import { useState } from "react";
import { apiFetch, errorMessage } from "@/lib/api";
import { useResource } from "@/lib/data/use-resource";
import { useI18n } from "@/lib/i18n/context";
import { amountTone, formatMoney } from "@/lib/format";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Card } from "@/components/ui/card";
import { Input } from "@/components/ui/input";
import { Modal } from "@/components/ui/modal";
import { Select } from "@/components/ui/select";

interface BankAccount {
  id: number;
  name: string;
  kind: string;
  currency: string;
  iban: string | null;
  is_active: boolean;
  sort_order: number;
  balance?: number;
  /** Whether any movement or settlement points at this account. */
  has_history?: boolean;
  notes: string | null;
}

const KINDS = ["cash", "bank"] as const;

/**
 * The accounts money moves through.
 *
 * These were three columns on the movements table — `cash_amount`, `nlb_amount`,
 * `lovcen_amount` — so opening a fourth account meant a migration. They are rows
 * now, and this is where they are opened, renamed and closed.
 */
export default function BankAccountsPage() {
  const { t } = useI18n();
  const [creating, setCreating] = useState(false);
  const [editing, setEditing] = useState<BankAccount | null>(null);
  const [error, setError] = useState<string | null>(null);

  const list = useResource<{ data: BankAccount[] }>("/bank-accounts");
  const accounts = list.data?.data ?? [];

  async function setActive(account: BankAccount, isActive: boolean) {
    setError(null);
    try {
      await apiFetch(`/bank-accounts/${account.id}`, {
        method: "PUT",
        json: { is_active: isActive },
      });
    } catch (err) {
      setError(errorMessage(err));
    }
  }

  async function remove(account: BankAccount) {
    setError(null);
    try {
      await apiFetch(`/bank-accounts/${account.id}`, { method: "DELETE" });
    } catch (err) {
      setError(errorMessage(err));
    }
  }

  return (
    <div className="space-y-5">
      <div className="flex items-center justify-between">
        <Button onClick={() => setCreating(true)}>{t("bankAccounts.new")}</Button>
      </div>

      <p className="max-w-2xl text-sm text-zinc-500">{t("bankAccounts.hint")}</p>

      {error && <p className="text-sm text-red-600">{error}</p>}

      <Card className="vui-table scroll-quiet overflow-x-auto p-0">
        <table className="w-full min-w-[820px] text-sm">
          <thead>
            <tr>
              <th>{t("bankAccounts.name")}</th>
              <th>{t("bankAccounts.kind")}</th>
              <th>{t("bankAccounts.iban")}</th>
              <th className="text-right">{t("bankAccounts.balance")}</th>
              <th>{t("bankAccounts.status")}</th>
              <th className="text-right">{t("common.actions")}</th>
            </tr>
          </thead>
          <tbody>
            {list.loading ? (
              <tr>
                <td colSpan={6} className="py-14 text-center text-sm text-zinc-500">
                  {t("common.loading")}
                </td>
              </tr>
            ) : accounts.length === 0 ? (
              <tr>
                <td colSpan={6} className="py-14 text-center text-sm text-zinc-500">
                  {t("bankAccounts.none")}
                </td>
              </tr>
            ) : (
              accounts.map((account) => (
                <tr key={account.id} className="text-zinc-800 dark:text-zinc-200">
                  <td className="font-medium">{account.name}</td>
                  <td className="text-zinc-500">
                    {t(account.kind === "cash" ? "bankAccounts.kindCash" : "bankAccounts.kindBank")}
                  </td>
                  <td className="text-zinc-500">{account.iban ?? "—"}</td>
                  {/*
                    Signed, like every other figure: money out has already been
                    taken off it, and an account in the red says so.
                  */}
                  <td className={`text-right tabular-nums ${amountTone(account.balance ?? 0)}`}>
                    {formatMoney(account.balance ?? 0)}
                  </td>
                  <td>
                    <Badge tone={account.is_active ? "green" : "gray"}>
                      {t(account.is_active ? "bankAccounts.open" : "bankAccounts.closed")}
                    </Badge>
                  </td>
                  <td className="text-right">
                    <div className="flex justify-end gap-2">
                      <Button variant="secondary" className="h-8 px-3" onClick={() => setEditing(account)}>
                        {t("common.edit")}
                      </Button>
                      <Button
                        variant="ghost"
                        className="h-8 px-3"
                        onClick={() => setActive(account, !account.is_active)}
                      >
                        {t(account.is_active ? "bankAccounts.close" : "bankAccounts.reopen")}
                      </Button>
                      {/*
                        Only an account nothing points at can be deleted. One
                        that has carried money is closed instead, so every
                        movement and settlement that named it keeps naming it.
                      */}
                      {!account.has_history && (
                        <Button
                          variant="ghost"
                          className="h-8 px-3 text-red-600"
                          onClick={() => {
                            if (confirm(t("bankAccounts.removeConfirm", { name: account.name }))) {
                              remove(account);
                            }
                          }}
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
      </Card>

      {creating && <AccountModal onClose={() => setCreating(false)} />}
      {editing && <AccountModal account={editing} onClose={() => setEditing(null)} />}
    </div>
  );
}

/** Open an account, or correct one. The same form serves both. */
function AccountModal({ account, onClose }: { account?: BankAccount; onClose: () => void }) {
  const { t } = useI18n();
  const editing = account !== undefined;

  const [name, setName] = useState(account?.name ?? "");
  const [kind, setKind] = useState(account?.kind ?? "bank");
  const [iban, setIban] = useState(account?.iban ?? "");
  const [notes, setNotes] = useState(account?.notes ?? "");
  const [error, setError] = useState<string | null>(null);
  const [saving, setSaving] = useState(false);

  async function submit(e: React.FormEvent) {
    e.preventDefault();
    setSaving(true);
    setError(null);

    try {
      await apiFetch(editing ? `/bank-accounts/${account.id}` : "/bank-accounts", {
        method: editing ? "PUT" : "POST",
        json: { name, kind, iban: iban || null, notes: notes || null },
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
    <Modal open onClose={onClose} title={editing ? t("bankAccounts.edit") : t("bankAccounts.new")}>
      <form onSubmit={submit} className="space-y-4">
        <div>
          <label className={label}>{t("bankAccounts.name")}</label>
          <Input value={name} onChange={(e) => setName(e.target.value)} required />
        </div>
        <div>
          <label className={label}>{t("bankAccounts.kind")}</label>
          <Select value={kind} onChange={(e) => setKind(e.target.value)}>
            {KINDS.map((value) => (
              <option key={value} value={value}>
                {t(value === "cash" ? "bankAccounts.kindCash" : "bankAccounts.kindBank")}
              </option>
            ))}
          </Select>
        </div>
        <div>
          <label className={label}>{t("bankAccounts.iban")}</label>
          <Input value={iban} onChange={(e) => setIban(e.target.value)} />
        </div>
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
            {saving ? t("common.saving") : t("common.save")}
          </Button>
        </div>
      </form>
    </Modal>
  );
}
