"use client";

import { AsyncSelect } from "@/components/ui/async-select";
import { Select } from "@/components/ui/select";
import { useI18n } from "@/lib/i18n/context";

/**
 * How a payment relates to the bank ledger.
 *
 * - `book` — this payment is the record that the money moved, so the API writes
 *   the matching bank/cash movement and links it.
 * - `match` — the movement was already typed off a bank statement; the payment
 *   only points at it.
 * - `none` — no ledger entry (an offset, a correction, cash outside the till).
 * - `keep` — leave whatever the payment already has. Only offered when editing,
 *   and it sends nothing at all, so an amount-only correction can never
 *   accidentally detach a movement.
 */
export type BankRecordMode = "book" | "match" | "none" | "keep";

/**
 * Whether this settlement names an account a movement can be booked against.
 *
 * Naming none is what `other` used to mean — money settled, but not through an
 * account the app tracks — and there is nothing to book from that.
 */
export function canBook(accountId: number | null): boolean {
  return accountId !== null;
}

/**
 * What to send with the payment, given the operator's choice.
 *
 * `keep` contributes nothing — the update endpoint treats an absent
 * `bank_transaction_id` as "unchanged", which is what makes correcting an
 * amount safe.
 */
export function bankRecordPayload(
  mode: BankRecordMode,
  movementId: number | null,
): Record<string, unknown> {
  if (mode === "book") return { book_bank_transaction: true };
  if (mode === "match") return { bank_transaction_id: movementId };
  if (mode === "none") return { bank_transaction_id: null };

  return {};
}

/**
 * The control behind every money figure on the dashboard.
 *
 * Balances, the income/expense split and the cashflow trend are all summed from
 * bank movements, so a payment recorded against its own module and nowhere else
 * settles the record while leaving the dashboard exactly as it was. This field
 * is where the operator says which of the two happened: the payment *is* the
 * record of the movement, or it points at one already entered from a statement.
 *
 * It is the same control on every module that takes settlements — invoices,
 * rent, bills, wages, tickets, travel expenses, loans, social assistance — so
 * its wording names no particular one.
 */
export function BankRecordField({
  mode,
  onModeChange,
  movementId,
  onMovementChange,
  accountId,
  editing,
}: {
  mode: BankRecordMode;
  onModeChange: (mode: BankRecordMode) => void;
  movementId: number | null;
  onMovementChange: (id: number | null) => void;
  /** The account the settlement names — none books nothing. */
  accountId: number | null;
  /** Editing an existing payment: booking a new movement no longer applies. */
  editing: boolean;
}) {
  const { t } = useI18n();
  const bookable = canBook(accountId);

  const label = "mb-1.5 block px-4 text-[11px] font-medium uppercase tracking-[0.08em] text-zinc-500";

  return (
    <div>
      <label className={label}>{t("bankRecord.label")}</label>
      <Select value={mode} onChange={(e) => onModeChange(e.target.value as BankRecordMode)}>
        {editing && <option value="keep">{t("bankRecord.keep")}</option>}
        {!editing && (
          <option value="book" disabled={!bookable}>
            {t("bankRecord.book")}
          </option>
        )}
        <option value="match">{t("bankRecord.match")}</option>
        <option value="none">{t("bankRecord.none")}</option>
      </Select>

      <p className="mt-1 text-xs text-zinc-500">
        {mode === "book"
          ? t("bankRecord.bookHint")
          : mode === "match"
            ? t("bankRecord.matchHint")
            : mode === "none"
              ? t("bankRecord.noneHint")
              : t("bankRecord.keepHint")}
      </p>

      {!bookable && !editing && (
        <p className="mt-1 text-xs text-amber-700 dark:text-amber-500">{t("bankRecord.noAccount")}</p>
      )}

      {mode === "match" && (
        <div className="mt-2">
          {/*
            Unmatched only: a movement that already settles another record is
            not a candidate, and offering it is how the same money gets counted
            twice.
          */}
          <AsyncSelect
            resource="bank-transactions"
            value={movementId}
            onChange={onMovementChange}
            params={{ unmatched: true }}
            placeholder={t("bankRecord.select")}
          />
        </div>
      )}
    </div>
  );
}
