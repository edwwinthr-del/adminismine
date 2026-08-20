"use client";

import { useEffect, useRef, useState } from "react";
import { createPortal } from "react-dom";
import { useEscapeLayer } from "@/lib/overlay-layers";

/** How much of the dialog must stay in view, however far it is dragged. */
const KEEP_VISIBLE = 96;

interface ModalProps {
  open: boolean;
  onClose: () => void;
  title: string;
  children: React.ReactNode;
}

export function Modal({ open, onClose, title, children }: ModalProps) {
  const [position, setPosition] = useState({ x: 0, y: 0 });
  const [dragging, setDragging] = useState(false);

  const dragStart = useRef({ x: 0, y: 0 });
  const startPosition = useRef({ x: 0, y: 0 });
  const dialogRef = useRef<HTMLDivElement>(null);

  // Escape closes the topmost layer only. A dialog opened from inside another
  // one, or a lookup list open over this dialog, used to go down together with
  // it on one keypress — see lib/overlay-layers.
  useEscapeLayer(open, onClose);

  // Reset position whenever a new modal opens.
  useEffect(() => {
    if (open) {
      setPosition({ x: 0, y: 0 });
    }
  }, [open]);

  function handlePointerDown(e: React.PointerEvent<HTMLDivElement>) {
    // Only left mouse button.
    if (e.pointerType === "mouse" && e.button !== 0) return;

    setDragging(true);

    dragStart.current = {
      x: e.clientX,
      y: e.clientY,
    };

    startPosition.current = {
      x: position.x,
      y: position.y,
    };

    e.currentTarget.setPointerCapture(e.pointerId);
  }

  function handlePointerMove(e: React.PointerEvent<HTMLDivElement>) {
    if (!dragging) return;

    const dx = e.clientX - dragStart.current.x;
    const dy = e.clientY - dragStart.current.y;

    setPosition(clampToViewport(startPosition.current.x + dx, startPosition.current.y + dy));
  }

  /**
   * Keeps the dragged dialog reachable.
   *
   * Dragging is unbounded otherwise, and a dialog pulled past the top of the
   * window takes its header — the drag handle and the close button — with it,
   * leaving a form that can only be dismissed by reloading the page. The bounds
   * are measured against the dialog's *untranslated* origin, which is where the
   * flex container centres it, so they hold at any size.
   */
  function clampToViewport(x: number, y: number) {
    const dialog = dialogRef.current;

    if (!dialog) return { x, y };

    const rect = dialog.getBoundingClientRect();
    const originLeft = rect.left - position.x;
    const originTop = rect.top - position.y;

    const between = (value: number, min: number, max: number) =>
      max < min ? min : Math.min(Math.max(value, min), max);

    return {
      x: between(
        x,
        KEEP_VISIBLE - rect.width - originLeft,
        window.innerWidth - KEEP_VISIBLE - originLeft,
      ),
      // Never above the top edge: the header is the only way to drag it back.
      y: between(y, -originTop, window.innerHeight - KEEP_VISIBLE - originTop),
    };
  }

  function handlePointerUp(e: React.PointerEvent<HTMLDivElement>) {
    setDragging(false);

    if (e.currentTarget.hasPointerCapture(e.pointerId)) {
      e.currentTarget.releasePointerCapture(e.pointerId);
    }
  }

  // Rendered into <body>, never in place. A modal opened from inside another
  // one — recording a payment from the settlement dialog — was laid out inside
  // its parent and cut off by it: the parent's drag `transform` makes it the
  // containing block for `position: fixed`, so `inset-0` stopped meaning the
  // viewport, and its `overflow-hidden` clipped whatever did not fit. A portal
  // escapes both, and the later-opened modal still stacks on top because its
  // node is appended after.
  if (!open || typeof document === "undefined") return null;

  return createPortal(
      <div className="fixed inset-0 z-50 flex items-center justify-center p-4">
        {/* Backdrop */}
        <div
            className="absolute inset-0 bg-ink/10 backdrop-blur-[2px]"
            onClick={onClose}
            aria-hidden
        />

        {/* Modal */}
        <div
            ref={dialogRef}
            style={{
              transform: `translate3d(${position.x}px, ${position.y}px, 0)`,
            }}
            className="surface-strong relative z-10 flex max-h-[90vh] w-full max-w-lg flex-col overflow-hidden rounded-[1.75rem]"
        >
          {/* Drag handle / header */}
          <div
              onPointerDown={handlePointerDown}
              onPointerMove={handlePointerMove}
              onPointerUp={handlePointerUp}
              onPointerCancel={handlePointerUp}
              className={`flex shrink-0 select-none items-start justify-between gap-4 p-6 pb-4 ${
                  dragging ? "cursor-grabbing" : "cursor-grab"
              }`}
          >
            <h2 className="text-xl font-medium tracking-tight text-zinc-900 dark:text-zinc-50">
              {title}
            </h2>

            <button
                type="button"
                onClick={onClose}
                onPointerDown={(e) => e.stopPropagation()}
                className="focus-ink -mr-1 -mt-1 grid h-8 w-8 shrink-0 place-items-center rounded-full text-zinc-500 transition-colors hover:bg-zinc-900/5 hover:text-zinc-800 dark:hover:bg-white/10 dark:hover:text-zinc-100"
                aria-label="Close"
            >
              <svg
                  viewBox="0 0 24 24"
                  className="h-4 w-4"
                  fill="none"
                  stroke="currentColor"
                  strokeWidth="2"
              >
                <path strokeLinecap="round" d="M6 6l12 12M18 6L6 18" />
              </svg>
            </button>
          </div>

          {/* Scrollable content */}
          <div className="scroll-quiet min-h-0 overflow-y-auto px-6 pb-6">
            {children}
          </div>
        </div>
      </div>,
      document.body,
  );
}