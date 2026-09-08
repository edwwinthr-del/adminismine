"use client";

import { useEffect, useRef, useState } from "react";
import { ApiError, apiFetch } from "@/lib/api";
import { useResource } from "@/lib/data/use-resource";
import { useI18n } from "@/lib/i18n/context";
import { formatMoney } from "@/lib/format";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Card } from "@/components/ui/card";
import { Input } from "@/components/ui/input";

interface Suggestion {
  id: number;
  kind: string;
  target: string;
  proposed: Record<string, unknown>;
  validated: Record<string, unknown> | null;
  errors: Record<string, string[]> | null;
  status: string;
  record_id: number | null;
}

interface ReportPayload {
  key: string;
  columns: { key: string; label: string; type: string }[];
  rows: Record<string, unknown>[];
  row_count: number;
  totals: Record<string, number>;
}

interface Message {
  id: number;
  role: string;
  content: string;
  intent: string | null;
  data: {
    report?: ReportPayload;
    search?: { entity: string; results: { id: number; name: string }[] };
    dashboard?: Record<string, unknown>;
    error?: string;
  } | null;
  suggestion: Suggestion | null;
  created_at: string;
}

const EXAMPLES = [
  "assistant.example1",
  "assistant.example2",
  "assistant.example3",
  "assistant.example4",
] as const;

export default function AssistantPage() {
  const { t } = useI18n();
  const [question, setQuestion] = useState("");
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const endRef = useRef<HTMLDivElement>(null);

  // The transcript is server state, not a draft — the only thing typed here is
  // `question`, which is held separately. So it can render the cache directly:
  // asking, confirming a suggestion and clearing are all writes, and each one's
  // markMutated() brings the thread back current.
  const { data, error: historyError } = useResource<{ data: Message[] }>("/assistant/messages");
  const messages = data?.data ?? [];

  useEffect(() => {
    endRef.current?.scrollIntoView({ behavior: "smooth" });
  }, [messages]);

  async function ask(text: string) {
    const asked = text.trim();
    if (asked === "") return;

    setBusy(true);
    setError(null);
    setQuestion("");
    try {
      await apiFetch<{ data: Message }>("/assistant/ask", {
        method: "POST",
        json: { question: asked },
      });
    } catch (err) {
      setError(err instanceof ApiError ? err.message : "Error");
    } finally {
      setBusy(false);
    }
  }

  async function act(suggestion: Suggestion, action: "confirm" | "reject") {
    setBusy(true);
    setError(null);
    try {
      await apiFetch(`/assistant/suggestions/${suggestion.id}/${action}`, { method: "POST" });
    } catch (err) {
      setError(err instanceof ApiError ? err.message : "Error");
    } finally {
      setBusy(false);
    }
  }

  async function clear() {
    if (!window.confirm(t("assistant.clearConfirm"))) return;
    await apiFetch("/assistant/messages", { method: "DELETE" });
  }

  return (
    <div className="space-y-5">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <div>
          <p className="text-sm text-zinc-500">{t("assistant.subtitle")}</p>
        </div>
        {messages.length > 0 && (
          <Button variant="secondary" onClick={() => void clear()}>
            {t("assistant.clear")}
          </Button>
        )}
      </div>

      {(error ?? historyError) && <p className="text-sm text-red-600">{error ?? historyError}</p>}

      <Card className="space-y-4">
        <div className="max-h-[26rem] space-y-4 overflow-y-auto pr-1">
          {messages.length === 0 ? (
            <div className="space-y-3 py-6 text-center">
              <p className="text-sm text-zinc-500">{t("assistant.empty")}</p>
              <div className="flex flex-wrap justify-center gap-2">
                {EXAMPLES.map((key) => (
                  <button
                    key={key}
                    onClick={() => void ask(t(key))}
                    className="rounded-full border border-zinc-300 px-3 py-1 text-xs text-zinc-600 transition-colors hover:bg-zinc-50 dark:border-zinc-700 dark:text-zinc-300 dark:hover:bg-zinc-800"
                  >
                    {t(key)}
                  </button>
                ))}
              </div>
            </div>
          ) : (
            messages.map((message) => (
              <div
                key={message.id}
                className={message.role === "user" ? "flex justify-end" : "flex justify-start"}
              >
                <div
                  className={
                    message.role === "user"
                      ? "max-w-[85%] rounded-2xl rounded-br-sm bg-indigo-600 px-4 py-2 text-sm text-white"
                      : "w-full max-w-[95%] space-y-3"
                  }
                >
                  {message.role === "user" ? (
                    message.content
                  ) : (
                    <>
                      <div className="rounded-2xl rounded-bl-sm bg-zinc-100 px-4 py-2 text-sm text-zinc-800 dark:bg-zinc-800 dark:text-zinc-100">
                        {message.content}
                      </div>

                      {message.data?.report && <ReportBlock report={message.data.report} />}
                      {message.data?.search && (
                        <div className="flex flex-wrap gap-2">
                          {message.data.search.results.map((row) => (
                            <Badge key={row.id} tone="indigo">
                              {row.name}
                            </Badge>
                          ))}
                        </div>
                      )}
                      {message.suggestion && (
                        <SuggestionBlock
                          suggestion={message.suggestion}
                          busy={busy}
                          onAct={(action) => void act(message.suggestion!, action)}
                        />
                      )}
                    </>
                  )}
                </div>
              </div>
            ))
          )}
          <div ref={endRef} />
        </div>

        <form
          onSubmit={(e) => {
            e.preventDefault();
            void ask(question);
          }}
          className="flex gap-2 border-t border-zinc-200 pt-4 dark:border-zinc-800"
        >
          <Input
            value={question}
            onChange={(e) => setQuestion(e.target.value)}
            placeholder={t("assistant.placeholder")}
            disabled={busy}
          />
          <Button type="submit" disabled={busy || question.trim() === ""}>
            {busy ? t("assistant.thinking") : t("assistant.send")}
          </Button>
        </form>
      </Card>
    </div>
  );
}

