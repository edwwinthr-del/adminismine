import { dictionaries } from "@/lib/i18n/dictionaries";

type Translate = (key: string) => string;

/** Whether a key actually exists, checked against `en` — every locale falls back to it. */
function known(key: string): boolean {
  return dictionaries.en[key] !== undefined;
}

/** `PayableInvoice` / `bank_transactions` → `Payable invoice` / `Bank transactions`. */
function humanise(raw: string): string {
  const spaced = raw
    .replace(/[_.]+/g, " ")
    .replace(/([a-z0-9])([A-Z])/g, "$1 $2")
    .trim()
    .toLowerCase();

  return spaced.charAt(0).toUpperCase() + spaced.slice(1);
}

/**
 * Render a stored permission name into the reader's language.
 *
 * Permissions are stored as canonical `area.action` keys — `payables.view`,
 * `company.terminology.manage` — which is right (rule 4) and was half the job.
 * The other half was never built: the roles screen printed the key verbatim, so
 * a Serbian administrator assigning access read `bank_transactions.manage`.
 *
 * Composed from its parts rather than translated one string at a time, the same
 * way {@link auditEventLabel} does it: the area is everything before the last
 * dot, the action is the last segment. That is 38 strings per locale instead of
 * 34 — and, the point, a permission added by a module written next year renders
 * without anyone remembering to translate it.
 *
 * Inside a group already headed "Payables", the action alone is what reads
 * naturally: View, Create, Approve. Three-segment permissions keep their middle
 * word, so "Company" holds "Settings · Manage" and "Terminology · Manage".
 *
 * Anything not covered falls back to a humanised form of the key itself, so an
 * unknown permission reads as "Bank transactions manage" rather than as a
 * missing translation.
 */
export function permissionLabel(permission: string, t: Translate, withArea = false): string {
  const lastDot = permission.lastIndexOf(".");
  if (lastDot < 0) return humanise(permission);

  const area = permission.slice(0, lastDot);
  const action = permission.slice(lastDot + 1);

  const actionKey = `permissionAction.${action}`;
  const actionLabel = known(actionKey) ? t(actionKey) : humanise(action);

  // The middle word of a three-segment permission — what tells "Settings ·
  // Manage" apart from "Modules · Manage" once the group heading has already
  // said "Company".
  const middle = area.includes(".") ? area.slice(area.indexOf(".") + 1) : null;

  if (!withArea && middle) {
    const subKey = `permissionSub.${middle}`;
    return `${known(subKey) ? t(subKey) : humanise(middle)} · ${actionLabel}`;
  }

  if (!withArea) return actionLabel;

  return `${permissionGroupLabel(area.split(".")[0], t)} · ${actionLabel}`;
}

/** The heading a group of permissions sits under — the part before the first dot. */
export function permissionGroupLabel(group: string, t: Translate): string {
  const key = `permissionGroup.${group}`;

  return known(key) ? t(key) : humanise(group);
}

/**
 * A role's name.
 *
 * The five the app ships with are canonical and translated; anything an admin
 * created is their own words and is shown exactly as typed — translating a
 * name somebody chose would be the same mistake as translating a supplier's.
 */
export function roleLabel(name: string, t: Translate): string {
  const key = `role.${name.toLowerCase().replace(/\s+/g, "_")}`;

  return known(key) ? t(key) : name;
}

/**
 * The kind of record an audit entry is about.
 *
 * The API sends the model's class basename (`PayableInvoice`), which is a
 * canonical value and not a label. Humanised where untranslated, so a model
 * added later reads as "Rent payment" rather than "RentPayment".
 */
export function recordTypeLabel(type: string, t: Translate): string {
  const key = `recordType.${type}`;

  return known(key) ? t(key) : humanise(type);
}
