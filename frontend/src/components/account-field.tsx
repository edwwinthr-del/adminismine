"use client";

import { AsyncSelect } from "@/components/ui/async-select";
import { useI18n } from "@/lib/i18n/context";

/**
 * Which account a settlement moved money through.
 *
 * This was a `<select>` of `cash` / `nlb` / `lovcen` / `other` — two Montenegrin
 * bank names hardcoded into every settlement form in the app. Accounts are rows
 * now, so the same field asks the server which ones this company holds.
 *
 * Leaving it empty is what `other` meant: settled, but not through an account
 * the app tracks (an offset, a correction, cash outside the till). Nothing can
 * be booked into the ledger from that, which is why {@link canBook} says so.
 *
 * It is the same control on every module that takes settlements — invoices,
 * rent, bills, wages, tickets, travel expenses, loans, social assistance.
 */
export function AccountField({
  value,
  onChange,
  editing,
}: {
  value: number | null;
  onChange: (value: number | null) => void;
  /**
   * Editing an existing settlement, which may name an account that has since
   * been closed. The form still has to be able to show it.
   */
  editing?: boolean;
}) {
  const { t } = useI18n();

  const label = "mb-1.5 block px-4 text-[11px] font-medium uppercase tracking-[0.08em] text-zinc-500";

  return (
    <div>
      <label className={label}>{t("settlement.account")}</label>
      <AsyncSelect
        resource="bank-accounts"
        value={value}
        onChange={(next) => onChange(next)}
        params={{ include_closed: editing ?? false }}
        emptyLabel={t("settlement.noAccount")}
        placeholder={t("settlement.selectAccount")}
      />
    </div>
  );
}
