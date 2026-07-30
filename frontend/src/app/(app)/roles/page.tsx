"use client";

import { useMemo, useState } from "react";
import { apiFetch, errorMessage } from "@/lib/api";
import { useResource, withQuery } from "@/lib/data/use-resource";
import { useAuth } from "@/lib/auth/context";
import { useI18n } from "@/lib/i18n/context";
import { LOCALES, LOCALE_LABELS } from "@/lib/i18n/dictionaries";
import { useDebouncedValue } from "@/lib/use-debounced-value";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Card } from "@/components/ui/card";
import { Input } from "@/components/ui/input";
import { Modal } from "@/components/ui/modal";
import { Select } from "@/components/ui/select";

interface Role {
  id: number;
  name: string;
  is_system: boolean;
  users_count: number;
  permissions: string[];
}

interface UserRow {
  id: number;
  name: string;
  email: string;
  locale: string;
  is_active: boolean;
  roles: string[];
  /** Permissions granted to this user directly, on top of their roles. */
  direct_permissions: string[];
}

type Tab = "roles" | "users";

/**
 * Initial passwords are typed by the admin and handed over — there is no mail
 * transport to send an invite — so the field offers a generated one rather than
 * inviting "password123". Kept out of any password manager's way by being plain
 * text: the admin has to read it back to the person anyway.
 */
function generatePassword(): string {
  const alphabet = "abcdefghijkmnopqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789";
  const bytes = crypto.getRandomValues(new Uint32Array(16));
  return [...bytes].map((byte) => alphabet[byte % alphabet.length]).join("");
}

/**
 * Permissions are named `module.action`, so grouping on the prefix gives the
 * module list for free — no second catalogue to keep in step with the backend.
 */
function groupByModule(permissions: string[]): [string, string[]][] {
  const groups = new Map<string, string[]>();
  for (const permission of permissions) {
    const moduleName = permission.split(".")[0];
    groups.set(moduleName, [...(groups.get(moduleName) ?? []), permission]);
  }
  return [...groups.entries()].sort(([a], [b]) => a.localeCompare(b));
}

export default function RolesPage() {
  const { t } = useI18n();
  const [tab, setTab] = useState<Tab>("roles");

  return (
    <div className="space-y-5">
      <div>
        <h1 className="text-2xl font-semibold text-zinc-900 dark:text-zinc-50">{t("roles.title")}</h1>
        <p className="text-sm text-zinc-500">{t("roles.subtitle")}</p>
      </div>

      <div className="flex gap-2 border-b border-zinc-200 dark:border-zinc-800">
        {(["roles", "users"] as const).map((key) => (
          <button
            key={key}
            type="button"
            onClick={() => setTab(key)}
            className={
              tab === key
                ? "-mb-px border-b-2 border-indigo-600 px-3 py-2 text-sm font-medium text-indigo-700 dark:text-indigo-300"
                : "-mb-px border-b-2 border-transparent px-3 py-2 text-sm font-medium text-zinc-500 hover:text-zinc-800 dark:hover:text-zinc-200"
            }
          >
            {t(`roles.tab.${key}`)}
          </button>
        ))}
      </div>

      {tab === "roles" ? <RolesTab /> : <UsersTab />}
    </div>
  );
}

