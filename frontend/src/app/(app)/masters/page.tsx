"use client";

import { useState } from "react";
import { ApiError, apiFetch } from "@/lib/api";
import { useResource } from "@/lib/data/use-resource";
import {
  useEmployeeOptions,
  useUserOptions,
  useWorksiteOptions,
  type EmployeeOption,
  type UserOption,
  type WorksiteOption,
} from "@/lib/data/use-options";
import { useAuth } from "@/lib/auth/context";
import { useI18n } from "@/lib/i18n/context";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Card } from "@/components/ui/card";
import { Modal } from "@/components/ui/modal";
import { Select } from "@/components/ui/select";
import { Checkbox } from "@/components/ui/checkbox";

interface Master {
  id: number;
  employee_id: number;
  employee?: { id: number; full_name: string; job_role: string | null };
  user_id: number | null;
  user?: { id: number; name: string; email: string } | null;
  is_active: boolean;
  worksites?: WorksiteOption[];
  notes: string | null;
}

export default function MastersPage() {
  const { t } = useI18n();
  const { hasPermission } = useAuth();
  const [creating, setCreating] = useState(false);
  const [editing, setEditing] = useState<Master | null>(null);

  const canListUsers = hasPermission("users.manage");

  const { data, loading } = useResource<{ data: Master[] }>("/masters");
  const masters = data?.data ?? [];

  const { options: employees } = useEmployeeOptions();
  const { options: worksites } = useWorksiteOptions();
  const { options: users } = useUserOptions(canListUsers);

  // No manual refetch: apiFetch's markMutated() revalidates every mounted
  // resource, this list included.
  async function remove(master: Master) {
    if (!window.confirm(t("masters.removeConfirm"))) return;
    await apiFetch(`/masters/${master.id}`, { method: "DELETE" });
  }

  return (
    <div className="space-y-5">
      <div className="flex items-center justify-between">
        <Button onClick={() => setCreating(true)}>{t("masters.new")}</Button>
      </div>

      <p className="text-sm text-zinc-500">{t("masters.hint")}</p>

      <Card className="vui-table scroll-quiet overflow-x-auto p-0">
        <table className="w-full min-w-[780px] text-sm">
          <thead>
            <tr>
              <th>{t("masters.master")}</th>
              <th>{t("masters.login")}</th>
              <th>{t("masters.worksites")}</th>
              <th>{t("masters.status")}</th>
              <th className="text-right">{t("masters.actions")}</th>
            </tr>
          </thead>
          <tbody>
            {loading ? (
              <tr>
                <td colSpan={5} className="py-14 text-center text-sm text-zinc-500">
                  {t("common.loading")}
                </td>
              </tr>
            ) : masters.length === 0 ? (
              <tr>
                <td colSpan={5} className="py-14 text-center text-sm text-zinc-500">
                  {t("masters.none")}
                </td>
              </tr>
            ) : (
              masters.map((master) => (
                <tr key={master.id} className="text-zinc-800 dark:text-zinc-200">
                  <td className="font-medium">
                    {master.employee?.full_name ?? `#${master.employee_id}`}
                    {master.employee?.job_role && (
                      <span className="ml-2 text-xs text-zinc-500">{master.employee.job_role}</span>
                    )}
                  </td>
                  <td>
                    {master.user ? master.user.email : <span className="text-zinc-500">{t("masters.noLogin")}</span>}
                  </td>
                  <td>
                    {master.worksites && master.worksites.length > 0 ? (
                      <span className="flex flex-wrap gap-1">
                        {master.worksites.map((worksite) => (
                          <Badge key={worksite.id} tone="indigo">
                            {worksite.name}
                          </Badge>
                        ))}
                      </span>
                    ) : (
                      <span className="text-zinc-500">{t("masters.noWorksites")}</span>
                    )}
                  </td>
                  <td>
                    <Badge tone={master.is_active ? "green" : "gray"}>
                      {master.is_active ? t("masters.active") : t("masters.inactive")}
                    </Badge>
                  </td>
                  <td className="text-right">
                    <div className="flex justify-end gap-2">
                      <Button variant="secondary" className="h-8 px-3" onClick={() => setEditing(master)}>
                        {t("masters.edit")}
                      </Button>
                      <Button variant="secondary" className="h-8 px-3" onClick={() => void remove(master)}>
                        {t("masters.remove")}
                      </Button>
                    </div>
                  </td>
                </tr>
              ))
            )}
          </tbody>
        </table>
      </Card>

      {creating && (
        <MasterModal
          employees={employees}
          worksites={worksites}
          users={users}
          canListUsers={canListUsers}
          onClose={() => setCreating(false)}
        />
      )}
      {editing && (
        <MasterModal
          master={editing}
          employees={employees}
          worksites={worksites}
          users={users}
          canListUsers={canListUsers}
          onClose={() => setEditing(null)}
        />
      )}
    </div>
  );
}

