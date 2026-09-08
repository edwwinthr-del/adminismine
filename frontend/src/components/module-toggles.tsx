"use client";

import { useEffect, useRef, useState } from "react";
import { ApiError, apiFetch } from "@/lib/api";
import { useResource } from "@/lib/data/use-resource";
import { useAuth } from "@/lib/auth/context";
import { useI18n } from "@/lib/i18n/context";
import { Button } from "@/components/ui/button";
import { Card } from "@/components/ui/card";
import { Checkbox } from "@/components/ui/checkbox";

interface ModuleRow {
  key: string;
  enabled: boolean;
  /** How much is in there — what switching it off would hide. */
  records: number;
  /** Modules this one points at with a non-null foreign key. */
  requires: string[];
  /** Modules that point at this one. */
  required_by: string[];
}

/**
 * What this company has.
 *
 * Switching a module off hides it — its routes 404, it leaves the sidebar, the
 * reports list, the import picker and the dashboard — but nothing is deleted,
 * and switching it back on finds every record where it was left.
 *
 * The dependency arrows are followed here rather than left to the API's 422:
 * ticking attendance ticks workers and worksites too, and unticking workers
 * unticks everything that cannot exist without it. The operator sees the
 * consequence in the same click instead of being told about it afterwards.
 */
export function ModuleToggles() {
  const { t } = useI18n();
  const { hasPermission } = useAuth();
  const canManage = hasPermission("company.modules.manage");
  // The catalogue is behind the same permission as the toggles, so there is
  // nothing to fetch for somebody who cannot use it.
  const { data } = useResource<{ data: ModuleRow[] }>(
    canManage ? "/company-settings/modules" : null,
  );

  const [enabled, setEnabled] = useState<string[] | null>(null);
  const [saving, setSaving] = useState(false);
  const [message, setMessage] = useState<string | null>(null);
  const [error, setError] = useState<string | null>(null);

  // Seeded once: this is a draft the operator is editing, and re-seeding on a
  // background revalidation would discard ticks they have not saved yet.
  const seeded = useRef(false);
  const rows = data?.data ?? [];

  useEffect(() => {
    if (seeded.current || rows.length === 0) return;

    seeded.current = true;
    setEnabled(rows.filter((row) => row.enabled).map((row) => row.key));
  }, [rows]);

  if (!canManage || enabled === null) {
    return null;
  }

  function toggle(row: ModuleRow, on: boolean) {
    const current = new Set(enabled ?? []);

    if (on) {
      current.add(row.key);
      // Everything it points at has to exist for it to mean anything.
      row.requires.forEach((required) => current.add(required));
    } else {
      current.delete(row.key);
      // And everything that points at it goes with it.
      rows
        .filter((other) => other.requires.includes(row.key))
        .forEach((dependent) => current.delete(dependent.key));
    }

    setEnabled(rows.filter((r) => current.has(r.key)).map((r) => r.key));
  }

  async function save() {
    setSaving(true);
    setMessage(null);
    setError(null);
    try {
      await apiFetch("/company-settings/modules", { method: "PUT", json: { enabled_modules: enabled } });
      setMessage(t("settings.updated"));
    } catch (err) {
      setError(err instanceof ApiError ? err.message : "Error");
    } finally {
      setSaving(false);
    }
  }

  return (
    <Card>
      <h2 className="text-sm font-medium text-zinc-900 dark:text-zinc-50">{t("settings.modules")}</h2>
      <p className="mt-1 text-xs text-zinc-500">{t("settings.modulesHint")}</p>

      <div className="mt-4 space-y-1">
        {rows.map((row) => {
          const on = enabled.includes(row.key);

          return (
            <label
              key={row.key}
              className="flex cursor-pointer items-center justify-between gap-3 rounded-lg px-2 py-2 hover:bg-zinc-900/[0.03] dark:hover:bg-white/5"
            >
              <span className="flex items-center gap-3">
                <Checkbox size="sm" checked={on} onChange={(e) => toggle(row, e.target.checked)} />
                <span className="text-sm text-zinc-800 dark:text-zinc-200">{t(`module.${row.key}`)}</span>
              </span>
              <span className="text-xs text-zinc-500">
                {/* What would be hidden. Silence about it is how a toggle becomes a surprise. */}
                {row.records > 0 ? t("settings.moduleRecords", { count: row.records }) : t("settings.moduleEmpty")}
              </span>
            </label>
          );
        })}
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
