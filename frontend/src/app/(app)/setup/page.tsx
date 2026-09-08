"use client";

import { useState } from "react";
import { useRouter } from "next/navigation";
import { ApiError, apiFetch } from "@/lib/api";
import { useResource } from "@/lib/data/use-resource";
import { useI18n } from "@/lib/i18n/context";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Card } from "@/components/ui/card";

interface Profile {
  key: string;
  modules: string[];
  work_structure_levels: string[];
  rules: Record<string, string | number>;
  /** How many terms this profile renames. */
  terminology_terms: number;
  /** Which lists it replaces. */
  vocabularies: string[];
  /** False for a profile reasoned from the domain rather than from a customer. */
  validated: boolean;
}

interface ProfilesResponse {
  data: Profile[];
  current: string | null;
  /** What is already in this install, by name. Empty means safe to apply. */
  existing_records: Record<string, number>;
}

/**
 * Setting the install up as one shape of company.
 *
 * A profile is a starting point, not a mode: it writes the ordinary settings the
 * three editors below it already own — modules, terminology, lists, payroll
 * rules — and every one of them stays editable afterwards. Nothing here locks
 * anything in.
 */
export default function SetupPage() {
  const { t } = useI18n();
  const router = useRouter();
  const { data } = useResource<ProfilesResponse>("/company-settings/profiles");

  const [applying, setApplying] = useState<string | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [done, setDone] = useState<string | null>(null);

  if (!data) return <p className="text-sm text-zinc-500">{t("common.loading")}</p>;

  const inUse = Object.entries(data.existing_records);

  async function apply(key: string, force: boolean) {
    setApplying(key);
    setError(null);
    try {
      await apiFetch("/company-settings/profile", { method: "POST", json: { profile: key, force } });
      setDone(key);
      router.refresh();
    } catch (err) {
      setError(err instanceof ApiError ? err.message : "Error");
    } finally {
      setApplying(null);
    }
  }

  return (
    <div className="max-w-3xl space-y-6">
      <div>
        <p className="mt-2 max-w-2xl text-sm text-zinc-500">{t("setup.subtitle")}</p>
      </div>

      {data.current && (
        <Card className="p-4">
          <p className="text-sm text-zinc-600 dark:text-zinc-300">
            {t("setup.current", { profile: t(`profile.${data.current}`) })}
          </p>
        </Card>
      )}

      {inUse.length > 0 && (
        <Card className="p-4">
          {/*
            Applying over records rewrites wording and retires list values those
            records hold. Recoverable, but not something anyone means to do by
            clicking a button on a live system.
          */}
          <p className="text-sm font-medium text-amber-700 dark:text-amber-500">{t("setup.inUseTitle")}</p>
          <p className="mt-1 text-xs text-zinc-500">{t("setup.inUseHint")}</p>
          <p className="mt-2 text-xs text-zinc-500">
            {inUse.map(([label, count]) => `${count} ${label}`).join(" · ")}
          </p>
        </Card>
      )}

      {error && <p className="text-sm text-red-600">{error}</p>}
      {done && <p className="text-sm text-green-600">{t("setup.applied", { profile: t(`profile.${done}`) })}</p>}

      <div className="space-y-4">
        {data.data.map((profile) => (
          <Card key={profile.key}>
            <div className="flex items-start justify-between gap-4">
              <div className="min-w-0">
                <h2 className="text-base font-medium text-zinc-900 dark:text-zinc-50">
                  {t(`profile.${profile.key}`)}
                  {data.current === profile.key && (
                    <span className="ml-2 inline-block align-middle">
                      <Badge tone="green">{t("setup.currentBadge")}</Badge>
                    </span>
                  )}
                </h2>
                <p className="mt-1 text-sm text-zinc-500">{t(`profile.${profile.key}.description`)}</p>

                {/*
                  Two of the three were reasoned rather than observed. Saying so
                  turns a default somebody would otherwise trust into an
                  invitation to correct it — which is what it is.
                */}
                {!profile.validated && (
                  <p className="mt-1.5 text-xs text-zinc-500">{t("setup.startingPoint")}</p>
                )}
              </div>

              <Button
                type="button"
                disabled={applying !== null}
                onClick={() => apply(profile.key, inUse.length > 0)}
              >
                {applying === profile.key ? t("common.saving") : t("setup.apply")}
              </Button>
            </div>

            <dl className="mt-4 grid grid-cols-2 gap-3 text-xs sm:grid-cols-4">
              <Fact label={t("setup.modules")} value={String(profile.modules.length)} />
              <Fact
                label={t("setup.levels")}
                value={profile.work_structure_levels.map((l) => t(`structureLevel.${l}`)).join(" › ")}
              />
              <Fact
                label={t("setup.week")}
                value={t(`workingDayRule.${profile.rules.working_day_rule}`)}
              />
              <Fact
                label={t("setup.wording")}
                value={
                  profile.terminology_terms === 0
                    ? t("setup.wordingNone")
                    : t("setup.wordingCount", { count: profile.terminology_terms })
                }
              />
            </dl>
          </Card>
        ))}
      </div>

      <p className="text-xs text-zinc-500">{t("setup.footnote")}</p>
    </div>
  );
}

function Fact({ label, value }: { label: string; value: string }) {
  return (
    <div>
      <dt className="uppercase tracking-[0.08em] text-zinc-500 dark:text-zinc-400">{label}</dt>
      <dd className="mt-0.5 text-zinc-700 dark:text-zinc-200">{value}</dd>
    </div>
  );
}
