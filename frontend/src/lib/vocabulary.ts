"use client";

import { useResource } from "@/lib/data/use-resource";
import { useI18n } from "@/lib/i18n/context";

/** One value in one of the company's own lists. */
export interface VocabularyValue {
  value: string;
  is_active: boolean;
  /** Shipped with the app: deactivatable, never deletable. */
  is_system: boolean;
  sort_order: number;
  /** How many records hold it — what retiring it would leave behind. */
  records: number;
}

interface VocabularyRow {
  key: string;
  values: VocabularyValue[];
}

/**
 * The open-ended lists, as this company has them.
 *
 * `material_type` shipped as bauxite/overburden/limestone, which is the app
 * telling a construction firm what it produces. These come from the server now,
 * so a form offers what the company actually uses.
 *
 * Read once and shared: `useResource` dedupes it across every form on the page.
 */
export function useVocabularies() {
  const { data } = useResource<{ data: VocabularyRow[] }>("/company-settings/vocabularies", {
    keepAlive: true,
  });

  const rows = data?.data ?? [];

  return {
    rows,
    /**
     * The values a form should offer.
     *
     * `current` is the value the record being edited already holds: an inactive
     * value has to stay in its own form or opening an old record would silently
     * blank it — the same rule the async lookups follow for closed accounts.
     */
    values(vocabulary: string, current?: string | null): string[] {
      const row = rows.find((r) => r.key === vocabulary);
      if (!row) return [];

      const offered = row.values.filter((v) => v.is_active).map((v) => v.value);

      return current && !offered.includes(current) ? [...offered, current] : offered;
    },
  };
}

/**
 * How to render one value.
 *
 * The company's own wording first (terminology, `vocab.{vocabulary}.{value}`),
 * then the built-in label for a value the app shipped with, then the raw value —
 * which is what a company's own value reads as until somebody names it, and is
 * still better than an empty cell.
 */
export function useVocabularyLabel(): (vocabulary: string, prefix: string, value?: string | null) => string {
  const { t } = useI18n();

  return (vocabulary, prefix, value) => {
    if (!value) return "—";

    const named = t(`vocab.${vocabulary}.${value}`);
    if (named !== `vocab.${vocabulary}.${value}`) return named;

    const builtIn = t(`${prefix}.${value}`);
    if (builtIn !== `${prefix}.${value}`) return builtIn;

    return value;
  };
}
