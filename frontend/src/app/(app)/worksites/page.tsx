"use client";

import { useEffect, useRef, useState } from "react";
import { ApiError, apiFetch } from "@/lib/api";
import { useResource, withQuery } from "@/lib/data/use-resource";
import { useEmployeeOptions, type EmployeeOption } from "@/lib/data/use-options";
import { useI18n } from "@/lib/i18n/context";
import { useDebouncedValue } from "@/lib/use-debounced-value";
import { AsyncSelect } from "@/components/ui/async-select";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Card } from "@/components/ui/card";
import { Input } from "@/components/ui/input";
import { Modal } from "@/components/ui/modal";
import { Checkbox } from "@/components/ui/checkbox";

interface Worksite {
  id: number;
  name: string;
  location: string | null;
  mine_id: number | null;
  mine?: { id: number; name: string } | null;
  project_id: number | null;
  project?: { id: number; name: string } | null;
  client_id: number | null;
  client?: { id: number; name: string } | null;
  is_active: boolean;
  employee_count?: number;
  master_count?: number;
  employees?: { id: number; full_name: string }[];
  notes: string | null;
}

export default function WorksitesPage() {
  const { t } = useI18n();
  const [search, setSearch] = useState("");
  const [creating, setCreating] = useState(false);
  const [editing, setEditing] = useState<Worksite | null>(null);
  const [assigning, setAssigning] = useState<Worksite | null>(null);

  const debouncedSearch = useDebouncedValue(search);

  const { data, loading } = useResource<{ data: Worksite[] }>(
    withQuery("/worksites", { search: debouncedSearch }),
  );
  const worksites = data?.data ?? [];

  const { options: employees } = useEmployeeOptions();

  // No manual refetch: apiFetch's markMutated() revalidates every mounted
  // resource, this list included.
  async function remove(worksite: Worksite) {
    if (!window.confirm(t("worksites.removeConfirm", { name: worksite.name }))) return;
    await apiFetch(`/worksites/${worksite.id}`, { method: "DELETE" });
  }

  return (
    <div className="space-y-5">
      <div className="flex items-center justify-between">
        <h1 className="text-[2.5rem] font-light leading-none tracking-[-0.02em] text-zinc-900 dark:text-zinc-50">{t("worksites.title")}</h1>
        <Button onClick={() => setCreating(true)}>{t("worksites.new")}</Button>
      </div>

      <Card className="p-4">
        <Input
          className="max-w-xs"
          placeholder={t("worksites.search")}
          value={search}
          onChange={(e) => setSearch(e.target.value)}
        />
      </Card>

      <Card className="table-quiet scroll-quiet overflow-x-auto p-0">
        <table className="w-full min-w-[820px] text-sm">
          <thead className="border-b border-zinc-900/8 text-left text-[11px] uppercase tracking-[0.1em] text-zinc-500 dark:border-white/10">
            <tr>
              <th className="px-4 py-3">{t("worksites.name")}</th>
              <th className="px-4 py-3">{t("worksites.location")}</th>
              <th className="px-4 py-3">{t("worksites.mine")}</th>
              <th className="px-4 py-3">{t("worksites.project")}</th>
              <th className="px-4 py-3">{t("worksites.client")}</th>
              <th className="px-4 py-3 text-right">{t("worksites.workers")}</th>
              <th className="px-4 py-3 text-right">{t("worksites.masters")}</th>
              <th className="px-4 py-3">{t("worksites.status")}</th>
              <th className="px-4 py-3 text-right">{t("worksites.actions")}</th>
            </tr>
          </thead>
          <tbody className="divide-y divide-zinc-900/5 dark:divide-white/8">
            {loading ? (
              <tr>
                <td colSpan={9} className="px-4 py-14 text-center text-sm text-zinc-500">
                  {t("common.loading")}
                </td>
              </tr>
            ) : worksites.length === 0 ? (
              <tr>
                <td colSpan={9} className="px-4 py-14 text-center text-sm text-zinc-500">
                  {t("worksites.none")}
                </td>
              </tr>
            ) : (
              worksites.map((worksite) => (
                <tr key={worksite.id} className="text-zinc-800 dark:text-zinc-200">
                  <td className="px-4 py-3 font-medium">{worksite.name}</td>
                  <td className="px-4 py-3">{worksite.location ?? "—"}</td>
                  <td className="px-4 py-3">{worksite.mine?.name ?? "—"}</td>
                  <td className="px-4 py-3">{worksite.project?.name ?? "—"}</td>
                  <td className="px-4 py-3">{worksite.client?.name ?? "—"}</td>
                  <td className="px-4 py-3 text-right tabular-nums">{worksite.employee_count ?? 0}</td>
                  <td className="px-4 py-3 text-right tabular-nums">{worksite.master_count ?? 0}</td>
                  <td className="px-4 py-3">
                    <Badge tone={worksite.is_active ? "green" : "gray"}>
                      {worksite.is_active ? t("worksites.active") : t("worksites.inactive")}
                    </Badge>
                  </td>
                  <td className="px-4 py-3 text-right">
                    <div className="flex justify-end gap-2">
                      <Button variant="secondary" className="h-8 px-3" onClick={() => setAssigning(worksite)}>
                        {t("worksites.roster")}
                      </Button>
                      <Button variant="secondary" className="h-8 px-3" onClick={() => setEditing(worksite)}>
                        {t("worksites.edit")}
                      </Button>
                      <Button variant="secondary" className="h-8 px-3" onClick={() => void remove(worksite)}>
                        {t("worksites.remove")}
                      </Button>
                    </div>
                  </td>
                </tr>
              ))
            )}
          </tbody>
        </table>
      </Card>

      {creating && <WorksiteModal onClose={() => setCreating(false)} />}
      {editing && <WorksiteModal worksite={editing} onClose={() => setEditing(null)} />}
      {assigning && (
        <RosterModal
          worksite={assigning}
          employees={employees}
          onClose={() => setAssigning(null)}
        />
      )}
    </div>
  );
}