function ReportBlock({ report }: { report: ReportPayload }) {
  const { t } = useI18n();

  return (
    <Card className="vui-table scroll-quiet overflow-x-auto p-0">
      <p className="px-[var(--vui-pad-card)] pb-2 pt-[var(--vui-pad-card)] text-xs font-medium text-zinc-500">
        {t(`report.${report.key}`)} · {t("assistant.rowCount", { count: report.row_count })}
      </p>
      <table className="w-full min-w-[560px] text-xs">
        <thead>
          <tr>
            {report.columns.map((column) => (
              <th
                key={column.key}
                className={column.type === "money" || column.type === "number" ? "text-right" : undefined}
              >
                {t(`reportColumn.${column.label}`)}
              </th>
            ))}
          </tr>
        </thead>
        <tbody>
          {report.rows.map((row, index) => (
            <tr key={index} className="text-zinc-700 dark:text-zinc-300">
              {report.columns.map((column) => {
                const value = row[column.key];
                const numeric = column.type === "money" || column.type === "number";

                return (
                  <td
                    key={column.key}
                    className={numeric ? "text-right tabular-nums" : undefined}
                  >
                    {value === null || value === undefined || value === ""
                      ? "—"
                      : column.type === "money"
                        ? formatMoney(Number(value))
                        : String(value)}
                  </td>
                );
              })}
            </tr>
          ))}
        </tbody>
      </table>
    </Card>
  );
}

/**
 * The confirmation step of the safety flow. Nothing here has been saved: the
 * panel shows exactly what would be written, and only the user's click does it.
 */
function SuggestionBlock({
  suggestion,
  busy,
  onAct,
}: {
  suggestion: Suggestion;
  busy: boolean;
  onAct: (action: "confirm" | "reject") => void;
}) {
  const { t } = useI18n();
  const fields = suggestion.validated ?? suggestion.proposed;
  const pending = suggestion.status === "pending";

  return (
    <Card className="border-amber-300 bg-amber-50/60 dark:border-amber-900 dark:bg-amber-950/30">
      <div className="mb-2 flex flex-wrap items-center justify-between gap-2">
        <p className="text-sm font-semibold text-zinc-900 dark:text-zinc-50">
          {t("assistant.proposes", { target: t(`importTarget.${suggestion.target}`) })}
        </p>
        <Badge
          tone={
            suggestion.status === "confirmed"
              ? "green"
              : suggestion.status === "pending"
                ? "amber"
                : "red"
          }
        >
          {t(`suggestionStatus.${suggestion.status}`)}
        </Badge>
      </div>

      <dl className="space-y-1 text-xs">
        {Object.entries(fields).map(([key, value]) => (
          <div key={key} className="flex gap-2">
            <dt className="min-w-[9rem] text-zinc-500">{key}</dt>
            <dd className="text-zinc-800 dark:text-zinc-200">{String(value ?? "—")}</dd>
          </div>
        ))}
      </dl>

      {suggestion.errors && (
        <ul className="mt-3 space-y-0.5 text-xs text-red-600">
          {Object.entries(suggestion.errors).map(([field, messages]) => (
            <li key={field}>
              {field}: {messages.join(" ")}
            </li>
          ))}
        </ul>
      )}

      {pending && suggestion.validated && (
        <>
          <p className="mt-3 text-xs text-amber-800 dark:text-amber-300">{t("assistant.notSavedYet")}</p>
          <div className="mt-2 flex flex-wrap justify-end gap-2">
            <Button variant="secondary" className="h-8 px-3" disabled={busy} onClick={() => onAct("reject")}>
              {t("assistant.reject")}
            </Button>
            <Button className="h-8 px-3" disabled={busy} onClick={() => onAct("confirm")}>
              {t("assistant.confirm")}
            </Button>
          </div>
        </>
      )}
      {pending && !suggestion.validated && (
        <p className="mt-3 text-xs text-red-600">{t("assistant.cannotApply")}</p>
      )}
    </Card>
  );
}