function MasterModal({
  master,
  employees,
  worksites,
  users,
  canListUsers,
  onClose,
}: {
  master?: Master;
  employees: EmployeeOption[];
  worksites: WorksiteOption[];
  users: UserOption[];
  canListUsers: boolean;
  onClose: () => void;
}) {
  const { t } = useI18n();
  const [employeeId, setEmployeeId] = useState(master ? String(master.employee_id) : "");
  const [userId, setUserId] = useState(master?.user_id ? String(master.user_id) : "");
  const [isActive, setIsActive] = useState(master?.is_active ?? true);
  const [selectedSites, setSelectedSites] = useState<number[]>(
    (master?.worksites ?? []).map((worksite) => worksite.id),
  );
  const [error, setError] = useState<string | null>(null);
  const [saving, setSaving] = useState(false);

  function toggleSite(id: number) {
    setSelectedSites((prev) => (prev.includes(id) ? prev.filter((value) => value !== id) : [...prev, id]));
  }

  async function submit(e: React.FormEvent) {
    e.preventDefault();
    setSaving(true);
    setError(null);
    try {
      const body = {
        user_id: userId ? Number(userId) : null,
        is_active: isActive,
        worksite_ids: selectedSites,
      };
      await apiFetch(master ? `/masters/${master.id}` : "/masters", {
        method: master ? "PUT" : "POST",
        json: master ? body : { ...body, employee_id: Number(employeeId) },
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
    <Modal open onClose={onClose} title={master ? t("masters.edit") : t("masters.new")}>
      <form onSubmit={submit} className="space-y-4">
        <div>
          <label className={label}>{t("masters.master")}</label>
          {master ? (
            <p className="py-2 text-sm text-zinc-700 dark:text-zinc-200">{master.employee?.full_name}</p>
          ) : (
            <Select value={employeeId} onChange={(e) => setEmployeeId(e.target.value)} required>
              <option value="">{t("masters.selectEmployee")}</option>
              {employees.map((employee) => (
                <option key={employee.id} value={employee.id}>
                  {employee.full_name}
                </option>
              ))}
            </Select>
          )}
        </div>

        <div>
          <label className={label}>{t("masters.login")}</label>
          {canListUsers ? (
            <Select value={userId} onChange={(e) => setUserId(e.target.value)}>
              <option value="">{t("masters.noLogin")}</option>
              {users.map((user) => (
                <option key={user.id} value={user.id}>
                  {user.name} · {user.email}
                </option>
              ))}
            </Select>
          ) : (
            <p className="text-xs text-zinc-500">{t("masters.loginNeedsPermission")}</p>
          )}
        </div>

        <div>
          <label className={label}>{t("masters.worksites")}</label>
          {worksites.length === 0 ? (
            <p className="text-xs text-zinc-500">{t("masters.noSitesYet")}</p>
          ) : (
            <ul className="max-h-48 space-y-1 overflow-y-auto">
              {worksites.map((worksite) => (
                <li key={worksite.id}>
                  <label className="flex cursor-pointer items-center gap-2 text-sm text-zinc-700 dark:text-zinc-200">
                    <Checkbox
                        size="sm"
                        checked={selectedSites.includes(worksite.id)}
                        onChange={() => toggleSite(worksite.id)}
                    />
                    <span>{worksite.name}</span>
                  </label>
                </li>
              ))}
            </ul>
          )}
        </div>

        <label className="flex cursor-pointer items-center gap-2 text-sm text-zinc-600 dark:text-zinc-300">
          <Checkbox
              size="sm"
              checked={isActive}
              onChange={(e) => setIsActive(e.target.checked)}/>
          <span>{t("masters.active")}</span>
        </label>

        {error && <p className="text-sm text-red-600">{error}</p>}
        <div className="flex justify-end gap-2">
          <Button type="button" variant="secondary" onClick={onClose}>
            {t("common.cancel")}
          </Button>
          <Button type="submit" disabled={saving || (!master && !employeeId)}>
            {saving ? t("common.saving") : t("common.save")}
          </Button>
        </div>
      </form>
    </Modal>
  );
}