function WorksiteModal({
  worksite,
  onClose,
}: {
  worksite?: Worksite;
  onClose: () => void;
}) {
  const { t } = useI18n();
  const [name, setName] = useState(worksite?.name ?? "");
  const [location, setLocation] = useState(worksite?.location ?? "");
  const [mineId, setMineId] = useState<number | null>(worksite?.mine_id ?? null);
  const [projectId, setProjectId] = useState<number | null>(worksite?.project_id ?? null);
  const [clientId, setClientId] = useState<number | null>(worksite?.client_id ?? null);
  const [isActive, setIsActive] = useState(worksite?.is_active ?? true);
  const [notes, setNotes] = useState(worksite?.notes ?? "");
  const [error, setError] = useState<string | null>(null);
  const [saving, setSaving] = useState(false);

  async function submit(e: React.FormEvent) {
    e.preventDefault();
    setSaving(true);
    setError(null);
    try {
      await apiFetch(worksite ? `/worksites/${worksite.id}` : "/worksites", {
        method: worksite ? "PUT" : "POST",
        json: {
          name: name.trim(),
          location: location.trim() || null,
          mine_id: mineId,
          project_id: projectId,
          client_id: clientId,
          is_active: isActive,
          notes: notes.trim() || null,
        },
      });
      onClose();
    } catch (err) {
      setError(err instanceof ApiError ? err.message : "Error");
    } finally {
      setSaving(false);
    }
  }

  const label = "mb-1.5 block px-4 text-[11px] font-medium uppercase tracking-[0.08em] text-zinc-500";

  return (
    <Modal open onClose={onClose} title={worksite ? t("worksites.edit") : t("worksites.new")}>
      <form onSubmit={submit} className="space-y-4">
        <div>
          <label className={label}>{t("worksites.name")}</label>
          <Input value={name} onChange={(e) => setName(e.target.value)} required />
        </div>
        <div>
          <label className={label}>{t("worksites.location")}</label>
          <Input value={location} onChange={(e) => setLocation(e.target.value)} />
        </div>
        <div className="grid grid-cols-2 gap-4">
          <div>
            <label className={label}>{t("worksites.mine")}</label>
            <AsyncSelect
              resource="mines"
              value={mineId}
              onChange={(value) => setMineId(value)}
              emptyLabel={t("worksites.noMine")}
            />
          </div>
          <div>
            <label className={label}>{t("worksites.project")}</label>
            <AsyncSelect
              resource="projects"
              value={projectId}
              onChange={(value) => setProjectId(value)}
              emptyLabel={t("worksites.noProject")}
            />
          </div>
        </div>
        <div>
          <label className={label}>{t("worksites.client")}</label>
          <AsyncSelect
            resource="clients"
            value={clientId}
            onChange={(value) => setClientId(value)}
            emptyLabel={t("worksites.noClient")}
          />
        </div>
        <div>
          <label className={label}>{t("worksites.notes")}</label>
          <Input value={notes} onChange={(e) => setNotes(e.target.value)} />
        </div>
        <label className="flex cursor-pointer items-center gap-2 text-sm text-zinc-600 dark:text-zinc-300">
          <Checkbox
              size="sm"
              checked={isActive}
              onChange={(e) => setIsActive(e.target.checked)}
          />
          <span>{t("worksites.active")}</span>
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

/** The roster decides who shows up on the attendance entry screen for this site. */
function RosterModal({
  worksite,
  employees,
  onClose,
}: {
  worksite: Worksite;
  employees: EmployeeOption[];
  onClose: () => void;
}) {
  const { t } = useI18n();
  const [selected, setSelected] = useState<number[]>([]);
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const { data, loading } = useResource<{ data: Worksite }>(`/worksites/${worksite.id}`);

  // Seed the draft from the server exactly once. `useResource` revalidates — on
  // refocus, or after any write — and re-seeding on each of those would discard
  // the ticks the user has made but not yet saved.
  const seeded = useRef(false);

  useEffect(() => {
    if (seeded.current || !data) return;

    seeded.current = true;
    setSelected((data.data.employees ?? []).map((employee) => employee.id));
  }, [data]);

  function toggle(id: number) {
    setSelected((prev) => (prev.includes(id) ? prev.filter((value) => value !== id) : [...prev, id]));
  }

  async function submit() {
    setSaving(true);
    setError(null);
    try {
      await apiFetch(`/worksites/${worksite.id}/employees`, {
        method: "PUT",
        json: { employees: selected.map((id) => ({ employee_id: id })) },
      });
      onClose();
    } catch (err) {
      setError(err instanceof ApiError ? err.message : "Error");
    } finally {
      setSaving(false);
    }
  }

  return (
    <Modal open onClose={onClose} title={`${t("worksites.roster")} — ${worksite.name}`}>
      <div className="space-y-4">
        {loading ? (
          <p className="text-sm text-zinc-500">{t("common.loading")}</p>
        ) : employees.length === 0 ? (
          <p className="text-sm text-zinc-500">{t("worksites.noWorkers")}</p>
        ) : (
          <ul className="max-h-72 space-y-1 overflow-y-auto">
            {employees.map((employee) => (
              <li key={employee.id}>
                <label className="flex cursor-pointer items-center gap-2 text-sm text-zinc-700 dark:text-zinc-200">
                  <Checkbox
                      size="sm"
                      checked={selected.includes(employee.id)}
                      onChange={() => toggle(employee.id)}
                  />

                  <span>{employee.full_name}{employee.job_role && (
                      <span className="ml-1 text-xs text-zinc-500">· {employee.job_role}</span>
                  )}</span>
                </label>
              </li>
            ))}
          </ul>
        )}
        {error && <p className="text-sm text-red-600">{error}</p>}
        <div className="flex justify-end gap-2">
          <Button type="button" variant="secondary" onClick={onClose}>
            {t("common.cancel")}
          </Button>
          <Button onClick={() => void submit()} disabled={saving || loading}>
            {saving ? t("common.saving") : t("common.save")}
          </Button>
        </div>
      </div>
    </Modal>
  );
}
