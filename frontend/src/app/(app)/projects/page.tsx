"use client";

import { useState } from "react";
import { apiFetch, errorMessage } from "@/lib/api";
import { useResource, withQuery } from "@/lib/data/use-resource";
import { useI18n } from "@/lib/i18n/context";
import { formatDate } from "@/lib/format";
import { useDebouncedValue } from "@/lib/use-debounced-value";
import { AsyncSelect } from "@/components/ui/async-select";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Card } from "@/components/ui/card";
import { Input } from "@/components/ui/input";
import { Modal } from "@/components/ui/modal";

interface Project {
  id: number;
  name: string;
  code: string | null;
  client_id: number | null;
  client?: { id: number; name: string } | null;
  start_date: string | null;
  end_date: string | null;
  is_active: boolean;
  worksite_count?: number;
  notes: string | null;
}

export default function ProjectsPage() {
  const { t } = useI18n();
  const [search, setSearch] = useState("");
  const [creating, setCreating] = useState(false);
  const [editing, setEditing] = useState<Project | null>(null);

  const debouncedSearch = useDebouncedValue(search);

  const { data, loading, error } = useResource<{ data: Project[] }>(
    withQuery("/projects", { search: debouncedSearch }),
  );
  const projects = data?.data ?? [];

  async function remove(project: Project) {
    if (!window.confirm(t("projects.removeConfirm", { name: project.name }))) return;
    await apiFetch(`/projects/${project.id}`, { method: "DELETE" });
  }

  return (
    <div className="space-y-5">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <div>
          <h1 className="text-[2.5rem] font-light leading-none tracking-[-0.02em] text-zinc-900 dark:text-zinc-50">{t("projects.title")}</h1>
          <p className="text-sm text-zinc-500">{t("projects.subtitle")}</p>
        </div>
        <Button onClick={() => setCreating(true)}>{t("projects.new")}</Button>
      </div>

      <Card className="p-4">
        <Input
          className="max-w-xs"
          placeholder={t("projects.search")}
          value={search}
          onChange={(e) => setSearch(e.target.value)}
        />
      </Card>

      {error && <p className="text-sm text-red-600">{error}</p>}

      <Card className="table-quiet overflow-x-auto p-0">
        <table className="w-full min-w-[860px] text-sm">
          <thead className="border-b border-zinc-900/8 text-left text-[11px] uppercase tracking-[0.1em] text-zinc-500 dark:border-white/10">
            <tr>
              <th className="px-4 py-3">{t("projects.name")}</th>
              <th className="px-4 py-3">{t("projects.code")}</th>
              <th className="px-4 py-3">{t("projects.client")}</th>
              <th className="px-4 py-3">{t("projects.start")}</th>
              <th className="px-4 py-3">{t("projects.end")}</th>
              <th className="px-4 py-3 text-right">{t("projects.worksites")}</th>
              <th className="px-4 py-3">{t("projects.status")}</th>
              <th className="px-4 py-3 text-right">{t("common.actions")}</th>
            </tr>
          </thead>
          <tbody className="divide-y divide-zinc-900/5 dark:divide-white/8">
            {loading ? (
              <tr>
                <td colSpan={8} className="px-4 py-14 text-center text-sm text-zinc-500">
                  {t("common.loading")}
                </td>
              </tr>
            ) : projects.length === 0 ? (
              <tr>
                <td colSpan={8} className="px-4 py-14 text-center text-sm text-zinc-500">
                  {t("projects.none")}
                </td>
              </tr>
            ) : (
              projects.map((project) => (
                <tr key={project.id} className="text-zinc-800 dark:text-zinc-200">
                  <td className="px-4 py-3 font-medium">{project.name}</td>
                  <td className="px-4 py-3">{project.code ?? "—"}</td>
                  <td className="px-4 py-3">{project.client?.name ?? "—"}</td>
                  <td className="px-4 py-3">{formatDate(project.start_date)}</td>
                  <td className="px-4 py-3">{formatDate(project.end_date)}</td>
                  <td className="px-4 py-3 text-right tabular-nums">{project.worksite_count ?? 0}</td>
                  <td className="px-4 py-3">
                    <Badge tone={project.is_active ? "green" : "gray"}>
                      {project.is_active ? t("projects.active") : t("projects.inactive")}
                    </Badge>
                  </td>
                  <td className="px-4 py-3 text-right">
                    <div className="flex justify-end gap-2">
                      <Button variant="secondary" className="h-8 px-3" onClick={() => setEditing(project)}>
                        {t("common.edit")}
                      </Button>
                      <Button variant="secondary" className="h-8 px-3" onClick={() => void remove(project)}>
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

      {creating && <ProjectModal onClose={() => setCreating(false)} />}
      {editing && <ProjectModal project={editing} onClose={() => setEditing(null)} />}
    </div>
  );
}

function ProjectModal({ project, onClose }: { project?: Project; onClose: () => void }) {
  const { t } = useI18n();
  const [name, setName] = useState(project?.name ?? "");
  const [code, setCode] = useState(project?.code ?? "");
  const [clientId, setClientId] = useState<number | null>(project?.client_id ?? null);
  const [startDate, setStartDate] = useState(project?.start_date ?? "");
  const [endDate, setEndDate] = useState(project?.end_date ?? "");
  const [isActive, setIsActive] = useState(project?.is_active ?? true);
  const [notes, setNotes] = useState(project?.notes ?? "");
  const [error, setError] = useState<string | null>(null);
  const [saving, setSaving] = useState(false);

  async function submit(e: React.FormEvent) {
    e.preventDefault();
    setSaving(true);
    setError(null);
    try {
      await apiFetch(project ? `/projects/${project.id}` : "/projects", {
        method: project ? "PUT" : "POST",
        json: {
          name: name.trim(),
          code: code.trim() || null,
          client_id: clientId,
          start_date: startDate || null,
          end_date: endDate || null,
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
    <Modal open onClose={onClose} title={project ? t("projects.edit") : t("projects.new")}>
      <form onSubmit={submit} className="space-y-4">
        <div className="grid grid-cols-2 gap-4">
          <div>
            <label className={label}>{t("projects.name")}</label>
            <Input value={name} onChange={(e) => setName(e.target.value)} required />
          </div>
          <div>
            <label className={label}>{t("projects.code")}</label>
            <Input value={code} onChange={(e) => setCode(e.target.value)} />
          </div>
        </div>
        <div>
          <label className={label}>{t("projects.client")}</label>
          <AsyncSelect
            resource="clients"
            value={clientId}
            onChange={(value) => setClientId(value)}
            emptyLabel={t("projects.noClient")}
          />
        </div>
        <div className="grid grid-cols-2 gap-4">
          <div>
            <label className={label}>{t("projects.start")}</label>
            <Input type="date" value={startDate} onChange={(e) => setStartDate(e.target.value)} />
          </div>
          <div>
            <label className={label}>{t("projects.end")}</label>
            <Input type="date" value={endDate} onChange={(e) => setEndDate(e.target.value)} />
          </div>
        </div>
        <div>
          <label className={label}>{t("projects.notes")}</label>
          <Input value={notes} onChange={(e) => setNotes(e.target.value)} />
        </div>
        <label className="flex items-center gap-2 text-sm text-zinc-600 dark:text-zinc-300">
          <input type="checkbox" checked={isActive} onChange={(e) => setIsActive(e.target.checked)} />
          {t("projects.active")}
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
