"use client";

import Link from "next/link";
import { useEffect, useRef, useState } from "react";
import { ApiError, apiFetch } from "@/lib/api";
import { useResource, withQuery } from "@/lib/data/use-resource";
import { useAuth } from "@/lib/auth/context";
import { useI18n } from "@/lib/i18n/context";
import { formatDate } from "@/lib/format";
import {
  NOTIFICATION_SEVERITIES,
  NOTIFICATION_TIMINGS,
  NOTIFICATION_TYPES,
  notificationMessage,
  severityTone,
  type AppNotification,
  type NotificationCounts,
} from "@/lib/notifications";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Card } from "@/components/ui/card";
import { Input } from "@/components/ui/input";
import { Modal } from "@/components/ui/modal";
import { Select } from "@/components/ui/select";
import { Checkbox } from "@/components/ui/checkbox";

interface NotificationRule {
  id: number;
  type: string;
  is_enabled: boolean;
  timing: string;
  days_before: number | null;
  severity: string;
  channels: string[];
  recipient_roles: string[];
  recipient_user_ids: number[];
}

interface Preference {
  type: string;
  is_enabled: boolean | null;
}

interface RulesResponse {
  data: NotificationRule[];
  // Role names ride along with the rules so this screen needs no extra
  // permission just to list who can be a recipient.
  meta: { roles: string[] };
}

const TABS = ["inbox", "rules", "preferences"] as const;
type Tab = (typeof TABS)[number];

const labelClass = "mb-1.5 block px-4 text-[11px] font-medium uppercase tracking-[0.08em] text-zinc-500";

export default function NotificationsPage() {
  const { t } = useI18n();
  const { hasPermission } = useAuth();
  const [tab, setTab] = useState<Tab>("inbox");

  const canConfigure = hasPermission("notifications.configure");
  const tabs = TABS.filter((value) => value !== "rules" || canConfigure);

  return (
    <div className="space-y-5">
      <h1 className="text-[2.5rem] font-light leading-none tracking-[-0.02em] text-zinc-900 dark:text-zinc-50">{t("notifications.title")}</h1>

      <div className="control-surface inline-flex flex-wrap gap-1 rounded-full p-1">
        {tabs.map((value) => (
          <button
            key={value}
            onClick={() => setTab(value)}
            className={
              tab === value
                ? "rounded-full bg-white px-4 py-1.5 text-sm font-medium text-zinc-900 shadow-[0_1px_2px_rgb(13_12_11/0.06),0_4px_12px_-6px_rgb(13_12_11/0.25)] dark:bg-white/15 dark:text-zinc-50"
                : "rounded-full px-4 py-1.5 text-sm text-zinc-500 transition-colors hover:bg-white/60 hover:text-zinc-800 dark:hover:bg-white/10 dark:hover:text-zinc-200"
            }
          >
            {t(`notifications.tab.${value}`)}
          </button>
        ))}
      </div>

      {tab === "inbox" && <InboxTab canConfigure={canConfigure} />}
      {tab === "rules" && canConfigure && <RulesTab />}
      {tab === "preferences" && <PreferencesTab />}
    </div>
  );
}

