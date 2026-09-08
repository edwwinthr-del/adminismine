"use client";

import { useEffect, useRef, useState } from "react";
import { ApiError, apiFetch } from "@/lib/api";
import { useResource } from "@/lib/data/use-resource";
import { useAuth } from "@/lib/auth/context";
import { useI18n } from "@/lib/i18n/context";
import { useVocabularyLabel, type VocabularyValue } from "@/lib/vocabulary";
import { Button } from "@/components/ui/button";
import { Card } from "@/components/ui/card";
import { Checkbox } from "@/components/ui/checkbox";
import { Input } from "@/components/ui/input";

interface VocabularyRow {
  key: string;
  values: VocabularyValue[];
}

/**
 * The open-ended lists a company fills in for itself.
 *
 * Only the lists nothing branches on: `bauxite_ore` decides nothing, so a
 * construction firm can replace it with `concrete`. Attendance statuses and
 * movement categories are not here and never will be — those decide what the
 * app does.
 *
 * A stored value stays canonical snake_case whatever it is called on screen
 * (rule 4); the wording is set in Terminology, above.
 */
export function VocabularyEditor() {
  const { t } = useI18n();
  const { hasPermission } = useAuth();
  const canManage = hasPermission("company.vocabularies.manage");
  const vocabularyLabel = useVocabularyLabel();
  const { data } = useResource<{ data: VocabularyRow[] }>("/company-settings/vocabularies");

  const [draft, setDraft] = useState<Record<string, VocabularyValue[]> | null>(null);
  const [adding, setAdding] = useState<Record<string, string>>({});
  const [saving, setSaving] = useState<string | null>(null);
  const [message, setMessage] = useState<string | null>(null);
  const [error, setError] = useState<string | null>(null);

  // Seeded once: a background revalidation must not discard unsaved edits.
  const seeded = useRef(false);

  useEffect(() => {
    if (seeded.current || !data) return;

    seeded.current = true;
    setDraft(Object.fromEntries(data.data.map((row) => [row.key, row.values])));
  }, [data]);

  if (!canManage || !data || draft === null) return null;

  function setValues(vocabulary: string, values: VocabularyValue[]) {
    setDraft({ ...draft, [vocabulary]: values });
  }

  function add(vocabulary: string) {
    const raw = (adding[vocabulary] ?? "").trim();
    if (raw === "") return;

    // Shaped here as well as validated server-side, so the operator sees what
    // will be stored rather than a 422 about a regex.
    const value = raw.toLowerCase().replace(/[^a-z0-9]+/g, "_").replace(/^_+|_+$/g, "");
    if (value === "" || (draft?.[vocabulary] ?? []).some((v) => v.value === value)) return;

    setValues(vocabulary, [
      ...(draft?.[vocabulary] ?? []),
      { value, is_active: true, is_system: false, sort_order: (draft?.[vocabulary] ?? []).length, records: 0 },
    ]);
    setAdding({ ...adding, [vocabulary]: "" });
  }

  async function save(vocabulary: string) {
    setSaving(vocabulary);
    setMessage(null);
    setError(null);
    try {
      await apiFetch(`/company-settings/vocabularies/${vocabulary}`, {
        method: "PUT",
        json: {
          values: (draft?.[vocabulary] ?? []).map((v, index) => ({
            value: v.value,
            is_active: v.is_active,
            sort_order: index,
          })),
        },
      });
      setMessage(t("settings.updated"));
    } catch (err) {
      setError(err instanceof ApiError ? err.message : "Error");
    } finally {
      setSaving(null);
    }
  }

  return (
    <Card>
      <h2 className="text-sm font-medium text-zinc-900 dark:text-zinc-50">{t("settings.vocabularies")}</h2>
      <p className="mt-1 text-xs text-zinc-500">{t("settings.vocabulariesHint")}</p>

      <div className="mt-4 space-y-2">
        {data.data.map((row) => {
          const values = draft[row.key] ?? [];

          return (
            <details key={row.key} className="rounded-lg border border-zinc-900/8 dark:border-white/10">
              <summary className="cursor-pointer px-3 py-2 text-sm text-zinc-800 dark:text-zinc-200">
                {t(`vocabulary.${row.key}`)}
                <span className="ml-2 text-xs text-zinc-500">
                  {t("settings.vocabularyCount", { count: values.filter((v) => v.is_active).length })}
                </span>
              </summary>

              <div className="space-y-1 px-3 pb-3 pt-1">
                {values.map((value, index) => (
                  <div key={value.value} className="flex items-center justify-between gap-3 py-1">
                    <label className="flex cursor-pointer items-center gap-3">
                      <Checkbox
                        size="sm"
                        checked={value.is_active}
                        onChange={(e) =>
                          setValues(
                            row.key,
                            values.map((v, i) => (i === index ? { ...v, is_active: e.target.checked } : v)),
                          )
                        }
                      />
                      <span className="text-sm text-zinc-800 dark:text-zinc-200">
                        {vocabularyLabel(row.key, row.key, value.value)}
                      </span>
                      {/* The stored value, which is what reports group by and
                          the importer normalises onto — visible so nobody has
                          to guess what a renamed label is underneath. */}
                      <code className="text-[11px] text-zinc-500 dark:text-zinc-400">{value.value}</code>
                    </label>

                    <span className="flex items-center gap-3 text-xs text-zinc-500">
                      {value.records > 0 && t("settings.vocabularyRecords", { count: value.records })}
                      {/* Shipped values and values in use can be switched off,
                          never removed: code and rows point at them. */}
                      {!value.is_system && value.records === 0 && (
                        <button
                          type="button"
                          className="text-red-600"
                          onClick={() => setValues(row.key, values.filter((_, i) => i !== index))}
                        >
                          {t("common.remove")}
                        </button>
                      )}
                    </span>
                  </div>
                ))}

                <div className="flex items-center gap-2 pt-2">
                  <Input
                    className="max-w-xs"
                    value={adding[row.key] ?? ""}
                    onChange={(e) => setAdding({ ...adding, [row.key]: e.target.value })}
                    placeholder={t("settings.vocabularyNew")}
                  />
                  <Button type="button" variant="secondary" className="h-9 px-3" onClick={() => add(row.key)}>
                    {t("common.add")}
                  </Button>
                  <Button
                    type="button"
                    className="h-9 px-3"
                    disabled={saving === row.key}
                    onClick={() => save(row.key)}
                  >
                    {saving === row.key ? t("common.saving") : t("common.save")}
                  </Button>
                </div>
              </div>
            </details>
          );
        })}
      </div>

      {message && <p className="mt-3 text-sm text-green-600">{message}</p>}
      {error && <p className="mt-3 text-sm text-red-600">{error}</p>}
    </Card>
  );
}
