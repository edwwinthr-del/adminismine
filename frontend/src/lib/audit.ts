import { dictionaries } from "@/lib/i18n/dictionaries";

/**
 * Render a stored audit event into the reader's language.
 *
 * The backend deliberately stores a language-neutral key — `payable.created`,
 * never a rendered sentence — so that one row reads correctly in all three
 * locales (rule 4). The storage half was right; the rendering half was never
 * built, and the audit screen printed the key verbatim. A Serbian bookkeeper
 * reading their own trail saw "payable.created".
 *
 * Composed from the two halves rather than translated event by event: the same
 * `module.action` split the row's colour tone already uses. That is 37 + 48
 * strings instead of 154 per locale, and — the point — an event added by a
 * module written next year renders without anyone remembering to translate it.
 *
 * Anything the vocabularies do not cover falls back to the raw key, which is
 * exactly what was shown before, so an unknown event can never render as
 * "auditModule.foo · auditAction.bar".
 */
export function auditEventLabel(event: string | null, t: (key: string) => string): string | null {
  if (!event) return null;

  const separator = event.indexOf(".");
  if (separator < 0) return event;

  const moduleKey = `auditModule.${event.slice(0, separator)}`;
  const actionKey = `auditAction.${event.slice(separator + 1)}`;

  // Checked against `en` because it is the fallback every locale resolves
  // through: a key absent there is absent everywhere.
  if (!(moduleKey in dictionaries.en) || !(actionKey in dictionaries.en)) {
    return event;
  }

  return `${t(moduleKey)} · ${t(actionKey)}`;
}