function InboxTab({ canConfigure }: { canConfigure: boolean }) {
  const { t } = useI18n();
  const [type, setType] = useState("");
  const [severity, setSeverity] = useState("");
  const [history, setHistory] = useState(false);
  const [reminding, setReminding] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const { data, loading, error: listError } = useResource<{
    data: AppNotification[];
    meta: NotificationCounts;
  }>(withQuery("/notifications", { type, severity, include_history: history }));

  const notifications = data?.data ?? [];
  const counts = data?.meta ?? { unread: 0, open: 0, critical: 0 };

  // No manual refetch below: every action is a write, and apiFetch's
  // markMutated() revalidates this list — and the shell's unread badge with it.
  async function act(notification: AppNotification, action: "read" | "dismiss" | "resolve") {
    try {
      await apiFetch(`/notifications/${notification.id}/${action}`, { method: "PUT" });
    } catch (err) {
      setError(err instanceof ApiError ? err.message : "Error");
    }
  }

  async function markAllRead() {
    try {
      await apiFetch("/notifications/read-all", { method: "PUT" });
    } catch (err) {
      setError(err instanceof ApiError ? err.message : "Error");
    }
  }

  async function rescan() {
    try {
      await apiFetch("/notifications/scan", { method: "POST" });
    } catch (err) {
      setError(err instanceof ApiError ? err.message : "Error");
    }
  }

  return (
    <div className="space-y-4">
      <div className="flex flex-wrap items-end justify-between gap-3">
        <div className="flex flex-wrap items-end gap-3">
          <div>
            <label className={labelClass}>{t("notifications.filterType")}</label>
            <Select className="w-56" value={type} onChange={(e) => setType(e.target.value)}>
              <option value="">{t("notifications.allTypes")}</option>
              {NOTIFICATION_TYPES.map((value) => (
                <option key={value} value={value}>
                  {t(`notifType.${value}`)}
                </option>
              ))}
            </Select>
          </div>
          <div>
            <label className={labelClass}>{t("notifications.filterSeverity")}</label>
            <Select className="w-40" value={severity} onChange={(e) => setSeverity(e.target.value)}>
              <option value="">{t("notifications.allSeverities")}</option>
              {NOTIFICATION_SEVERITIES.map((value) => (
                <option key={value} value={value}>
                  {t(`severity.${value}`)}
                </option>
              ))}
            </Select>
          </div>
          <label className="flex h-10 cursor-pointer items-center gap-2 text-sm text-zinc-600 dark:text-zinc-300">
            <Checkbox
                size="sm"
                checked={history}
                onChange={(e) => setHistory(e.target.checked)}
            />
            <span>{t("notifications.showHistory")}</span>
          </label>
        </div>

        <div className="flex flex-wrap gap-2">
          {canConfigure && (
            <>
              <Button variant="secondary" onClick={() => setReminding(true)}>
                {t("notifications.newReminder")}
              </Button>
              <Button variant="secondary" onClick={() => void rescan()}>
                {t("notifications.rescan")}
              </Button>
            </>
          )}
          <Button variant="secondary" onClick={() => void markAllRead()} disabled={counts.unread === 0}>
            {t("notifications.markAllRead")}
          </Button>
        </div>
      </div>

      {(error ?? listError) && <p className="text-sm text-red-600">{error ?? listError}</p>}

      <Card className="divide-y divide-zinc-100 p-0 dark:divide-zinc-800">
        {loading ? (
          <p className="px-4 py-10 text-center text-sm text-zinc-500">{t("common.loading")}</p>
        ) : notifications.length === 0 ? (
          <p className="px-4 py-10 text-center text-sm text-zinc-500">{t("notifications.empty")}</p>
        ) : (
          notifications.map((notification) => (
            <div key={notification.id} className="flex flex-wrap items-start gap-3 px-4 py-3">
              <Badge tone={severityTone(notification.severity)}>{t(`severity.${notification.severity}`)}</Badge>

              <div className="min-w-0 flex-1">
                <p
                  className={
                    notification.status === "unread"
                      ? "text-sm font-medium text-zinc-900 dark:text-zinc-50"
                      : "text-sm text-zinc-700 dark:text-zinc-300"
                  }
                >
                  {notificationMessage(t, notification)}
                </p>
                <p className="mt-0.5 text-xs text-zinc-500">
                  {t(`notifType.${notification.type}`)}
                  {notification.due_date &&
                    ` · ${t("notifications.due", { date: formatDate(notification.due_date) })}`}
                  {notification.status !== "unread" && ` · ${t(`notifStatus.${notification.status}`)}`}
                </p>
                {notification.type === "custom.reminder" && Boolean(notification.data.body) && (
                  <p className="mt-1 text-xs text-zinc-500">{String(notification.data.body)}</p>
                )}
              </div>

              <div className="flex flex-wrap justify-end gap-2">
                <Link
                  href={notification.link}
                  onClick={() => void act(notification, "read")}
                  className="inline-flex h-8 items-center rounded-md border border-zinc-300 px-3 text-sm text-zinc-800 transition-colors hover:bg-zinc-50 dark:border-zinc-700 dark:text-zinc-100 dark:hover:bg-zinc-800"
                >
                  {t("notifications.open")}
                </Link>
                {notification.status === "unread" && (
                  <Button variant="secondary" className="h-8 px-3" onClick={() => void act(notification, "read")}>
                    {t("notifications.markRead")}
                  </Button>
                )}
                {(notification.status === "unread" || notification.status === "read") && (
                  <>
                    <Button
                      variant="secondary"
                      className="h-8 px-3"
                      onClick={() => void act(notification, "resolve")}
                    >
                      {t("notifications.resolve")}
                    </Button>
                    <Button
                      variant="secondary"
                      className="h-8 px-3"
                      onClick={() => void act(notification, "dismiss")}
                    >
                      {t("notifications.dismiss")}
                    </Button>
                  </>
                )}
              </div>
            </div>
          ))
        )}
      </Card>

      {reminding && <ReminderModal onClose={() => setReminding(false)} />}
    </div>
  );
}

