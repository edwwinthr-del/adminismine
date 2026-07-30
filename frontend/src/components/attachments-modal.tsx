"use client";

import { useCallback, useEffect, useState } from "react";
import { ApiError, apiFetch, downloadToDisk } from "@/lib/api";
import { useI18n } from "@/lib/i18n/context";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Modal } from "@/components/ui/modal";
import { Select } from "@/components/ui/select";

export interface FileAttachment {
  id: number;
  kind: string;
  label: string | null;
  original_name: string;
  mime_type: string | null;
  size_bytes: number | null;
  download_url: string | null;
  created_at: string;
}

export const ATTACHMENT_KINDS = ["invoice", "warranty", "customs", "cmr", "photo", "other"] as const;

export function formatFileSize(bytes: number | null): string {
  if (bytes === null) return "—";
  if (bytes < 1024) return `${bytes} B`;
  if (bytes < 1024 * 1024) return `${Math.round(bytes / 1024)} KB`;
  return `${(bytes / (1024 * 1024)).toFixed(1)} MB`;
}

/**
 * Paperwork panel shared by every record that carries files (machines, customs
 * documents, …). `basePath` is the record's attachments endpoint; the files are
 * fetched with the bearer token, never linked to directly.
 */
export function AttachmentsModal({
  title,
  basePath,
  defaultKind = "other",
  kinds = ATTACHMENT_KINDS,
  onClose,
  onChanged,
}: {
  title: string;
  basePath: string;
  defaultKind?: string;
  kinds?: readonly string[];
  onClose: () => void;
  onChanged?: () => Promise<void>;
}) {
  const { t } = useI18n();
  const [attachments, setAttachments] = useState<FileAttachment[]>([]);
  const [loading, setLoading] = useState(true);
  const [kind, setKind] = useState<string>(defaultKind);
  const [label, setLabel] = useState("");
  const [file, setFile] = useState<File | null>(null);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const load = useCallback(async () => {
    setLoading(true);
    try {
      const res = await apiFetch<{ data: FileAttachment[] }>(basePath);
      setAttachments(res.data);
    } finally {
      setLoading(false);
    }
  }, [basePath]);

  useEffect(() => {
    void load();
  }, [load]);

  async function upload(e: React.FormEvent) {
    e.preventDefault();
    if (!file) return;
    setBusy(true);
    setError(null);
    try {
      const body = new FormData();
      body.append("file", file);
      body.append("kind", kind);
      if (label.trim()) body.append("label", label.trim());

      await apiFetch(basePath, { method: "POST", body });
      setFile(null);
      setLabel("");
      await load();
      await onChanged?.();
    } catch (err) {
      setError(err instanceof ApiError ? err.message : "Error");
    } finally {
      setBusy(false);
    }
  }

  async function remove(attachment: FileAttachment) {
    if (!window.confirm(t("files.removeConfirm"))) return;
    setBusy(true);
    setError(null);
    try {
      await apiFetch(`${basePath}/${attachment.id}`, { method: "DELETE" });
      await load();
      await onChanged?.();
    } catch (err) {
      setError(err instanceof ApiError ? err.message : "Error");
    } finally {
      setBusy(false);
    }
  }

  async function download(attachment: FileAttachment) {
    if (!attachment.download_url) return;
    setError(null);
    try {
      await downloadToDisk(attachment.download_url, attachment.original_name);
    } catch (err) {
      setError(err instanceof ApiError ? err.message : "Error");
    }
  }

  const labelClass = "mb-1 block text-sm font-medium text-zinc-700 dark:text-zinc-300";

  return (
    <Modal open onClose={onClose} title={title}>
      <div className="space-y-5">
        {loading ? (
          <p className="text-sm text-zinc-500">{t("common.loading")}</p>
        ) : attachments.length === 0 ? (
          <p className="text-sm text-zinc-500">{t("files.none")}</p>
        ) : (
          <ul className="space-y-2">
            {attachments.map((attachment) => (
              <li
                key={attachment.id}
                className="flex items-center justify-between gap-3 rounded-lg border border-zinc-200 px-3 py-2 dark:border-zinc-800"
              >
                <span className="min-w-0">
                  <span className="flex items-center gap-2">
                    <Badge tone="gray">{t(`attachmentKind.${attachment.kind}`)}</Badge>
                    <span className="truncate text-sm text-zinc-800 dark:text-zinc-100">
                      {attachment.label ?? attachment.original_name}
                    </span>
                  </span>
                  <span className="mt-1 block text-xs text-zinc-500">
                    {attachment.original_name} · {formatFileSize(attachment.size_bytes)}
                  </span>
                </span>
                <span className="flex shrink-0 gap-2">
                  <Button variant="secondary" className="h-8 px-3" onClick={() => void download(attachment)}>
                    {t("files.download")}
                  </Button>
                  <Button
                    variant="secondary"
                    className="h-8 px-3"
                    disabled={busy}
                    onClick={() => void remove(attachment)}
                  >
                    {t("files.remove")}
                  </Button>
                </span>
              </li>
            ))}
          </ul>
        )}

        <form onSubmit={upload} className="space-y-3 border-t border-zinc-200 pt-4 dark:border-zinc-800">
          <p className="text-xs font-semibold uppercase tracking-wider text-zinc-500">{t("files.add")}</p>
          <div className="grid grid-cols-2 gap-4">
            <div>
              <label className={labelClass}>{t("files.kind")}</label>
              <Select value={kind} onChange={(e) => setKind(e.target.value)}>
                {kinds.map((value) => (
                  <option key={value} value={value}>
                    {t(`attachmentKind.${value}`)}
                  </option>
                ))}
              </Select>
            </div>
            <div>
              <label className={labelClass}>{t("files.label")}</label>
              <Input value={label} onChange={(e) => setLabel(e.target.value)} />
            </div>
          </div>
          <div>
            <label className={labelClass}>{t("files.file")}</label>
            <input
              type="file"
              className="block w-full text-sm text-zinc-600 file:mr-3 file:rounded-md file:border-0 file:bg-zinc-100 file:px-3 file:py-2 file:text-sm file:text-zinc-700 dark:text-zinc-300 dark:file:bg-zinc-800 dark:file:text-zinc-200"
              onChange={(e) => setFile(e.target.files?.[0] ?? null)}
            />
            <p className="mt-1 text-xs text-zinc-500">{t("files.hint")}</p>
          </div>

          {error && <p className="text-sm text-red-600">{error}</p>}
          <div className="flex justify-end gap-2">
            <Button type="button" variant="secondary" onClick={onClose}>
              {t("common.cancel")}
            </Button>
            <Button type="submit" disabled={busy || !file}>
              {busy ? t("common.saving") : t("files.upload")}
            </Button>
          </div>
        </form>
      </div>
    </Modal>
  );
}
