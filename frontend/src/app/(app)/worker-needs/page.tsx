"use client";

import { useState } from "react";
import { apiFetch, errorMessage } from "@/lib/api";
import { usePage } from "@/lib/data/use-page";
import { useResource, withQuery } from "@/lib/data/use-resource";
import { useI18n } from "@/lib/i18n/context";
import { formatDate, todayISO } from "@/lib/format";
import { useDebouncedValue } from "@/lib/use-debounced-value";
import { AsyncSelect } from "@/components/ui/async-select";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Card } from "@/components/ui/card";
import { Input } from "@/components/ui/input";
import { Modal } from "@/components/ui/modal";
import { Pagination, type PageMeta } from "@/components/ui/pagination";
import { Select } from "@/components/ui/select";

interface WorkerNeed {
  id: number;
  employee_id: number;
  employee?: { id: number; full_name: string };
  worksite_id: number | null;
  worksite?: { id: number; name: string } | null;
  date: string | null;
  need_type: string;
  description: string;
  priority: string;
  status: string;
  assigned_user_id: number | null;
  assigned_user?: { id: number; name: string } | null;
  resolved_at: string | null;
  notes: string | null;
  created_at: string | null;
}

const TYPES = ["equipment", "document", "salary_advance", "travel", "housing", "medical", "other"] as const;
const PRIORITIES = ["low", "normal", "urgent"] as const;
const STATUSES = ["open", "in_review", "resolved", "rejected"] as const;

/** Which statuses belong to which view. Mirrors the model's scopes. */
const ACTIVE_STATUSES = ["open", "in_review"] as const;
const ARCHIVE_STATUSES = ["resolved", "rejected"] as const;

const PER_PAGE = 25;

type View = "active" | "archive";

function priorityTone(priority: string): "gray" | "amber" | "red" {
  if (priority === "urgent") return "red";
  if (priority === "normal") return "amber";
  return "gray";
}

function statusTone(status: string): "gray" | "amber" | "green" | "red" {
  if (status === "resolved") return "green";
  if (status === "in_review") return "amber";
  if (status === "rejected") return "red";
  return "gray";
}

