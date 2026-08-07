"use client";

import { useState } from "react";
import { apiFetch, errorMessage } from "@/lib/api";
import { useResource, withQuery } from "@/lib/data/use-resource";
import { useI18n } from "@/lib/i18n/context";
import { useDebouncedValue } from "@/lib/use-debounced-value";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Card } from "@/components/ui/card";
import { Input } from "@/components/ui/input";
import { Modal } from "@/components/ui/modal";
import { Select } from "@/components/ui/select";

interface Mine {
  id: number;
  name: string;
  code: string | null;
  location: string | null;
  material_type: string | null;
  is_active: boolean;
  worksite_count?: number;
  notes: string | null;
}

/** Canonical stored values; the labels beside them are translated, not the data. */
const MATERIALS = ["bauxite_ore", "overburden", "limestone", "other"] as const;

export default function MinesPage() {
  const { t } = useI18n();
  const [search, setSearch] = useState("");
  const [creating, setCreating] = useState(false);
  const [editing, setEditing] = useState<Mine | null>(null);

  const debouncedSearch = useDebouncedValue(search);

  const { data, loading, error } = useResource<{ data: Mine[] }>(
    withQuery("/mines", { search: debouncedSearch }),
  );
  const mines = data?.data ?? [];

  async function remove(mine: Mine) {
    if (!window.confirm(t("mines.removeConfirm", { name: mine.name }))) return;
    await apiFetch(`/mines/${mine.id}`, { method: "DELETE" });
  }

  return (
    <div className="space-y-5">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <div>
          <h1 className="text-[2.5rem] font-light leading-none tracking-[-0.02em] text-zinc-900 dark:text-zinc-50">{t("mines.title")}</h1>
          <p className="text-sm text-zinc-500">{t("mines.subtitle")}</p>
        </div>
        <Button onClick={() => setCreating(true)}>{t("mines.new")}</Button>
      </div>

      <Card className="p-4">
        <Input
          className="max-w-xs"
          placeholder={t("mines.search")}
          value={search}
          onChange={(e) => setSearch(e.target.value)}
        />
      </Card>

      {error && <p className="text-sm text-red-600">{error}</p>}

      <Card className="table-quiet overflow-x-auto p-0">
        <table className="w-full min-w-[760px] text-sm">
          <thead className="border-b border-zinc-900/8 text-left text-[11px] uppercase tracking-[0.1em] text-zinc-500 dark:border-white/10">
            <tr>
              <th className="px-4 py-3">{t("mines.name")}</th>
              <th className="px-4 py-3">{t("mines.code")}</th>
              <th className="px-4 py-3">{t("mines.location")}</th>
              <th className="px-4 py-3">{t("mines.material")}</th>
              <th className="px-4 py-3 text-right">{t("mines.worksites")}</th>
              <th className="px-4 py-3">{t("mines.status")}</th>
              <th className="px-4 py-3 text-right">{t("mines.actions")}</th>
            </tr>
          </thead>
          <tbody className="divide-y divide-zinc-900/5 dark:divide-white/8">
            {loading ? (
              <tr>
                <td colSpan={7} className="px-4 py-14 text-center text-sm text-zinc-500">
                  {t("common.loading")}
                </td>
              </tr>
            ) : mines.length === 0 ? (
              <tr>
                <td colSpan={7} className="px-4 py-14 text-center text-sm text-zinc-500">
                  {t("mines.none")}
                </td>
              </tr>
            ) : (
              mines.map((mine) => (
                <tr key={mine.id} className="text-zinc-800 dark:text-zinc-200">
                  <td className="px-4 py-3 font-medium">{mine.name}</td>
                  <td className="px-4 py-3">{mine.code ?? "—"}</td>
                  <td className="px-4 py-3">{mine.location ?? "—"}</td>
                  <td className="px-4 py-3">
                    {mine.material_type ? t(`material.${mine.material_type}`) : "—"}
                  </td>
                  <td className="px-4 py-3 text-right tabular-nums">{mine.worksite_count ?? 0}</td>
                  <td className="px-4 py-3">
                    <Badge tone={mine.is_active ? "green" : "gray"}>
                      {mine.is_active ? t("mines.active") : t("mines.inactive")}
                    </Badge>
                  </td>
                  <td className="px-4 py-3 text-right">
                    <div className="flex justify-end gap-2">
                      <Button variant="secondary" className="h-8 px-3" onClick={() => setEditing(mine)}>
                        {t("common.edit")}
                      </Button>
                      <Button variant="secondary" className="h-8 px-3" onClick={() => void remove(mine)}>
                        {t("common.delete")}
                      </Button>
                    </div>
                  </td>
                </tr>
              ))
            )}
          </tbody>
        </table>
      </Card>

      {creating && <MineModal onClose={() => setCreating(false)} />}
      {editing && <MineModal mine={editing} onClose={() => setEditing(null)} />}
    </div>
  );
}

function MineModal({ mine, onClose }: { mine?: Mine; onClose: () => void }) {
  const { t } = useI18n();
  const [name, setName] = useState(mine?.name ?? "");
  const [code, setCode] = useState(mine?.code ?? "");
  const [location, setLocation] = useState(mine?.location ?? "");
  const [material, setMaterial] = useState(mine?.material_type ?? "bauxite_ore");
  const [isActive, setIsActive] = useState(mine?.is_active ?? true);
  const [notes, setNotes] = useState(mine?.notes ?? "");
  const [error, setError] = useState<string | null>(null);
  const [saving, setSaving] = useState(false);

  async function submit(e: React.FormEvent) {
    e.preventDefault();
    setSaving(true);
    setError(null);
    try {
      await apiFetch(mine ? `/mines/${mine.id}` : "/mines", {
        method: mine ? "PUT" : "POST",
        json: {
          name: name.trim(),
          code: code.trim() || null,
          location: location.trim() || null,
          material_type: material || null,
          is_active: isActive,
          notes: notes.trim() || null,
        },
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
    <Modal open onClose={onClose} title={mine ? t("mines.edit") : t("mines.new")}>
      <form onSubmit={submit} className="space-y-4">
        <div className="grid grid-cols-2 gap-4">
          <div>
            <label className={label}>{t("mines.name")}</label>
            <Input value={name} onChange={(e) => setName(e.target.value)} required />
          </div>
          <div>
            <label className={label}>{t("mines.code")}</label>
            <Input value={code} onChange={(e) => setCode(e.target.value)} />
          </div>
        </div>
        <div className="grid grid-cols-2 gap-4">
          <div>
            <label className={label}>{t("mines.location")}</label>
            <Input value={location} onChange={(e) => setLocation(e.target.value)} />
          </div>
          <div>
            <label className={label}>{t("mines.material")}</label>
            <Select value={material} onChange={(e) => setMaterial(e.target.value)}>
              {MATERIALS.map((value) => (
                <option key={value} value={value}>
                  {t(`material.${value}`)}
                </option>
              ))}
            </Select>
          </div>
        </div>
        <div>
          <label className={label}>{t("mines.notes")}</label>
          <Input value={notes} onChange={(e) => setNotes(e.target.value)} />
        </div>
        <label className="flex items-center gap-2 text-sm text-zinc-600 dark:text-zinc-300">
          <input type="checkbox" checked={isActive} onChange={(e) => setIsActive(e.target.checked)} />
          {t("mines.active")}
        </label>

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
