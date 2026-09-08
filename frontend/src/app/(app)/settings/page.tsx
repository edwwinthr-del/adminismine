"use client";

import { useEffect, useRef, useState } from "react";
import { ApiError, apiFetch } from "@/lib/api";
import { useResource } from "@/lib/data/use-resource";
import { Button } from "@/components/ui/button";
import { Card } from "@/components/ui/card";
import { Input } from "@/components/ui/input";
import { Select } from "@/components/ui/select";
import { ModuleToggles } from "@/components/module-toggles";
import { TerminologyEditor } from "@/components/terminology-editor";
import { VocabularyEditor } from "@/components/vocabulary-editor";
import { useI18n } from "@/lib/i18n/context";
import { LOCALE_LABELS, LOCALES } from "@/lib/i18n/dictionaries";
import { formatMoney } from "@/lib/format";

/**
 * What a payroll rule change did to the wage bill, one line per currency.
 *
 * One line *per currency* because attendance stores `currency` and
 * `total_amount` with no EUR twin, so there is no single figure to show — and
 * adding them together would be the TRY-onto-EUR bug the money columns exist to
 * prevent (rule 5). A currency whose total did not move is left out: it is the
 * change the operator is being told about.
 */
function payrollDelta(
  earned: { before: Record<string, number>; after: Record<string, number> } | null,
): string[] {
  if (!earned) return [];

  return [...new Set([...Object.keys(earned.before), ...Object.keys(earned.after)])]
    .sort()
    .flatMap((currency) => {
      const before = earned.before[currency] ?? 0;
      const after = earned.after[currency] ?? 0;
      const moved = Math.round((after - before) * 100) / 100;

      if (moved === 0) return [];

      const sign = moved > 0 ? "+" : "−";

      return [
        `${formatMoney(before, currency)} → ${formatMoney(after, currency)} (${sign}${formatMoney(Math.abs(moved), currency)})`,
      ];
    });
}

interface CompanySettings {
  company_name: string;
  base_currency: string;
  default_locale: string;
  timezone: string;
  /*
   * The payroll rules. They were constants in three backend services, so a
   * company on a five-day week got a divisor that was simply wrong and every
   * daily rate with it.
   */
  standard_day_hours: string | number;
  overtime_multiplier: string | number;
  working_day_rule: string;
  social_assistance_annual: string | number;
}

const WORKING_DAY_RULES = ["every_non_sunday", "mon_fri", "calendar"] as const;