export default function WorkerNeedsPage() {
  const { t } = useI18n();

  /*
   * Completed needs are not deleted and not mixed into the working list: they
   * move to a history view with its own search, filters and sorting, so a
   * resolved request stays findable without cluttering what still needs doing.
   */
  const [view, setView] = useState<View>("active");
  const [status, setStatus] = useState("");
  const [priority, setPriority] = useState("");
  const [needType, setNeedType] = useState("");
  const [search, setSearch] = useState("");
  const [sort, setSort] = useState("");
  const [direction, setDirection] = useState<"asc" | "desc">("desc");
  const [seenView, setSeenView] = useState(view);

  const debouncedSearch = useDebouncedValue(search);

  const [creating, setCreating] = useState(false);
  const [editing, setEditing] = useState<WorkerNeed | null>(null);
  const [viewing, setViewing] = useState<WorkerNeed | null>(null);

  /*
   * Switching view resets the filters that do not carry across — `status` above
   * all, since the two views have disjoint status lists and carrying one over
   * would ask the archive for an open status and show nothing. Adjusted during
   * render rather than in an effect, for the same reason as `usePage`: an
   * effect would let one request go out with the other view's filters first.
   */
  if (view !== seenView) {
    setSeenView(view);
    setStatus("");
    setSort("");
  }

  const [page, setPage] = usePage([view, status, priority, needType, debouncedSearch, sort, direction]);

  const { data, loading, refreshing, error } = useResource<{ data: WorkerNeed[]; meta: PageMeta }>(
    withQuery("/worker-needs", {
      view,
      status,
      priority,
      need_type: needType,
      search: debouncedSearch,
      sort,
      direction: sort ? direction : "",
      page,
      per_page: PER_PAGE,
    }),
  );

  const needs = data?.data ?? [];
  const archive = view === "archive";
  const statuses = archive ? ARCHIVE_STATUSES : ACTIVE_STATUSES;

  async function setNeedStatus(need: WorkerNeed, next: string) {
    await apiFetch(`/worker-needs/${need.id}`, { method: "PUT", json: { status: next } });
  }

  return (
    <div className="space-y-5">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <div>
          <h1 className="text-[2.5rem] font-light leading-none tracking-[-0.02em] text-zinc-900 dark:text-zinc-50">{t("needs.title")}</h1>
          {archive && <p className="text-sm text-zinc-500">{t("needs.archiveSubtitle")}</p>}
        </div>
        <Button onClick={() => setCreating(true)}>{t("needs.new")}</Button>
      </div>

      <div className="control-surface inline-flex flex-wrap gap-1 rounded-full p-1">
        {(["active", "archive"] as const).map((tab) => (
          <button
            key={tab}
            type="button"
            onClick={() => setView(tab)}
            className={
              view === tab
                ? "rounded-full bg-white px-4 py-1.5 text-sm font-medium text-zinc-900 shadow-[0_1px_2px_rgb(13_12_11/0.06),0_4px_12px_-6px_rgb(13_12_11/0.25)] dark:bg-white/15 dark:text-zinc-50"
                : "rounded-full px-4 py-1.5 text-sm text-zinc-500 transition-colors hover:bg-white/60 hover:text-zinc-800 dark:hover:bg-white/10 dark:hover:text-zinc-200"
            }
          >
            {tab === "active" ? t("needs.tabActive") : t("needs.tabArchive")}
          </button>
        ))}
      </div>

      <Card className="p-4">
        <div className="flex flex-wrap items-center gap-3">
          <Input
            className="max-w-xs"
            placeholder={t("needs.search")}
            value={search}
            onChange={(e) => setSearch(e.target.value)}
          />
          <Select className="max-w-[12rem]" value={status} onChange={(e) => setStatus(e.target.value)}>
            <option value="">{t("needs.allStatuses")}</option>
            {statuses.map((value) => (
              <option key={value} value={value}>
                {t(`needStatus.${value}`)}
              </option>
            ))}
          </Select>
          <Select className="max-w-[11rem]" value={priority} onChange={(e) => setPriority(e.target.value)}>
            <option value="">{t("needs.allPriorities")}</option>
            {PRIORITIES.map((value) => (
              <option key={value} value={value}>
                {t(`needPriority.${value}`)}
              </option>
            ))}
          </Select>
          <Select className="max-w-[12rem]" value={needType} onChange={(e) => setNeedType(e.target.value)}>
            <option value="">{t("needs.allTypes")}</option>
            {TYPES.map((value) => (
              <option key={value} value={value}>
                {t(`needType.${value}`)}
              </option>
            ))}
          </Select>
          <Select className="max-w-[12rem]" value={sort} onChange={(e) => setSort(e.target.value)}>
            <option value="">{t("needs.sortBy")}</option>
            <option value="date">{t("needs.sortDate")}</option>
            {archive && <option value="resolved_at">{t("needs.sortResolved")}</option>}
            <option value="priority">{t("needs.sortPriority")}</option>
            <option value="status">{t("needs.sortStatus")}</option>
          </Select>
          {sort && (
            <Select
              className="max-w-[11rem]"
              value={direction}
              onChange={(e) => setDirection(e.target.value as "asc" | "desc")}
            >
              <option value="desc">{t("needs.newest")}</option>
              <option value="asc">{t("needs.oldest")}</option>
            </Select>
          )}
        </div>
      </Card>

      {error && <p className="text-sm text-red-600">{error}</p>}

      <Card className="table-quiet scroll-quiet overflow-x-auto p-0">
        <table className="w-full min-w-[1000px] text-sm">
          <thead className="border-b border-zinc-900/8 text-left text-[11px] uppercase tracking-[0.1em] text-zinc-500 dark:border-white/10">
            <tr>
              <th className="px-4 py-3">{t("needs.date")}</th>
              <th className="px-4 py-3">{t("needs.worker")}</th>
              <th className="px-4 py-3">{t("needs.worksite")}</th>
              <th className="px-4 py-3">{t("needs.type")}</th>
              <th className="px-4 py-3">{t("needs.description")}</th>
              <th className="px-4 py-3">{t("needs.priority")}</th>
              <th className="px-4 py-3">{archive ? t("needs.resolvedAt") : t("needs.status")}</th>
              <th className="px-4 py-3 text-right">{t("needs.actions")}</th>
            </tr>
          </thead>
          <tbody
            className={
              refreshing
                ? "divide-y divide-zinc-100 opacity-60 dark:divide-zinc-800"
                : "divide-y divide-zinc-900/5 dark:divide-white/8"
            }
          >
            {loading ? (
              <tr>
                <td colSpan={8} className="px-4 py-14 text-center text-sm text-zinc-500">
                  {t("common.loading")}
                </td>
              </tr>
            ) : needs.length === 0 ? (
              <tr>
                <td colSpan={8} className="px-4 py-14 text-center text-sm text-zinc-500">
                  {archive ? t("needs.noArchive") : t("needs.none")}
                </td>
              </tr>
            ) : (
              needs.map((need) => (
                <tr key={need.id} className="text-zinc-800 dark:text-zinc-200">
                  <td className="px-4 py-3">{formatDate(need.date)}</td>
                  <td className="px-4 py-3 font-medium">{need.employee?.full_name ?? `#${need.employee_id}`}</td>
                  <td className="px-4 py-3">{need.worksite?.name ?? "—"}</td>
                  <td className="px-4 py-3">{t(`needType.${need.need_type}`)}</td>
                  <td className="max-w-[22rem] px-4 py-3">
                    <span className="line-clamp-2">{need.description}</span>
                  </td>
                  <td className="px-4 py-3">
                    <Badge tone={priorityTone(need.priority)}>{t(`needPriority.${need.priority}`)}</Badge>
                  </td>
                  <td className="px-4 py-3">
                    <span className="flex flex-col gap-1">
                      <Badge tone={statusTone(need.status)}>{t(`needStatus.${need.status}`)}</Badge>
                      {archive ? (
                        <span className="text-xs text-zinc-500">{formatDate(need.resolved_at)}</span>
                      ) : (
                        need.assigned_user && (
                          <span className="text-xs text-zinc-500">{need.assigned_user.name}</span>
                        )
                      )}
                    </span>
                  </td>
                  <td className="px-4 py-3 text-right">
                    <div className="flex justify-end gap-2">
                      <Button variant="secondary" className="h-8 px-3" onClick={() => setViewing(need)}>
                        {t("needs.view")}
                      </Button>
                      {archive ? (
                        <Button
                          variant="secondary"
                          className="h-8 px-3"
                          onClick={() => void setNeedStatus(need, "open")}
                        >
                          {t("needs.reopen")}
                        </Button>
                      ) : (
                        <>
                          {need.status === "open" && (
                            <Button
                              variant="secondary"
                              className="h-8 px-3"
                              onClick={() => void setNeedStatus(need, "in_review")}
                            >
                              {t("needs.review")}
                            </Button>
                          )}
                          <Button
                            variant="secondary"
                            className="h-8 px-3"
                            onClick={() => void setNeedStatus(need, "resolved")}
                          >
                            {t("needs.resolve")}
                          </Button>
                        </>
                      )}
                      <Button variant="secondary" className="h-8 px-3" onClick={() => setEditing(need)}>
                        {t("needs.edit")}
                      </Button>
                    </div>
                  </td>
                </tr>
              ))
            )}
          </tbody>
        </table>

        <Pagination meta={data?.meta} onPageChange={setPage} disabled={refreshing} />
      </Card>

      {creating && <NeedModal onClose={() => setCreating(false)} />}
      {editing && <NeedModal need={editing} onClose={() => setEditing(null)} />}
      {viewing && (
        <NeedDetails
          need={viewing}
          onEdit={() => {
            setEditing(viewing);
            setViewing(null);
          }}
          onClose={() => setViewing(null)}
        />
      )}
    </div>
  );
}

