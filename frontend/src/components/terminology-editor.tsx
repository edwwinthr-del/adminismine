"use client";

import { useEffect, useRef, useState } from "react";
import { ApiError, apiFetch } from "@/lib/api";
import { useResource } from "@/lib/data/use-resource";
import { useAuth } from "@/lib/auth/context";
import { useI18n } from "@/lib/i18n/context";
import { dictionaries, LOCALE_LABELS, type Locale } from "@/lib/i18n/dictionaries";
import { Button } from "@/components/ui/button";
import { Card } from "@/components/ui/card";
import { Input } from "@/components/ui/input";

type Terms = Record<string, Partial<Record<Locale, string>>>;

interface TerminologyResponse {
  data: Terms;
  /**
   * The keys a company may rename, grouped by domain then by term — anything
   * else is refused by the API. Grouping is what keeps this a list of terms
   * rather than a wall of eighty boxes.
   */
  groups: Record<string, Record<string, string[]>>;
  locales: Locale[];
}

/**
 * What this company calls the things the app models.
 *
 * The app's structure fits a construction firm as well as a mine — a deposit, a
 * billed job, a place people clock in — and only the words are wrong. So the
 * words are editable and nothing else is: the route stays `/mines`, the table
 * stays `rudnici`, the permission stays `worksites.manage`.
 *
 * The built-in wording is the placeholder rather than the value, so an empty box
 * reads as "we have not renamed this" and clearing one puts the original back.
 */
export function TerminologyEditor() {
  const { t } = useI18n();
  const { hasPermission } = useAuth();
  const canManage = hasPermission("company.terminology.manage");
  const { data } = useResource<TerminologyResponse>("/company-settings/terminology");

  const [draft, setDraft] = useState<Terms | null>(null);
  const [saving, setSaving] = useState(false);
  const [message, setMessage] = useState<string | null>(null);
  const [error, setError] = useState<string | null>(null);

  // Seeded once: a background revalidation must not wipe words being typed.
  const seeded = useRef(false);

  useEffect(() => {
    if (seeded.current || !data) return;

    seeded.current = true;
    setDraft(data.data);
  }, [data]);

  if (!canManage || !data || draft === null) return null;

  const locales = data.locales;

  function set(key: string, locale: Locale, value: string) {
    setDraft({ ...draft, [key]: { ...(draft?.[key] ?? {}), [locale]: value } });
  }

  async function save() {
    setSaving(true);
    setMessage(null);
    setError(null);
    try {
      const res = await apiFetch<{ changed: number }>("/company-settings/terminology", {
        method: "PUT",
        json: { terms: draft },
      });
      setMessage(t("settings.termsSaved", { count: res.changed }));
    } catch (err) {
      setError(err instanceof ApiError ? err.message : "Error");
    } finally {
      setSaving(false);
    }
  }

  return (
    <Card>
      <h2 className="text-sm font-medium text-zinc-900 dark:text-zinc-50">{t("settings.terminology")}</h2>
      <p className="mt-1 text-xs text-zinc-500">{t("settings.terminologyHint")}</p>

      <div className="scroll-quiet mt-4 max-h-[32rem] space-y-5 overflow-y-auto pr-1">
        {Object.entries(data.groups).map(([group, terms]) => (
          <div key={group}>
            <p className="mb-2 text-[11px] font-semibold uppercase tracking-[0.1em] text-zinc-500 dark:text-zinc-400">{group}</p>

            {Object.entries(terms).map(([term, keys]) => (
              <details key={term} className="mb-1 rounded-lg border border-zinc-900/8 dark:border-white/10">
                <summary className="cursor-pointer px-3 py-2 text-sm text-zinc-800 dark:text-zinc-200">
                  {/* The term's own heading names the group, and its built-in
                      English wording says what is inside without opening it. */}
                  {dictionaries.en[keys[0]] ?? term}
                  <span className="ml-2 text-xs text-zinc-500">
                    {keys.filter((key) => locales.some((l) => (draft?.[key]?.[l] ?? "") !== "")).length > 0
                      ? t("settings.termRenamed")
                      : ""}
                  </span>
                </summary>

                <div className="space-y-3 px-3 pb-3 pt-1">
                  {keys.map((key) => (
                    <div key={key}>
                      <label className="mb-1 block px-1 text-[11px] font-medium uppercase tracking-[0.08em] text-zinc-500">
                        {dictionaries.en[key] ?? key}
                      </label>
                      <div className="grid grid-cols-3 gap-2">
                        {locales.map((locale) => (
                          <Input
                            key={locale}
                            value={draft[key]?.[locale] ?? ""}
                            onChange={(e) => set(key, locale, e.target.value)}
                            placeholder={dictionaries[locale][key] ?? key}
                            aria-label={`${key} ${LOCALE_LABELS[locale]}`}
                          />
                        ))}
                      </div>
                    </div>
                  ))}
                </div>
              </details>
            ))}
          </div>
        ))}
      </div>

      {message && <p className="mt-3 text-sm text-green-600">{message}</p>}
      {error && <p className="mt-3 text-sm text-red-600">{error}</p>}

      <div className="mt-4">
        <Button type="button" onClick={save} disabled={saving}>
          {saving ? t("common.saving") : t("common.save")}
        </Button>
      </div>
    </Card>
  );
}
