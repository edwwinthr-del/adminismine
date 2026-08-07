"use client";

import { useEffect } from "react";

interface ModalProps {
  open: boolean;
  onClose: () => void;
  title: string;
  children: React.ReactNode;
}

export function Modal({ open, onClose, title, children }: ModalProps) {
  useEffect(() => {
    function onKey(e: KeyboardEvent) {
      if (e.key === "Escape") onClose();
    }
    if (open) document.addEventListener("keydown", onKey);
    return () => document.removeEventListener("keydown", onKey);
  }, [open, onClose]);

  if (!open) return null;

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center p-4">
      {/* Blurred rather than merely dimmed, so the dialog reads as the same
          frosted material as everything under it. */}
      <div
        className="absolute inset-0 bg-ink/25 backdrop-blur-sm"
        onClick={onClose}
        aria-hidden
      />
      <div className="surface-strong relative z-10 max-h-[90vh] w-full max-w-lg overflow-y-auto rounded-[1.75rem] p-6">
        <div className="mb-5 flex items-start justify-between gap-4">
          <h2 className="text-xl font-medium tracking-tight text-zinc-900 dark:text-zinc-50">{title}</h2>
          <button
            onClick={onClose}
            className="focus-ink -mr-1 -mt-1 grid h-8 w-8 shrink-0 place-items-center rounded-full text-zinc-500 transition-colors hover:bg-zinc-900/5 hover:text-zinc-800 dark:hover:bg-white/10 dark:hover:text-zinc-100"
            aria-label="Close"
          >
            <svg viewBox="0 0 24 24" className="h-4 w-4" fill="none" stroke="currentColor" strokeWidth="2">
              <path strokeLinecap="round" d="M6 6l12 12M18 6L6 18" />
            </svg>
          </button>
        </div>
        {children}
      </div>
    </div>
  );
}
