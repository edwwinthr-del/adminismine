"use client";

import { useEffect, useLayoutEffect, useRef, useState } from "react";
import { useEscapeLayer } from "@/lib/overlay-layers";

/** Tallest the option list is allowed to be, and the least it will settle for. */
export const MAX_LIST_HEIGHT = 288;
export const MIN_LIST_HEIGHT = 120;

export interface AnchorBox {
  top: number;
  left: number;
  width: number;
  height: number;
}

/**
 * The hard half of a dropdown: keeping a portalled list on its field, closing it
 * on an outside click, and taking Escape without the dialog behind it hearing.
 *
 * Extracted so the searchable field and the plain one cannot drift apart. Both
 * had the same three problems to solve and only one of them had solved them.
 *
 * **Why the list leaves the DOM position of its field.** Inside a modal the
 * field sits in a scrolling box (`overflow-y-auto`) which clipped the list the
 * moment the field was near the bottom — the options were there, cut in half.
 * Drawn from `<body>` it can also flip above the field when there is more room
 * up than down, instead of being squeezed.
 *
 * **The cost, and what pays it.** Having left the field, the list no longer
 * moves with it, and the anchor moves for reasons that fire no event to listen
 * for: a dialog dragged by its header, an error line appearing above it, an
 * async label resolving and rewrapping a row. So it is measured every frame
 * while open — one `getBoundingClientRect` on one element, for as long as a
 * dropdown is on screen — and state is written only when the numbers actually
 * change, so a still field costs no renders.
 */
export function useDropdownAnchor(open: boolean, close: () => void) {
  const containerRef = useRef<HTMLDivElement>(null);
  const listRef = useRef<HTMLUListElement>(null);
  const [box, setBox] = useState<AnchorBox | null>(null);

  useEffect(() => {
    if (!open) return;

    const onPointerDown = (event: MouseEvent) => {
      const target = event.target as Node;

      // The list lives in <body>, so "outside" has to mean outside both parts —
      // otherwise clicking an option closes the field before the click lands on
      // it, and nothing is ever selected.
      if (containerRef.current?.contains(target) || listRef.current?.contains(target)) return;

      close();
    };

    document.addEventListener("mousedown", onPointerDown);

    return () => document.removeEventListener("mousedown", onPointerDown);
  }, [open, close]);

  // Escape closes the list and stops there. It used to reach the dialog behind
  // the field as well, so dismissing a dropdown threw away the form — see
  // lib/overlay-layers.
  useEscapeLayer(open, close);

  useLayoutEffect(() => {
    if (!open) return;

    let frame = 0;

    function place() {
      const anchor = containerRef.current;

      if (anchor) {
        const rect = anchor.getBoundingClientRect();
        const gap = 4;
        const below = window.innerHeight - rect.bottom - gap;
        const above = rect.top - gap;
        const flip = below < Math.min(MAX_LIST_HEIGHT, above) && above > below;
        const height = Math.min(MAX_LIST_HEIGHT, Math.max(flip ? above : below, MIN_LIST_HEIGHT));

        // With room on neither side the floor above wins and the list would
        // hang off the edge it was placed against — the very thing the portal
        // was for. Pinning it inside the window instead lets it overlap the
        // field, which is the lesser of the two.
        const top = flip
          ? Math.max(gap, rect.top - gap - height)
          : Math.max(gap, Math.min(rect.bottom + gap, window.innerHeight - gap - height));

        setBox((current) =>
          current &&
          current.top === top &&
          current.left === rect.left &&
          current.width === rect.width &&
          current.height === height
            ? current
            : { top, left: rect.left, width: rect.width, height },
        );
      }

      frame = requestAnimationFrame(place);
    }

    place();

    return () => cancelAnimationFrame(frame);
  }, [open]);

  return { containerRef, listRef, box };
}

/**
 * The panel's own styling, so both dropdowns look like one control.
 *
 * `vui-menu` rather than the translucent card surface: these are portalled to
 * `<body>` so a blur would in fact work, but a list you can read the page
 * through is a list you misread. The template makes the same call — its card
 * gradient is rgba and its menu gradient is solid.
 */
export const DROPDOWN_PANEL =
  "vui-menu vui-edge scroll-quiet fixed z-[60] overflow-y-auto rounded-[var(--vui-r-xl)] p-1.5";

/** One row in the list. */
export const DROPDOWN_OPTION =
  "flex w-full flex-col items-start gap-0.5 rounded-[var(--vui-r-button)] px-3 py-2 text-left text-sm transition-colors";
