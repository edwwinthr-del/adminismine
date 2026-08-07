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

/** Methods that name an account a movement can be booked against; `other` names none. */
export const BOOKABLE_METHODS = ["cash", "nlb", "lovcen"];

export function canBook(method: string): boolean {
  return BOOKABLE_METHODS.includes(method);
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
 * bank movements, so a payment recorded against an invoice and nowhere else
 * settles the invoice while leaving the dashboard exactly as it was. This field
 * is where the operator says which of the two happened: the payment *is* the
 * record of the movement, or it points at one already entered from a statement.
 */
export function BankRecordField({
  mode,
  onModeChange,
  movementId,
  onMovementChange,
  method,
  editing,
}: {
  mode: BankRecordMode;
  onModeChange: (mode: BankRecordMode) => void;
  movementId: number | null;
  onMovementChange: (id: number | null) => void;
  /** The payment method — `other` books nothing, since it names no account. */
  method: string;
  /** Editing an existing payment: booking a new movement no longer applies. */
  editing: boolean;
}) {
  const { t } = useI18n();
  const bookable = canBook(method);

  const label = "mb-1.5 block px-4 text-[11px] font-medium uppercase tracking-[0.08em] text-zinc-500";

  return (
    <div>
      <label className={label}>{t("payables.bankRecord")}</label>
      <Select value={mode} onChange={(e) => onModeChange(e.target.value as BankRecordMode)}>
        {editing && <option value="keep">{t("payables.bankRecordKeep")}</option>}
        {!editing && (
          <option value="book" disabled={!bookable}>
            {t("payables.bankRecordBook")}
          </option>
        )}
        <option value="match">{t("payables.bankRecordMatch")}</option>
        <option value="none">{t("payables.bankRecordNone")}</option>
      </Select>

      <p className="mt-1 text-xs text-zinc-500">
        {mode === "book"
          ? t("payables.bankRecordBookHint")
          : mode === "match"
            ? t("payables.bankRecordMatchHint")
            : mode === "none"
              ? t("payables.bankRecordNoneHint")
              : t("payables.bankRecordKeepHint")}
      </p>

      {!bookable && !editing && (
        <p className="mt-1 text-xs text-amber-700 dark:text-amber-500">
          {t("payables.bankRecordNoAccount")}
        </p>
      )}

      {mode === "match" && (
        <div className="mt-2">
          {/*
            Unmatched only: a movement that already settles another invoice is
            not a candidate, and offering it is how the same money gets counted
            against two invoices.
          */}
          <AsyncSelect
            resource="bank-transactions"
            value={movementId}
            onChange={onMovementChange}
            params={{ unmatched: true }}
            placeholder={t("payables.bankRecordSelect")}
          />
        </div>
      )}
    </div>
  );
}
