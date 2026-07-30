import { formatDate, formatMoney } from "@/lib/format";

export interface AppNotification {
  id: number;
  type: string;
  severity: string;
  data: Record<string, unknown>;
  subject_type: string | null;
  subject_id: number | null;
  link: string;
  due_date: string | null;
  period: string | null;
  status: string;
  read_at: string | null;
  dismissed_at: string | null;
  resolved_at: string | null;
  created_at: string;
}

export interface NotificationCounts {
  unread: number;
  open: number;
  critical: number;
}

export const NOTIFICATION_TYPES = [
  "payables.unpaid",
  "payables.overdue",
  "housing.rent_unpaid",
  "housing.bills_overdue",
  "housing.contract_expiring",
  "salaries.unpaid",
  "attendance.unapproved",
  "attendance.overtime_pending",
  "worker_needs.urgent_open",
  "mining.production_missing",
  "machines.document_expiring",
  "customs.incomplete",
  "bank.unmatched",
  "employees.missing_documents",
  "employees.document_expiring",
  "custom.reminder",
] as const;

export const NOTIFICATION_TIMINGS = [
  "same_day",
  "days_before",
  "after_due",
  "weekly_summary",
  "monthly_summary",
] as const;

export const NOTIFICATION_SEVERITIES = ["info", "warning", "critical"] as const;

/** Params the backend sends as raw numbers/dates but that read better formatted. */
const MONEY_KEYS = new Set(["amount", "total"]);
const DATE_KEYS = new Set(["date", "month", "due_date", "expires_on", "contract_end_date", "period"]);

type Translate = (key: string, vars?: Record<string, string | number>) => string;

/**
 * Builds the sentence for a notification in the viewer's language. The backend
 * stores only `type` + language-neutral params, so the same row reads correctly
 * in en/sr/tr — the wording lives entirely in the dictionaries.
 *
 * The one exception is a custom reminder, whose title is the author's own words
 * and is shown exactly as written.
 */
export function notificationMessage(t: Translate, notification: AppNotification): string {
  if (notification.type === "custom.reminder") {
    return String(notification.data.title ?? t("notifType.custom.reminder"));
  }

  const vars: Record<string, string | number> = {};

  for (const [key, value] of Object.entries(notification.data ?? {})) {
    if (value === null || value === undefined) {
      vars[key] = "—";
    } else if (MONEY_KEYS.has(key) && typeof value === "number") {
      vars[key] = formatMoney(value);
    } else if (DATE_KEYS.has(key) && typeof value === "string") {
      vars[key] = formatDate(value);
    } else if (key === "document") {
      // Field names map to their own labels (work_permit_expiry → "Work permit").
      vars[key] = t(`documentField.${value}`);
    } else if (Array.isArray(value)) {
      vars[key] = value.map((entry) => t(`documentField.${entry}`)).join(", ");
    } else {
      vars[key] = String(value);
    }
  }

  return t(`notifMsg.${notification.type}`, vars);
}

export function severityTone(severity: string): "gray" | "amber" | "red" {
  if (severity === "critical") return "red";
  if (severity === "warning") return "amber";
  return "gray";
}
