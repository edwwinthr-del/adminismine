"use client";

import { useState } from "react";
import { apiFetch, errorMessage } from "@/lib/api";
import { useI18n } from "@/lib/i18n/context";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Modal } from "@/components/ui/modal";

/**
 * Changing your own password.
 *
 * Open to everyone who is signed in, whatever their role — the administrative
 * reset on the Users screen is for someone who has *lost* access, and needing an
 * admin for the ordinary case would mean the admin knows everybody's password.
 *
 * The current password is required, and checked on the server: a session left
 * open on an unattended machine must not be enough to take the account over.
 * Other sessions are signed out by the API, since changing a password is how
 * someone reacts to a session they no longer trust.
 */
export function ChangePasswordModal({ onClose }: { onClose: () => void }) {
  const { t } = useI18n();
  const [current, setCurrent] = useState("");
  const [next, setNext] = useState("");
  const [confirmation, setConfirmation] = useState("");
  const [error, setError] = useState<string | null>(null);
  const [done, setDone] = useState(false);
  const [saving, setSaving] = useState(false);

  // Checked here only to spare a round trip; the API confirms it too.
  const mismatch = confirmation !== "" && next !== confirmation;

  async function submit(e: React.FormEvent) {
    e.preventDefault();
    setSaving(true);
    setError(null);

    try {
      await apiFetch("/me/password", {
        method: "PUT",
        json: {
          current_password: current,
          password: next,
          password_confirmation: confirmation,
        },
      });
      setDone(true);
    } catch (err) {
      setError(errorMessage(err));
    } finally {
      setSaving(false);
    }
  }

  const label = "mb-1 block text-sm font-medium text-zinc-700 dark:text-zinc-300";

  return (
    <Modal open onClose={onClose} title={t("account.changePassword")}>
      {done ? (
        <div className="space-y-4">
          <p className="rounded-md bg-green-50 px-3 py-2 text-sm text-green-800 dark:bg-green-950/50 dark:text-green-300">
            {t("account.passwordChanged")}
          </p>
          <div className="flex justify-end">
            <Button type="button" onClick={onClose}>
              {t("common.close")}
            </Button>
          </div>
        </div>
      ) : (
        <form onSubmit={submit} className="space-y-4">
          <div>
            <label className={label}>{t("account.currentPassword")}</label>
            <Input
              type="password"
              autoComplete="current-password"
              value={current}
              onChange={(e) => setCurrent(e.target.value)}
              required
              autoFocus
            />
          </div>
          <div>
            <label className={label}>{t("account.newPassword")}</label>
            <Input
              type="password"
              autoComplete="new-password"
              value={next}
              onChange={(e) => setNext(e.target.value)}
              required
              minLength={8}
            />
          </div>
          <div>
            <label className={label}>{t("account.confirmPassword")}</label>
            <Input
              type="password"
              autoComplete="new-password"
              value={confirmation}
              onChange={(e) => setConfirmation(e.target.value)}
              required
              minLength={8}
            />
            {mismatch && <p className="mt-1 text-xs text-red-600">{t("account.passwordMismatch")}</p>}
          </div>

          <p className="text-xs text-zinc-500">{t("account.changePasswordHint")}</p>

          {error && <p className="text-sm text-red-600">{error}</p>}

          <div className="flex justify-end gap-2">
            <Button type="button" variant="secondary" onClick={onClose}>
              {t("common.cancel")}
            </Button>
            <Button type="submit" disabled={saving || mismatch}>
              {saving ? t("common.saving") : t("common.save")}
            </Button>
          </div>
        </form>
      )}
    </Modal>
  );
}
