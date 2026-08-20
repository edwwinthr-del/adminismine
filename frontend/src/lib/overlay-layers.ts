"use client";

import { useEffect, useRef } from "react";

/**
 * Who Escape belongs to.
 *
 * Every dismissible layer on screen — a dialog, a dialog opened from inside it,
 * a lookup list drawn into `<body>` — registers here while it is open, and one
 * Escape closes the topmost and nothing else.
 *
 * Each layer keeping its own `document` listener is what made a single keypress
 * shut the dropdown *and* the dialog behind it, throwing away a half-filled
 * form: listeners on the same node all fire regardless of `stopPropagation`
 * (that separates nodes, not listeners), and React's own root handler sits on
 * that same node, so nothing a component does to its synthetic event can hold
 * the keypress back. One listener that knows the order is the only version that
 * can answer "which layer did the user mean".
 */
interface Layer {
  close: () => void;
}

const layers: Layer[] = [];

function onKeyDown(event: KeyboardEvent) {
  if (event.key !== "Escape") return;

  const top = layers[layers.length - 1];

  if (!top) return;

  event.preventDefault();
  top.close();
}

/**
 * Registers a layer for as long as it is open, topmost last.
 *
 * @returns the function that removes it again.
 */
export function pushOverlayLayer(close: () => void): () => void {
  const layer: Layer = { close };

  layers.push(layer);

  if (layers.length === 1) {
    document.addEventListener("keydown", onKeyDown);
  }

  return () => {
    const index = layers.indexOf(layer);

    if (index !== -1) layers.splice(index, 1);

    if (layers.length === 0) {
      document.removeEventListener("keydown", onKeyDown);
    }
  };
}

/**
 * Claims Escape while `active`, giving it back on close.
 *
 * The callback is read at keypress time rather than at registration, so a
 * handler that closes over fresh state is never called in its stale form.
 */
export function useEscapeLayer(active: boolean, close: () => void): void {
  const closeRef = useRef(close);

  useEffect(() => {
    closeRef.current = close;
  });

  useEffect(() => {
    if (!active) return;

    return pushOverlayLayer(() => closeRef.current());
  }, [active]);
}