function ReminderModal({ onClose }: { onClose: () => void }) {
  const { t } = useI18n();
  const [title, setTitle] = useState("");
  const [body, setBody] = useState("");
  const [dueDate, setDueDate] = useState("");
  const [severity, setSeverity] = useState("info");
  const [selectedRoles, setSelectedRoles] = useState<string[]>([]);
  const [error, setError] = useState<string | null>(null);
  const [saving, setSaving] = useState(false);

  // The rules endpoint is also read by the Rules tab; one cache key serves both.
  const { data } = useResource<RulesResponse>("/settings/notification-rules");
  const roles = data?.meta.roles ?? [];

  async function submit(e: React.FormEvent) {
    e.preventDefault();
    setSaving(true);
    setError(null);
    try {
      await apiFetch("/notifications/reminders", {
        method: "POST",
        json: {
          title: title.trim(),
          body: body.trim() || null,
          due_date: dueDate || null,
          severity,
          roles: selectedRoles,
        },
      });
      onClose();
    } catch (err) {
      setError(err instanceof ApiError ? err.message : "Error");
    } finally {
      setSaving(false);
    }
  }

  return (
    <Modal open onClose={onClose} title={t("notifications.newReminder")}>
      <form onSubmit={submit} className="space-y-4">
        <p className="text-xs text-zinc-500">{t("notifications.reminderHint")}</p>
        <div>
          <label className={labelClass}>{t("notifications.reminderTitle")}</label>
          <Input value={title} onChange={(e) => setTitle(e.target.value)} required />
        </div>
        <div>
          <label className={labelClass}>{t("notifications.reminderBody")}</label>
          <Input value={body} onChange={(e) => setBody(e.target.value)} />
        </div>
        <div className="grid grid-cols-2 gap-4">
          <div>
            <label className={labelClass}>{t("notifications.dueDate")}</label>
            <Input type="date" value={dueDate} onChange={(e) => setDueDate(e.target.value)} />
          </div>
          <div>
            <label className={labelClass}>{t("notifications.severity")}</label>
            <Select value={severity} onChange={(e) => setSeverity(e.target.value)}>
              {NOTIFICATION_SEVERITIES.map((value) => (
                <option key={value} value={value}>
                  {t(`severity.${value}`)}
                </option>
              ))}
            </Select>
          </div>
        </div>
        <div>
          <label className={labelClass}>{t("notifications.recipientRoles")}</label>
          <div className="flex flex-wrap gap-3">
            {roles.map((role) => (
                <label
                    key={role}
                    className="flex cursor-pointer items-center gap-2 text-sm text-zinc-700 dark:text-zinc-300"
                >
                  <Checkbox
                      size="sm"
                      checked={selectedRoles.includes(role)}
                      onChange={(e) =>
                          setSelectedRoles((prev) =>
                              e.target.checked
                                  ? [...prev, role]
                                  : prev.filter((name) => name !== role),
                          )
                      }
                  />
                  <span>{role}</span>
                </label>
            ))}
          </div>
          <p className="mt-1 text-xs text-zinc-500">{t("notifications.recipientsHint")}</p>
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

function RulesTab() {
  const { t } = useI18n();
  const [rules, setRules] = useState<NotificationRule[]>([]);
  const [saving, setSaving] = useState(false);
  const [saved, setSaved] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const { data, loading, error: loadError } = useResource<RulesResponse>(
    "/settings/notification-rules",
  );

  const roles = data?.meta.roles ?? [];

  // `rules` is an editable draft that `patch()` writes into and `save()` submits,
  // so it is seeded exactly once. Re-seeding on a revalidation — which happens on
  // refocus and after any write — would silently discard unsaved edits.
  const seeded = useRef(false);

  useEffect(() => {
    if (seeded.current || !data) return;

    seeded.current = true;
    setRules(data.data);
  }, [data]);

  function patch(type: string, changes: Partial<NotificationRule>) {
    setSaved(false);
    setRules((prev) => prev.map((rule) => (rule.type === type ? { ...rule, ...changes } : rule)));
  }

  async function save() {
    setSaving(true);
    setError(null);
    try {
      const res = await apiFetch<RulesResponse>("/settings/notification-rules", {
        method: "PUT",
        json: {
          rules: rules.map((rule) => ({
            type: rule.type,
            is_enabled: rule.is_enabled,
            timing: rule.timing,
            days_before: rule.timing === "days_before" ? (rule.days_before ?? 0) : null,
            severity: rule.severity,
            channels: rule.channels.length > 0 ? rule.channels : ["in_app"],
            recipient_roles: rule.recipient_roles,
            recipient_user_ids: rule.recipient_user_ids,
          })),
        },
      });
      setRules(res.data);
      setSaved(true);
    } catch (err) {
      setError(err instanceof ApiError ? err.message : "Error");
    } finally {
      setSaving(false);
    }
  }

  if (loading) return <p className="text-sm text-zinc-500">{t("common.loading")}</p>;

  return (
    <div className="space-y-4">
      <p className="text-sm text-zinc-500">{t("notifications.rulesHint")}</p>

      <Card className="table-quiet scroll-quiet overflow-x-auto p-0">
        <table className="w-full min-w-[900px] text-sm">
          <thead className="border-b border-zinc-900/8 text-left text-[11px] uppercase tracking-[0.1em] text-zinc-500 dark:border-white/10">
            <tr>
              <th className="px-4 py-3">{t("notifications.type")}</th>
              <th className="px-4 py-3">{t("notifications.enabled")}</th>
              <th className="px-4 py-3">{t("notifications.timing")}</th>
              <th className="px-4 py-3">{t("notifications.daysBefore")}</th>
              <th className="px-4 py-3">{t("notifications.severity")}</th>
              <th className="px-4 py-3">{t("notifications.recipientRoles")}</th>
            </tr>
          </thead>
          <tbody className="divide-y divide-zinc-900/5 dark:divide-white/8">
            {rules.map((rule) => (
              <tr key={rule.type} className="text-zinc-800 dark:text-zinc-200">
                <td className="px-4 py-3">
                  <span className="flex flex-col">
                    <span className="font-medium">{t(`notifType.${rule.type}`)}</span>
                    <span className="text-xs text-zinc-500">{rule.type}</span>
                  </span>
                </td>
                <td className="px-4 py-3">
                  <Checkbox
                      size="sm"
                      checked={rule.is_enabled}
                      onChange={(e) =>
                          patch(rule.type, { is_enabled: e.target.checked })
                      }
                  />
                </td>
                <td className="px-4 py-3">
                  <Select
                    className="w-44"
                    value={rule.timing}
                    onChange={(e) => patch(rule.type, { timing: e.target.value })}
                  >
                    {NOTIFICATION_TIMINGS.map((value) => (
                      <option key={value} value={value}>
                        {t(`timing.${value}`)}
                      </option>
                    ))}
                  </Select>
                </td>
                <td className="px-4 py-3">
                  <Input
                    type="number"
                    min="0"
                    max="365"
                    className="w-24"
                    value={rule.days_before ?? ""}
                    disabled={rule.timing !== "days_before"}
                    onChange={(e) =>
                      patch(rule.type, { days_before: e.target.value === "" ? null : Number(e.target.value) })
                    }
                  />
                </td>
                <td className="px-4 py-3">
                  <Select
                    className="w-32"
                    value={rule.severity}
                    onChange={(e) => patch(rule.type, { severity: e.target.value })}
                  >
                    {NOTIFICATION_SEVERITIES.map((value) => (
                      <option key={value} value={value}>
                        {t(`severity.${value}`)}
                      </option>
                    ))}
                  </Select>
                </td>
                <td className="px-4 py-3">
                  <div className="flex flex-wrap gap-2">
                    {roles.map((role) => (
                        <label
                            key={role}
                            className="flex cursor-pointer items-center gap-1 text-xs text-zinc-600 dark:text-zinc-300"
                        >
                          <Checkbox
                              size="sm"
                              checked={rule.recipient_roles.includes(role)}
                              onChange={(e) =>
                                  patch(rule.type, {
                                    recipient_roles: e.target.checked
                                        ? [...rule.recipient_roles, role]
                                        : rule.recipient_roles.filter((name) => name !== role),
                                  })
                              }
                          />
                          <span>{role}</span>
                        </label>
                    ))}
                  </div>
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      </Card>

      {(error ?? loadError) && <p className="text-sm text-red-600">{error ?? loadError}</p>}
      <div className="flex items-center justify-end gap-3">
        {saved && <span className="text-sm text-green-600">{t("notifications.saved")}</span>}
        <Button onClick={() => void save()} disabled={saving}>
          {saving ? t("common.saving") : t("common.save")}
        </Button>
      </div>
    </div>
  );
}

function PreferencesTab() {
  const { t } = useI18n();
  const [preferences, setPreferences] = useState<Preference[]>([]);
  const [saving, setSaving] = useState(false);
  const [saved, setSaved] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const { data, loading, error: loadError } = useResource<{ data: Preference[] }>(
    "/me/notification-preferences",
  );

  // Seeded once for the same reason as the rules draft above.
  const seeded = useRef(false);

  useEffect(() => {
    if (seeded.current || !data) return;

    seeded.current = true;
    setPreferences(data.data);
  }, [data]);

  function patch(type: string, value: boolean | null) {
    setSaved(false);
    setPreferences((prev) => prev.map((row) => (row.type === type ? { ...row, is_enabled: value } : row)));
  }

  async function save() {
    setSaving(true);
    setError(null);
    try {
      const res = await apiFetch<{ data: Preference[] }>("/me/notification-preferences", {
        method: "PUT",
        json: { preferences },
      });
      setPreferences(res.data);
      setSaved(true);
    } catch (err) {
      setError(err instanceof ApiError ? err.message : "Error");
    } finally {
      setSaving(false);
    }
  }

  if (loading) return <p className="text-sm text-zinc-500">{t("common.loading")}</p>;

  return (
    <div className="space-y-4">
      <p className="text-sm text-zinc-500">{t("notifications.preferencesHint")}</p>

      <Card className="divide-y divide-zinc-100 p-0 dark:divide-zinc-800">
        {preferences.map((preference) => (
          <div key={preference.type} className="flex flex-wrap items-center justify-between gap-3 px-4 py-3">
            <span className="flex flex-col">
              <span className="text-sm font-medium text-zinc-900 dark:text-zinc-50">
                {t(`notifType.${preference.type}`)}
              </span>
              <span className="text-xs text-zinc-500">{preference.type}</span>
            </span>
            <Select
              className="w-52"
              value={preference.is_enabled === null ? "default" : preference.is_enabled ? "on" : "off"}
              onChange={(e) =>
                patch(
                  preference.type,
                  e.target.value === "default" ? null : e.target.value === "on",
                )
              }
            >
              <option value="default">{t("notifications.followRule")}</option>
              <option value="on">{t("notifications.alwaysNotify")}</option>
              <option value="off">{t("notifications.neverNotify")}</option>
            </Select>
          </div>
        ))}
      </Card>

      {(error ?? loadError) && <p className="text-sm text-red-600">{error ?? loadError}</p>}
      <div className="flex items-center justify-end gap-3">
        {saved && <span className="text-sm text-green-600">{t("notifications.saved")}</span>}
        <Button onClick={() => void save()} disabled={saving}>
          {saving ? t("common.saving") : t("common.save")}
        </Button>
      </div>
    </div>
  );
}