/** Read-only detail, so a row in the history can be opened without editing it. */
function NeedDetails({
  need,
  onEdit,
  onClose,
}: {
  need: WorkerNeed;
  onEdit: () => void;
  onClose: () => void;
}) {
  const { t } = useI18n();

  const rows: Array<[string, string]> = [
    [t("needs.worker"), need.employee?.full_name ?? `#${need.employee_id}`],
    [t("needs.worksite"), need.worksite?.name ?? "—"],
    [t("needs.type"), t(`needType.${need.need_type}`)],
    [t("needs.priority"), t(`needPriority.${need.priority}`)],
    [t("needs.status"), t(`needStatus.${need.status}`)],
    [t("needs.date"), formatDate(need.date)],
    [t("needs.resolvedAt"), need.resolved_at ? formatDate(need.resolved_at) : "—"],
    [t("needs.assignedTo"), need.assigned_user?.name ?? t("needs.unassigned")],
    [t("needs.createdAt"), formatDate(need.created_at)],
  ];

  return (
    <Modal open onClose={onClose} title={t("needs.detailsTitle")}>
      <div className="space-y-4">
        <p className="whitespace-pre-wrap rounded-md bg-zinc-50 p-3 text-sm text-zinc-800 dark:bg-zinc-800 dark:text-zinc-100">
          {need.description}
        </p>

        <dl className="grid grid-cols-2 gap-x-4 gap-y-2 text-sm">
          {rows.map(([label, value]) => (
            <div key={label} className="flex flex-col">
              <dt className="text-xs uppercase tracking-wider text-zinc-500">{label}</dt>
              <dd className="text-zinc-900 dark:text-zinc-100">{value}</dd>
            </div>
          ))}
        </dl>

        {need.notes && (
          <div>
            <p className="text-xs uppercase tracking-wider text-zinc-500">{t("needs.notes")}</p>
            <p className="whitespace-pre-wrap text-sm text-zinc-800 dark:text-zinc-100">{need.notes}</p>
          </div>
        )}

        <div className="flex justify-end gap-2">
          <Button variant="secondary" onClick={onEdit}>
            {t("needs.edit")}
          </Button>
          <Button onClick={onClose}>{t("common.close")}</Button>
        </div>
      </div>
    </Modal>
  );
}

