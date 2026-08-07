"use client";

import { useEffect, useRef, useState } from "react";
import { ApiError, apiFetch } from "@/lib/api";
import { useResource } from "@/lib/data/use-resource";
import { Button } from "@/components/ui/button";
import { Card } from "@/components/ui/card";
import { Input } from "@/components/ui/input";
import { useI18n } from "@/lib/i18n/context";
import { LOCALE_LABELS, LOCALES } from "@/lib/i18n/dictionaries";

interface CompanySettings {
  company_name: string;
  base_currency: string;
  default_locale: string;
  timezone: string;
}

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
      const res = await apiFetch<{ data: CompanySettings }>("/company-settings", {
        method: "PUT",
        json: {
          company_name: settings.company_name,
          base_currency: settings.base_currency,
          default_locale: settings.default_locale,
          timezone: settings.timezone,
        },
      });
      setSettings(res.data);
      setMessage(t("settings.updated"));
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
      <h1 className="text-[2.5rem] font-light leading-none tracking-[-0.02em] text-zinc-900 dark:text-zinc-50">{t("settings.title")}</h1>
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
              <select
                value={settings.default_locale}
                onChange={(e) => setSettings({ ...settings, default_locale: e.target.value })}
                className="h-10 w-full rounded-md border border-zinc-300 bg-white px-3 text-sm dark:border-zinc-700 dark:bg-zinc-900 dark:text-zinc-100"
              >
                {LOCALES.map((l) => (
                  <option key={l} value={l}>
                    {LOCALE_LABELS[l]}
                  </option>
                ))}
              </select>
            </div>
          </div>
          <div>
            <label className="mb-1.5 block px-4 text-[11px] font-medium uppercase tracking-[0.08em] text-zinc-500">
              {t("settings.timezone")}
            </label>
            <Input value={settings.timezone} onChange={(e) => setSettings({ ...settings, timezone: e.target.value })} />
          </div>
          {message && <p className="text-sm text-green-600">{message}</p>}
          {error && <p className="text-sm text-red-600">{error}</p>}
          <Button type="submit" disabled={saving}>
            {saving ? t("common.saving") : t("common.save")}
          </Button>
        </form>
      </Card>
    </div>
  );
}
