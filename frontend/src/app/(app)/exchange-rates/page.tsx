"use client";

import { useState } from "react";
import { apiFetch, errorMessage } from "@/lib/api";
import { useResource } from "@/lib/data/use-resource";
import { useI18n } from "@/lib/i18n/context";
import { formatDate, todayISO } from "@/lib/format";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Card } from "@/components/ui/card";
import { Input } from "@/components/ui/input";
import { Modal } from "@/components/ui/modal";
import { Select } from "@/components/ui/select";

interface Rate {
  id: number;
  base_currency: string;
  quote_currency: string;
  rate: string;
  rate_date: string;
  provider: string | null;
  is_manual: boolean;
  override_reason: string | null;
  fetched_at: string | null;
}

/** Currencies the app converts from. EUR is the accounting currency (rule 5). */
const QUOTES = ["TRY", "USD", "RSD"] as const;

/** Rates are stored to ten decimals; showing six is enough to read one. */
function formatRate(rate: string): string {
  const value = Number(rate);
  return Number.isFinite(value) ? value.toFixed(6).replace(/0+$/, "").replace(/\.$/, "") : rate;
}

export default function ExchangeRatesPage() {
  const { t } = useI18n();
  const [overriding, setOverriding] = useState(false);
  const [syncing, setSyncing] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const { data, loading } = useResource<{ data: Rate[]; latest: Rate[] }>("/exchange-rates");
  const rates = data?.data ?? [];
  const latest = data?.latest ?? [];

  async function sync() {
    setSyncing(true);
    setError(null);
    try {
      await apiFetch("/exchange-rates/sync", { method: "POST", json: { quotes: ["TRY"] } });
    } catch (err) {
      setError(errorMessage(err));
    } finally {
      setSyncing(false);
    }
  }

  return (
    <div className="space-y-5">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <div>
          <h1 className="text-2xl font-semibold text-zinc-900 dark:text-zinc-50">{t("rates.title")}</h1>
          <p className="text-sm text-zinc-500">{t("rates.subtitle")}</p>
        </div>
        <div className="flex gap-2">
          <Button variant="secondary" onClick={() => setOverriding(true)}>
            {t("rates.override")}
          </Button>
          <Button onClick={() => void sync()} disabled={syncing}>
            {syncing ? t("rates.syncing") : t("rates.sync")}
          </Button>
        </div>
      </div>

      {error && <p className="text-sm text-red-600">{error}</p>}

      {/* What the app will actually convert with today. */}
      <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
        {latest.map((rate) => (
          <Card key={rate.id} className="p-4">
            <p className="text-xs uppercase tracking-wider text-zinc-500">
              1 {rate.base_currency} =
            </p>
            <p className="mt-1 text-2xl font-semibold tabular-nums text-zinc-900 dark:text-zinc-50">
              {formatRate(rate.rate)} {rate.quote_currency}
            </p>
            <div className="mt-2 flex items-center gap-2">
              <span className="text-xs text-zinc-500">{formatDate(rate.rate_date)}</span>
              <Badge tone={rate.is_manual ? "amber" : "green"}>
                {rate.is_manual ? t("rates.manual") : rate.provider ?? t("rates.automatic")}
              </Badge>
            </div>
          </Card>
        ))}
        {!loading && latest.length === 0 && (
          <Card className="p-4 text-sm text-zinc-500">{t("rates.noneYet")}</Card>
        )}
      </div>

      <Card className="overflow-x-auto p-0">
        <table className="w-full min-w-[720px] text-sm">
          <thead className="border-b border-zinc-200 text-left text-xs uppercase tracking-wider text-zinc-500 dark:border-zinc-800">
            <tr>
              <th className="px-4 py-3">{t("rates.date")}</th>
              <th className="px-4 py-3">{t("rates.pair")}</th>
              <th className="px-4 py-3 text-right">{t("rates.rate")}</th>
              <th className="px-4 py-3">{t("rates.source")}</th>
              <th className="px-4 py-3">{t("rates.reason")}</th>
            </tr>
          </thead>
          <tbody className="divide-y divide-zinc-100 dark:divide-zinc-800">
            {loading ? (
              <tr>
                <td colSpan={5} className="px-4 py-8 text-center text-zinc-500">
                  {t("common.loading")}
                </td>
              </tr>
            ) : rates.length === 0 ? (
              <tr>
                <td colSpan={5} className="px-4 py-8 text-center text-zinc-500">
                  {t("rates.noneYet")}
                </td>
              </tr>
            ) : (
              rates.map((rate) => (
                <tr key={rate.id} className="text-zinc-800 dark:text-zinc-200">
                  <td className="px-4 py-3">{formatDate(rate.rate_date)}</td>
                  <td className="px-4 py-3 font-medium">
                    {rate.base_currency}/{rate.quote_currency}
                  </td>
                  <td className="px-4 py-3 text-right tabular-nums">{formatRate(rate.rate)}</td>
                  <td className="px-4 py-3">
                    <Badge tone={rate.is_manual ? "amber" : "gray"}>
                      {rate.is_manual ? t("rates.manual") : rate.provider ?? t("rates.automatic")}
                    </Badge>
                  </td>
                  {/* The reason is the operator's own words — shown verbatim. */}
                  <td className="px-4 py-3 text-zinc-500">{rate.override_reason ?? "—"}</td>
                </tr>
              ))
            )}
          </tbody>
        </table>
      </Card>

      {overriding && <OverrideModal onClose={() => setOverriding(false)} />}
    </div>
  );
}

/** A manual rate always carries a recorded reason — that is the whole point of it. */
function OverrideModal({ onClose }: { onClose: () => void }) {
  const { t } = useI18n();
  const [quote, setQuote] = useState<string>("TRY");
  const [rate, setRate] = useState("");
  const [rateDate, setRateDate] = useState(todayISO());
  const [reason, setReason] = useState("");
  const [error, setError] = useState<string | null>(null);
  const [saving, setSaving] = useState(false);

  async function submit(e: React.FormEvent) {
    e.preventDefault();
    setSaving(true);
    setError(null);
    try {
      await apiFetch("/exchange-rates/manual", {
        method: "POST",
        json: {
          base_currency: "EUR",
          quote_currency: quote,
          rate: Number(rate),
          rate_date: rateDate,
          override_reason: reason.trim(),
        },
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
    <Modal open onClose={onClose} title={t("rates.override")}>
      <form onSubmit={submit} className="space-y-4">
        <p className="text-sm text-zinc-500">{t("rates.overrideHint")}</p>
        <div className="grid grid-cols-3 gap-4">
          <div>
            <label className={label}>{t("rates.currency")}</label>
            <Select value={quote} onChange={(e) => setQuote(e.target.value)}>
              {QUOTES.map((code) => (
                <option key={code} value={code}>
                  {code}
                </option>
              ))}
            </Select>
          </div>
          <div>
            <label className={label}>{t("rates.rate")}</label>
            <Input
              type="number"
              step="0.000001"
              min="0"
              value={rate}
              onChange={(e) => setRate(e.target.value)}
              required
            />
          </div>
          <div>
            <label className={label}>{t("rates.date")}</label>
            <Input type="date" value={rateDate} onChange={(e) => setRateDate(e.target.value)} required />
          </div>
        </div>
        <div>
          <label className={label}>{t("rates.reason")}</label>
          <Input value={reason} onChange={(e) => setReason(e.target.value)} required />
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