export default function SettingsPage() {
  const { t } = useI18n();
  const [settings, setSettings] = useState<CompanySettings | null>(null);
  const [saving, setSaving] = useState(false);
  const [message, setMessage] = useState<string | null>(null);
  const [error, setError] = useState<string | null>(null);

  const { data } = useResource<{ data: CompanySettings }>("/company-settings");

  // `settings` is the form draft, so it is seeded from the server exactly once.
  // `useResource` revalidates on refocus and after any write, and re-seeding on
  // those would wipe out edits the user has typed but not saved.
  const seeded = useRef(false);

  useEffect(() => {
    if (seeded.current || !data) return;

    seeded.current = true;
    setSettings(data.data);
  }, [data]);

  async function onSave(e: React.FormEvent) {
    e.preventDefault();
    if (!settings) return;
    setSaving(true);
    setMessage(null);
    setError(null);
    try {
      const res = await apiFetch<{
        data: CompanySettings;
        attendance_recomputed: number;
        /** Per currency: attendance totals are not stored in EUR. */
        earned_pay: { before: Record<string, number>; after: Record<string, number> } | null;
      }>(
        "/company-settings",
        {
          method: "PUT",
          json: {
            company_name: settings.company_name,
            base_currency: settings.base_currency,
            default_locale: settings.default_locale,
            timezone: settings.timezone,
            standard_day_hours: Number(settings.standard_day_hours),
            overtime_multiplier: Number(settings.overtime_multiplier),
            working_day_rule: settings.working_day_rule,
            social_assistance_annual: Number(settings.social_assistance_annual),
          },
        },
      );
      setSettings(res.data);
      /*
       * Changing a payroll rule rewrites every earned figure already entered.
       * An edit that quietly moved four thousand of them should say so — and
       * should say what it moved them *to*: a row count tells the operator
       * something happened to the wage bill without telling them what, and is a
       * number they cannot check against anything. The totals are.
       */
      setMessage(
        res.attendance_recomputed > 0
          ? [
              t("settings.updatedWithPayroll", { count: res.attendance_recomputed }),
              ...payrollDelta(res.earned_pay),
            ].join(" · ")
          : t("settings.updated"),
      );
    } catch (err) {
      setError(err instanceof ApiError ? err.message : "Error");
    } finally {
      setSaving(false);
    }
  }

  if (!settings) {
    return <p className="text-sm text-zinc-500">{t("common.loading")}</p>;
  }

  return (
    <div className="max-w-xl space-y-6">
      <Card>
        <form onSubmit={onSave} className="space-y-4">
          <div>
            <label className="mb-1.5 block px-4 text-[11px] font-medium uppercase tracking-[0.08em] text-zinc-500">
              {t("settings.companyName")}
            </label>
            <Input
              value={settings.company_name}
              onChange={(e) => setSettings({ ...settings, company_name: e.target.value })}
            />
          </div>
          <div className="grid grid-cols-2 gap-4">
            <div>
              <label className="mb-1.5 block px-4 text-[11px] font-medium uppercase tracking-[0.08em] text-zinc-500">
                {t("settings.baseCurrency")}
              </label>
              <Input
                value={settings.base_currency}
                maxLength={3}
                onChange={(e) => setSettings({ ...settings, base_currency: e.target.value.toUpperCase() })}
              />
            </div>
            <div>
              <label className="mb-1.5 block px-4 text-[11px] font-medium uppercase tracking-[0.08em] text-zinc-500">
                {t("settings.defaultLanguage")}
              </label>
              <Select
                value={settings.default_locale}
                onChange={(e) => setSettings({ ...settings, default_locale: e.target.value })}
              >
                {LOCALES.map((l) => (
                  <option key={l} value={l}>
                    {LOCALE_LABELS[l]}
                  </option>
                ))}
              </Select>
            </div>
          </div>
          <div>
            <label className="mb-1.5 block px-4 text-[11px] font-medium uppercase tracking-[0.08em] text-zinc-500">
              {t("settings.timezone")}
            </label>
            <Input value={settings.timezone} onChange={(e) => setSettings({ ...settings, timezone: e.target.value })} />
          </div>
          <div className="border-t border-zinc-900/8 pt-4 dark:border-white/10">
            <h2 className="text-sm font-medium text-zinc-900 dark:text-zinc-50">{t("settings.payroll")}</h2>
            <p className="mt-1 text-xs text-zinc-500">{t("settings.payrollHint")}</p>
          </div>
          <div className="grid grid-cols-2 gap-4">
            <div>
              <label className="mb-1.5 block px-4 text-[11px] font-medium uppercase tracking-[0.08em] text-zinc-500">
                {t("settings.standardDayHours")}
              </label>
              <Input
                type="number"
                step="0.25"
                min="1"
                max="24"
                value={settings.standard_day_hours}
                onChange={(e) => setSettings({ ...settings, standard_day_hours: e.target.value })}
              />
            </div>
            <div>
              <label className="mb-1.5 block px-4 text-[11px] font-medium uppercase tracking-[0.08em] text-zinc-500">
                {t("settings.overtimeMultiplier")}
              </label>
              <Input
                type="number"
                step="0.05"
                min="1"
                max="5"
                value={settings.overtime_multiplier}
                onChange={(e) => setSettings({ ...settings, overtime_multiplier: e.target.value })}
              />
            </div>
          </div>
          <div>
            <label className="mb-1.5 block px-4 text-[11px] font-medium uppercase tracking-[0.08em] text-zinc-500">
              {t("settings.workingDayRule")}
            </label>
            <Select
              value={settings.working_day_rule}
              onChange={(e) => setSettings({ ...settings, working_day_rule: e.target.value })}
            >
              {WORKING_DAY_RULES.map((rule) => (
                <option key={rule} value={rule}>
                  {t(`workingDayRule.${rule}`)}
                </option>
              ))}
            </Select>
            <p className="mt-1 text-xs text-zinc-500">{t("settings.workingDayRuleHint")}</p>
          </div>
          <div>
            <label className="mb-1.5 block px-4 text-[11px] font-medium uppercase tracking-[0.08em] text-zinc-500">
              {t("settings.socialAssistanceAnnual")}
            </label>
            <Input
              type="number"
              step="0.01"
              min="0"
              value={settings.social_assistance_annual}
              onChange={(e) => setSettings({ ...settings, social_assistance_annual: e.target.value })}
            />
          </div>

          {message && <p className="text-sm text-green-600">{message}</p>}
          {error && <p className="text-sm text-red-600">{error}</p>}
          <Button type="submit" disabled={saving}>
            {saving ? t("common.saving") : t("common.save")}
          </Button>
        </form>
      </Card>

      <ModuleToggles />

      <TerminologyEditor />

      <VocabularyEditor />
    </div>
  );
}