function RolesTab() {
  const { t } = useI18n();
  const [search, setSearch] = useState("");
  const [editing, setEditing] = useState<Role | null>(null);
  const [creating, setCreating] = useState(false);
  const [cloning, setCloning] = useState<Role | null>(null);
  const [error, setError] = useState<string | null>(null);

  const { data: rolesData, loading } = useResource<{ data: Role[] }>("/roles");
  const { data: permissionsData } = useResource<{ data: string[] }>("/permissions");

  const permissions = permissionsData?.data ?? [];
  const term = search.trim().toLowerCase();
  const roles = (rolesData?.data ?? []).filter(
    (role) =>
      term === "" ||
      role.name.toLowerCase().includes(term) ||
      role.permissions.some((permission) => permission.toLowerCase().includes(term)),
  );

  async function remove(role: Role) {
    if (!window.confirm(t("roles.deleteConfirm", { name: role.name }))) return;
    setError(null);
    try {
      await apiFetch(`/roles/${role.id}`, { method: "DELETE" });
    } catch (err) {
      setError(errorMessage(err));
    }
  }

  return (
    <div className="space-y-4">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <Input
          className="max-w-xs"
          placeholder={t("roles.search")}
          value={search}
          onChange={(e) => setSearch(e.target.value)}
        />
        <Button onClick={() => setCreating(true)}>{t("roles.new")}</Button>
      </div>

      {error && <p className="text-sm text-red-600">{error}</p>}

      <Card className="overflow-x-auto p-0">
        <table className="w-full min-w-[820px] text-sm">
          <thead className="border-b border-zinc-200 text-left text-xs uppercase tracking-wider text-zinc-500 dark:border-zinc-800">
            <tr>
              <th className="px-4 py-3">{t("roles.name")}</th>
              <th className="px-4 py-3 text-right">{t("roles.users")}</th>
              <th className="px-4 py-3 text-right">{t("roles.permissionCount")}</th>
              <th className="px-4 py-3">{t("roles.kind")}</th>
              <th className="px-4 py-3 text-right">{t("common.actions")}</th>
            </tr>
          </thead>
          <tbody className="divide-y divide-zinc-100 dark:divide-zinc-800">
            {loading ? (
              <tr>
                <td colSpan={5} className="px-4 py-8 text-center text-zinc-500">
                  {t("common.loading")}
                </td>
              </tr>
            ) : roles.length === 0 ? (
              <tr>
                <td colSpan={5} className="px-4 py-8 text-center text-zinc-500">
                  {t("roles.none")}
                </td>
              </tr>
            ) : (
              roles.map((role) => (
                <tr key={role.id} className="text-zinc-800 dark:text-zinc-200">
                  <td className="px-4 py-3 font-medium">{role.name}</td>
                  <td className="px-4 py-3 text-right tabular-nums">{role.users_count}</td>
                  <td className="px-4 py-3 text-right tabular-nums">{role.permissions.length}</td>
                  <td className="px-4 py-3">
                    <Badge tone={role.is_system ? "indigo" : "gray"}>
                      {role.is_system ? t("roles.system") : t("roles.custom")}
                    </Badge>
                  </td>
                  <td className="px-4 py-3 text-right">
                    <div className="flex justify-end gap-2">
                      <Button variant="secondary" className="h-8 px-3" onClick={() => setEditing(role)}>
                        {t("common.edit")}
                      </Button>
                      <Button variant="secondary" className="h-8 px-3" onClick={() => setCloning(role)}>
                        {t("roles.clone")}
                      </Button>
                      {/* Core roles are protected; the API refuses too. */}
                      <Button
                        variant="secondary"
                        className="h-8 px-3"
                        disabled={role.is_system}
                        onClick={() => void remove(role)}
                      >
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

      {creating && <RoleModal permissions={permissions} onClose={() => setCreating(false)} />}
      {editing && <RoleModal role={editing} permissions={permissions} onClose={() => setEditing(null)} />}
      {cloning && <CloneModal role={cloning} onClose={() => setCloning(null)} />}
    </div>
  );
}

function RoleModal({
  role,
  permissions,
  onClose,
}: {
  role?: Role;
  permissions: string[];
  onClose: () => void;
}) {
  const { t } = useI18n();
  const [name, setName] = useState(role?.name ?? "");
  const [selected, setSelected] = useState<string[]>(role?.permissions ?? []);
  const [filter, setFilter] = useState("");
  const [error, setError] = useState<string | null>(null);
  const [saving, setSaving] = useState(false);

  const groups = useMemo(() => {
    const term = filter.trim().toLowerCase();
    const matching = term === "" ? permissions : permissions.filter((p) => p.toLowerCase().includes(term));
    return groupByModule(matching);
  }, [permissions, filter]);

  function toggle(permission: string) {
    setSelected((prev) =>
      prev.includes(permission) ? prev.filter((value) => value !== permission) : [...prev, permission],
    );
  }

  function toggleModule(modulePermissions: string[]) {
    const allSelected = modulePermissions.every((permission) => selected.includes(permission));
    setSelected((prev) =>
      allSelected
        ? prev.filter((permission) => !modulePermissions.includes(permission))
        : [...new Set([...prev, ...modulePermissions])],
    );
  }

  async function submit(e: React.FormEvent) {
    e.preventDefault();
    setSaving(true);
    setError(null);
    try {
      await apiFetch(role ? `/roles/${role.id}` : "/roles", {
        method: role ? "PUT" : "POST",
        json: { name: name.trim(), permissions: selected },
      });
      onClose();
    } catch (err) {
      setError(errorMessage(err));
    } finally {
      setSaving(false);
    }
  }

  const label = "mb-1 block text-sm font-medium text-zinc-700 dark:text-zinc-300";

  return (
    <Modal open onClose={onClose} title={role ? t("roles.edit") : t("roles.new")}>
      <form onSubmit={submit} className="space-y-4">
        <div>
          <label className={label}>{t("roles.name")}</label>
          <Input
            value={name}
            onChange={(e) => setName(e.target.value)}
            required
            disabled={role?.is_system}
          />
          {role?.is_system && <p className="mt-1 text-xs text-zinc-500">{t("roles.systemNameLocked")}</p>}
        </div>

        <div>
          <div className="mb-2 flex items-center justify-between gap-3">
            <label className={label}>
              {t("roles.permissions")} ({selected.length})
            </label>
            <Input
              className="h-8 max-w-[200px]"
              placeholder={t("roles.filterPermissions")}
              value={filter}
              onChange={(e) => setFilter(e.target.value)}
            />
          </div>
          <div className="max-h-80 space-y-3 overflow-y-auto rounded-md border border-zinc-200 p-3 dark:border-zinc-800">
            {groups.length === 0 ? (
              <p className="text-sm text-zinc-500">{t("roles.noPermissionMatches")}</p>
            ) : (
              groups.map(([moduleName, modulePermissions]) => (
                <div key={moduleName}>
                  <button
                    type="button"
                    onClick={() => toggleModule(modulePermissions)}
                    className="mb-1 text-xs font-semibold uppercase tracking-wider text-zinc-500 hover:text-indigo-600"
                  >
                    {moduleName}
                  </button>
                  <div className="grid gap-1 sm:grid-cols-2">
                    {modulePermissions.map((permission) => (
                      <label
                        key={permission}
                        className="flex items-center gap-2 text-sm text-zinc-700 dark:text-zinc-200"
                      >
                        <input
                          type="checkbox"
                          checked={selected.includes(permission)}
                          onChange={() => toggle(permission)}
                        />
                        <span className="font-mono text-xs">{permission}</span>
                      </label>
                    ))}
                  </div>
                </div>
              ))
            )}
          </div>
        </div>

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

/** Cloning starts a new role from an existing one's permission set. */
function CloneModal({ role, onClose }: { role: Role; onClose: () => void }) {
  const { t } = useI18n();
  const [name, setName] = useState(`${role.name} copy`);
  const [error, setError] = useState<string | null>(null);
  const [saving, setSaving] = useState(false);

  async function submit(e: React.FormEvent) {
    e.preventDefault();
    setSaving(true);
    setError(null);
    try {
      await apiFetch(`/roles/${role.id}/clone`, { method: "POST", json: { name: name.trim() } });
      onClose();
    } catch (err) {
      setError(errorMessage(err));
    } finally {
      setSaving(false);
    }
  }

  return (
    <Modal open onClose={onClose} title={t("roles.clone")}>
      <form onSubmit={submit} className="space-y-4">
        <p className="text-sm text-zinc-500">{t("roles.cloneHint", { name: role.name })}</p>
        <div>
          <label className="mb-1 block text-sm font-medium text-zinc-700 dark:text-zinc-300">
            {t("roles.name")}
          </label>
          <Input value={name} onChange={(e) => setName(e.target.value)} required />
        </div>
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

function UsersTab() {
  const { t } = useI18n();
  const { hasPermission, user: currentUser } = useAuth();
  const [search, setSearch] = useState("");
  const [access, setAccess] = useState<UserRow | null>(null);
  const [account, setAccount] = useState<UserRow | null>(null);
  const [resetting, setResetting] = useState<UserRow | null>(null);
  const [creating, setCreating] = useState(false);

  const debouncedSearch = useDebouncedValue(search);
  const canManageUsers = hasPermission("users.manage");

  const { data, loading } = useResource<{ data: UserRow[] }>(
    withQuery("/users", { search: debouncedSearch }),
    { enabled: canManageUsers },
  );
  const users = data?.data ?? [];

  if (!canManageUsers) {
    return <Card className="p-4 text-sm text-zinc-500">{t("roles.noUserAccess")}</Card>;
  }

  return (
    <div className="space-y-4">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <Input
          className="max-w-xs"
          placeholder={t("roles.searchUsers")}
          value={search}
          onChange={(e) => setSearch(e.target.value)}
        />
        <Button onClick={() => setCreating(true)}>{t("users.new")}</Button>
      </div>

      <Card className="overflow-x-auto p-0">
        <table className="w-full min-w-[960px] text-sm">
          <thead className="border-b border-zinc-200 text-left text-xs uppercase tracking-wider text-zinc-500 dark:border-zinc-800">
            <tr>
              <th className="px-4 py-3">{t("roles.user")}</th>
              <th className="px-4 py-3">{t("roles.email")}</th>
              <th className="px-4 py-3">{t("roles.assignedRoles")}</th>
              <th className="px-4 py-3">{t("roles.userStatus")}</th>
              <th className="px-4 py-3 text-right">{t("common.actions")}</th>
            </tr>
          </thead>
          <tbody className="divide-y divide-zinc-100 dark:divide-zinc-800">
            {loading ? (
              <tr>
                <td colSpan={5} className="px-4 py-8 text-center text-zinc-500">
                  {t("common.loading")}
                </td>
              </tr>
            ) : users.length === 0 ? (
              <tr>
                <td colSpan={5} className="px-4 py-8 text-center text-zinc-500">
                  {t("roles.noUsers")}
                </td>
              </tr>
            ) : (
              users.map((user) => (
                <tr key={user.id} className="text-zinc-800 dark:text-zinc-200">
                  <td className="px-4 py-3 font-medium">{user.name}</td>
                  <td className="px-4 py-3 text-zinc-500">{user.email}</td>
                  <td className="px-4 py-3">
                    <div className="flex flex-wrap gap-1">
                      {user.roles.length === 0 ? (
                        <span className="text-zinc-400">—</span>
                      ) : (
                        user.roles.map((role) => (
                          <Badge key={role} tone="indigo">
                            {role}
                          </Badge>
                        ))
                      )}
                    </div>
                  </td>
                  <td className="px-4 py-3">
                    <Badge tone={user.is_active ? "green" : "gray"}>
                      {user.is_active ? t("roles.userActive") : t("roles.userInactive")}
                    </Badge>
                  </td>
                  <td className="px-4 py-3 text-right">
                    <div className="flex justify-end gap-2">
                      <Button variant="secondary" className="h-8 px-3" onClick={() => setAccount(user)}>
                        {t("common.edit")}
                      </Button>
                      <Button variant="secondary" className="h-8 px-3" onClick={() => setResetting(user)}>
                        {t("users.resetPassword")}
                      </Button>
                      {/* Your own access is another admin's job; the API refuses too. */}
                      <Button
                        variant="secondary"
                        className="h-8 px-3"
                        disabled={currentUser?.id === user.id}
                        onClick={() => setAccess(user)}
                      >
                        {t("roles.manageAccess")}
                      </Button>
                    </div>
                  </td>
                </tr>
              ))
            )}
          </tbody>
        </table>
      </Card>

      {creating && <UserModal onClose={() => setCreating(false)} />}
      {account && <UserModal user={account} onClose={() => setAccount(null)} />}
      {resetting && <PasswordModal user={resetting} onClose={() => setResetting(null)} />}
      {access && <UserAccessModal user={access} onClose={() => setAccess(null)} />}
    </div>
  );
}

/**
 * The account itself — who the person is and whether they may sign in. Their
 * roles and grants live in UserAccessModal: creating a login and deciding what
 * it may reach are different decisions, often made by different people.
 */
function UserModal({ user, onClose }: { user?: UserRow; onClose: () => void }) {
  const { t } = useI18n();
  const { user: currentUser } = useAuth();
  const [name, setName] = useState(user?.name ?? "");
  const [email, setEmail] = useState(user?.email ?? "");
  const [locale, setLocale] = useState(user?.locale ?? "en");
  const [isActive, setIsActive] = useState(user?.is_active ?? true);
  const [password, setPassword] = useState("");
  const [roles, setRoles] = useState<string[]>([]);
  const [error, setError] = useState<string | null>(null);
  const [saving, setSaving] = useState(false);

  const { data: rolesData } = useResource<{ data: Role[] }>("/roles", { enabled: !user });
  const allRoles = rolesData?.data ?? [];
  const isSelf = currentUser?.id === user?.id;

  async function submit(e: React.FormEvent) {
    e.preventDefault();
    setSaving(true);
    setError(null);
    try {
      if (user) {
        await apiFetch(`/users/${user.id}`, {
          method: "PUT",
          json: { name: name.trim(), email: email.trim(), locale, ...(isSelf ? {} : { is_active: isActive }) },
        });
      } else {
        await apiFetch("/users", {
          method: "POST",
          json: { name: name.trim(), email: email.trim(), password, locale, is_active: isActive, roles },
        });
      }
      onClose();
    } catch (err) {
      setError(errorMessage(err));
    } finally {
      setSaving(false);
    }
  }

  const label = "mb-1 block text-sm font-medium text-zinc-700 dark:text-zinc-300";

  return (
    <Modal open onClose={onClose} title={user ? t("users.edit") : t("users.new")}>
      <form onSubmit={submit} className="space-y-4">
        <div>
          <label className={label}>{t("roles.user")}</label>
          <Input value={name} onChange={(e) => setName(e.target.value)} required autoFocus />
        </div>

        <div>
          <label className={label}>{t("roles.email")}</label>
          <Input type="email" value={email} onChange={(e) => setEmail(e.target.value)} required />
        </div>

        {!user && (
          <div>
            <label className={label}>{t("users.password")}</label>
            <div className="flex gap-2">
              {/* Deliberately readable: the admin has to pass it on. */}
              <Input value={password} onChange={(e) => setPassword(e.target.value)} required minLength={8} />
              <Button type="button" variant="secondary" onClick={() => setPassword(generatePassword())}>
                {t("users.generate")}
              </Button>
            </div>
            <p className="mt-1 text-xs text-zinc-500">{t("users.passwordHint")}</p>
          </div>
        )}

        <div>
          <label className={label}>{t("users.language")}</label>
          <Select value={locale} onChange={(e) => setLocale(e.target.value)}>
            {LOCALES.map((code) => (
              <option key={code} value={code}>
                {LOCALE_LABELS[code]}
              </option>
            ))}
          </Select>
        </div>

        {!user && (
          <div>
            <label className={label}>{t("roles.assignedRoles")}</label>
            <div className="grid gap-1 sm:grid-cols-2">
              {allRoles.map((role) => (
                <label key={role.id} className="flex items-center gap-2 text-sm text-zinc-700 dark:text-zinc-200">
                  <input
                    type="checkbox"
                    checked={roles.includes(role.name)}
                    onChange={() =>
                      setRoles((prev) =>
                        prev.includes(role.name)
                          ? prev.filter((value) => value !== role.name)
                          : [...prev, role.name],
                      )
                    }
                  />
                  {role.name}
                </label>
              ))}
            </div>
          </div>
        )}

        <div>
          <label className="flex items-center gap-2 text-sm text-zinc-700 dark:text-zinc-200">
            <input
              type="checkbox"
              checked={isActive}
              disabled={isSelf}
              onChange={(e) => setIsActive(e.target.checked)}
            />
            {t("users.activeAccount")}
          </label>
          <p className="mt-1 text-xs text-zinc-500">
            {isSelf ? t("users.selfLocked") : t("users.activeHint")}
          </p>
        </div>

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

/** Administrative reset — for the person who cannot get in to change it themselves. */
function PasswordModal({ user, onClose }: { user: UserRow; onClose: () => void }) {
  const { t } = useI18n();
  const [password, setPassword] = useState("");
  const [error, setError] = useState<string | null>(null);
  const [saving, setSaving] = useState(false);

  async function submit(e: React.FormEvent) {
    e.preventDefault();
    setSaving(true);
    setError(null);
    try {
      await apiFetch(`/users/${user.id}/password`, { method: "PUT", json: { password } });
      onClose();
    } catch (err) {
      setError(errorMessage(err));
    } finally {
      setSaving(false);
    }
  }

  return (
    <Modal open onClose={onClose} title={`${t("users.resetPassword")} — ${user.name}`}>
      <form onSubmit={submit} className="space-y-4">
        <div>
          <label className="mb-1 block text-sm font-medium text-zinc-700 dark:text-zinc-300">
            {t("users.newPassword")}
          </label>
          <div className="flex gap-2">
            <Input value={password} onChange={(e) => setPassword(e.target.value)} required minLength={8} autoFocus />
            <Button type="button" variant="secondary" onClick={() => setPassword(generatePassword())}>
              {t("users.generate")}
            </Button>
          </div>
          <p className="mt-1 text-xs text-zinc-500">{t("users.resetHint")}</p>
        </div>

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

/**
 * A user's access is a role set plus any extra permissions granted directly —
 * the spec's "one role plus additional permission parameters". The two are saved
 * separately because the API keeps them apart.
 */
function UserAccessModal({ user, onClose }: { user: UserRow; onClose: () => void }) {
  const { t } = useI18n();
  const [roles, setRoles] = useState<string[]>(user.roles);
  const [extras, setExtras] = useState<string[]>(user.direct_permissions ?? []);
  const [filter, setFilter] = useState("");
  const [error, setError] = useState<string | null>(null);
  const [saving, setSaving] = useState(false);

  const { data: rolesData } = useResource<{ data: Role[] }>("/roles");
  const { data: permissionsData } = useResource<{ data: string[] }>("/permissions");

  const allRoles = rolesData?.data ?? [];
  const permissions = permissionsData?.data ?? [];

  const groups = useMemo(() => {
    const term = filter.trim().toLowerCase();
    const matching = term === "" ? permissions : permissions.filter((p) => p.toLowerCase().includes(term));
    return groupByModule(matching);
  }, [permissions, filter]);

  // Permissions the chosen roles already grant — shown so an extra is never
  // added for something the role covers anyway.
  const fromRoles = useMemo(() => {
    const granted = new Set<string>();
    for (const role of allRoles) {
      if (roles.includes(role.name)) role.permissions.forEach((permission) => granted.add(permission));
    }
    return granted;
  }, [allRoles, roles]);

  function toggleRole(name: string) {
    setRoles((prev) => (prev.includes(name) ? prev.filter((value) => value !== name) : [...prev, name]));
  }

  function toggleExtra(permission: string) {
    setExtras((prev) =>
      prev.includes(permission) ? prev.filter((value) => value !== permission) : [...prev, permission],
    );
  }

  async function submit(e: React.FormEvent) {
    e.preventDefault();
    setSaving(true);
    setError(null);
    try {
      await apiFetch(`/users/${user.id}/roles`, { method: "PUT", json: { roles } });
      await apiFetch(`/users/${user.id}/extra-permissions`, { method: "PUT", json: { permissions: extras } });
      onClose();
    } catch (err) {
      setError(errorMessage(err));
    } finally {
      setSaving(false);
    }
  }

  const label = "mb-1 block text-sm font-medium text-zinc-700 dark:text-zinc-300";

  return (
    <Modal open onClose={onClose} title={`${t("roles.manageAccess")} — ${user.name}`}>
      <form onSubmit={submit} className="space-y-4">
        <div>
          <label className={label}>{t("roles.assignedRoles")}</label>
          <div className="grid gap-1 sm:grid-cols-2">
            {allRoles.map((role) => (
              <label key={role.id} className="flex items-center gap-2 text-sm text-zinc-700 dark:text-zinc-200">
                <input
                  type="checkbox"
                  checked={roles.includes(role.name)}
                  onChange={() => toggleRole(role.name)}
                />
                {role.name}
              </label>
            ))}
          </div>
        </div>

        <div>
          <div className="mb-2 flex items-center justify-between gap-3">
            <label className={label}>{t("roles.extraPermissions")}</label>
            <Input
              className="h-8 max-w-[200px]"
              placeholder={t("roles.filterPermissions")}
              value={filter}
              onChange={(e) => setFilter(e.target.value)}
            />
          </div>
          <p className="mb-2 text-xs text-zinc-500">{t("roles.extraHint")}</p>
          <div className="max-h-64 space-y-3 overflow-y-auto rounded-md border border-zinc-200 p-3 dark:border-zinc-800">
            {groups.map(([moduleName, modulePermissions]) => (
              <div key={moduleName}>
                <p className="mb-1 text-xs font-semibold uppercase tracking-wider text-zinc-500">{moduleName}</p>
                <div className="grid gap-1 sm:grid-cols-2">
                  {modulePermissions.map((permission) => {
                    const covered = fromRoles.has(permission);
                    return (
                      <label
                        key={permission}
                        className="flex items-center gap-2 text-sm text-zinc-700 dark:text-zinc-200"
                      >
                        <input
                          type="checkbox"
                          checked={covered || extras.includes(permission)}
                          disabled={covered}
                          onChange={() => toggleExtra(permission)}
                        />
                        <span className={covered ? "font-mono text-xs text-zinc-400" : "font-mono text-xs"}>
                          {permission}
                        </span>
                      </label>
                    );
                  })}
                </div>
              </div>
            ))}
          </div>
        </div>

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