function NeedModal({ need, onClose }: { need?: WorkerNeed; onClose: () => void }) {
  const { t } = useI18n();

  const [employeeId, setEmployeeId] = useState<number | null>(need?.employee_id ?? null);
  const [worksiteId, setWorksiteId] = useState<number | null>(need?.worksite_id ?? null);
  const [assignedUserId, setAssignedUserId] = useState<number | null>(need?.assigned_user_id ?? null);
  const [date, setDate] = useState(need?.date ?? todayISO());
  const [needType, setNeedType] = useState(need?.need_type ?? "equipment");
  const [description, setDescription] = useState(need?.description ?? "");
  const [priority, setPriority] = useState(need?.priority ?? "normal");
  const [status, setStatus] = useState(need?.status ?? "open");
  const [notes, setNotes] = useState(need?.notes ?? "");
  const [error, setError] = useState<string | null>(null);
  const [saving, setSaving] = useState(false);

  async function submit(e: React.FormEvent) {
    e.preventDefault();
    setSaving(true);
    setError(null);

    const body = {
      worksite_id: worksiteId,
      assigned_user_id: assignedUserId,
      need_type: needType,
      description: description.trim(),
      priority,
      status,
      notes: notes || null,
    };

    try {
      await apiFetch(need ? `/worker-needs/${need.id}` : "/worker-needs", {
        method: need ? "PUT" : "POST",
        json: need ? body : { ...body, employee_id: employeeId, date },
      });
      onClose();
    } catch (err) {
      setError(errorMessage(err));
    } finally {
      setSaving(false);
    }
  }

  async function remove() {
    if (!need || !window.confirm(t("needs.removeConfirm"))) return;

    setSaving(true);
    try {
      await apiFetch(`/worker-needs/${need.id}`, { method: "DELETE" });
      onClose();
    } catch (err) {
      setError(errorMessage(err));
      setSaving(false);
    }
  }

  const label = "mb-1.5 block px-4 text-[11px] font-medium uppercase tracking-[0.08em] text-zinc-500";

  return (
    <Modal open onClose={onClose} title={need ? t("needs.edit") : t("needs.new")}>
      <form onSubmit={submit} className="space-y-4">
        <div className="grid grid-cols-2 gap-4">
          <div>
            <label className={label}>{t("needs.worker")}</label>
            {/* The worker and the date are what was filed; they stay as filed. */}
            {need ? (
              <p className="py-2 text-sm text-zinc-700 dark:text-zinc-200">{need.employee?.full_name}</p>
            ) : (
              <AsyncSelect
                resource="employees"
                value={employeeId}
                onChange={setEmployeeId}
                params={{ active_only: true }}
                placeholder={t("needs.selectWorker")}
                required
              />
            )}
          </div>
          <div>
            <label className={label}>{t("needs.date")}</label>
            {need ? (
              <p className="py-2 text-sm text-zinc-700 dark:text-zinc-200">{formatDate(need.date)}</p>
            ) : (
              <Input type="date" value={date} onChange={(e) => setDate(e.target.value)} required />
            )}
          </div>
          <div>
            <label className={label}>{t("needs.worksite")}</label>
            <AsyncSelect
              resource="worksites"
              value={worksiteId}
              onChange={setWorksiteId}
              params={{ active_only: true }}
              emptyLabel={t("needs.noWorksite")}
            />
          </div>
          <div>
            <label className={label}>{t("needs.assignedTo")}</label>
            <AsyncSelect
              resource="users"
              value={assignedUserId}
              onChange={setAssignedUserId}
              params={{ active_only: true }}
              emptyLabel={t("needs.unassigned")}
            />
          </div>
          <div>
            <label className={label}>{t("needs.type")}</label>
            <Select value={needType} onChange={(e) => setNeedType(e.target.value)}>
              {TYPES.map((value) => (
                <option key={value} value={value}>
                  {t(`needType.${value}`)}
                </option>
              ))}
            </Select>
          </div>
          <div>
            <label className={label}>{t("needs.priority")}</label>
            <Select value={priority} onChange={(e) => setPriority(e.target.value)}>
              {PRIORITIES.map((value) => (
                <option key={value} value={value}>
                  {t(`needPriority.${value}`)}
                </option>
              ))}
            </Select>
          </div>
          <div>
            <label className={label}>{t("needs.status")}</label>
            <Select value={status} onChange={(e) => setStatus(e.target.value)}>
              {STATUSES.map((value) => (
                <option key={value} value={value}>
                  {t(`needStatus.${value}`)}
                </option>
              ))}
            </Select>
          </div>
        </div>
        <div>
          <label className={label}>{t("needs.description")}</label>
          <Input value={description} onChange={(e) => setDescription(e.target.value)} required />
        </div>
        <div>
          <label className={label}>{t("needs.notes")}</label>
          <Input value={notes} onChange={(e) => setNotes(e.target.value)} />
        </div>

        {error && <p className="text-sm text-red-600">{error}</p>}

        <div className="flex justify-between gap-2">
          {need ? (
            <Button type="button" variant="danger" disabled={saving} onClick={() => void remove()}>
              {t("needs.remove")}
            </Button>
          ) : (
            <span />
          )}
          <div className="flex gap-2">
            <Button type="button" variant="secondary" onClick={onClose}>
              {t("common.cancel")}
            </Button>
            <Button type="submit" disabled={saving || (!need && employeeId === null)}>
              {saving ? t("common.saving") : t("common.save")}
            </Button>
          </div>
        </div>
      </form>
    </Modal>
  );
}
