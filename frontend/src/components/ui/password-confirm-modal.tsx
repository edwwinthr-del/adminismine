"use client";

import { useState } from "react";
import { errorMessage } from "@/lib/api";
import { useI18n } from "@/lib/i18n/context";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Modal } from "@/components/ui/modal";

interface PasswordConfirmModalProps {
  title: string;
  /** What is about to be destroyed, in the user's own terms. */
  message: string;
  /** Extra controls shown above the password field (e.g. "also delete the linked movement"). */
  children?: React.ReactNode;
  confirmLabel?: string;
  /** Runs the delete. The password is passed through to the request body. */
  onConfirm: (password: string) => Promise<void>;
  onClose: () => void;
}

/**
 * The gate in front of every delete.
 *
 * It collects the password and hands it to the caller's request rather than
 * verifying anything itself — the check happens on the server, inside the same
 * request that does the deleting (see the backend's ConfirmsPassword). A modal
 * that decided for itself whether the password was right would be a lock whose
 * key is drawn on the door: anyone calling the API directly would skip it.
 *
 * So what this component is responsible for is only the human side — saying what
 * is about to be lost, and showing the server's refusal when it comes.
 */
export function PasswordConfirmModal({
  title,
  message,
  children,
  confirmLabel,
  onConfirm,
  onClose,
}: PasswordConfirmModalProps) {
  const { t } = useI18n();
  const [password, setPassword] = useState("");
  const [error, setError] = useState<string | null>(null);
  const [working, setWorking] = useState(false);

  async function submit(e: React.FormEvent) {
    e.preventDefault();
    setWorking(true);
    setError(null);

    try {
      await onConfirm(password);
      onClose();
    } catch (err) {
      // A wrong password comes back as a 422 on `current_password`; the field
      // is cleared so the next attempt starts from nothing.
      setError(errorMessage(err, t("confirm.failed")));
      setPassword("");
      setWorking(false);
    }
  }

  return (
    <Modal open onClose={onClose} title={title}>
      <form onSubmit={submit} className="space-y-4">
        <p className="rounded-md bg-red-50 px-3 py-2 text-sm text-red-800 dark:bg-red-950/50 dark:text-red-300">
          {message}
        </p>

        {children}

        <div>
          <label className="mb-1 block text-sm font-medium text-zinc-700 dark:text-zinc-300">
            {t("confirm.password")}
          </label>
          <Input
            type="password"
            autoComplete="current-password"
            value={password}
            onChange={(e) => setPassword(e.target.value)}
            required
            autoFocus
          />
          <p className="mt-1 text-xs text-zinc-500">{t("confirm.passwordHint")}</p>
        </div>

        {error && <p className="text-sm text-red-600">{error}</p>}

        <div className="flex justify-end gap-2">
          <Button type="button" variant="secondary" onClick={onClose}>
            {t("common.cancel")}
          </Button>
          <Button type="submit" variant="danger" disabled={working || password === ""}>
            {working ? t("common.saving") : (confirmLabel ?? t("common.delete"))}
          </Button>
        </div>
      </form>
    </Modal>
  );
}
