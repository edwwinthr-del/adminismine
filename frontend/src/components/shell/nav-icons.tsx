/**
 * Thin outline icons for the sidebar, in the reference design's style: 1.5px
 * stroke, rounded caps, 24px box.
 *
 * Inline SVG rather than an icon package on purpose — this is 26 glyphs used in
 * exactly one place, and a dependency would ship several hundred that nothing
 * imports. They are keyed by the same `nav.*` key the navigation already uses,
 * so adding a destination means adding one entry here beside the one in nav.ts.
 */

const paths: Record<string, string> = {
  "nav.dashboard": "M4 13h6V4H4v9Zm0 7h6v-5H4v5Zm10 0h6v-9h-6v9Zm0-16v5h6V4h-6Z",
  "nav.payables": "M3 7h18M3 12h18M3 17h10M17 17l2 2 4-4",
  "nav.receivables": "M3 7h18M3 12h18M3 17h10m4-2v6m-3-3h6",
  "nav.bank": "M3 10h18M5 10v8m4-8v8m6-8v8m4-8v8M2 21h20M12 3 3 8h18l-9-5Z",
  "nav.exchangeRates": "M4 8h13m0 0-3-3m3 3-3 3M20 16H7m0 0 3-3m-3 3 3 3",
  "nav.workers": "M16 20v-1a4 4 0 0 0-4-4H7a4 4 0 0 0-4 4v1M9.5 11a3.5 3.5 0 1 0 0-7 3.5 3.5 0 0 0 0 7ZM21 20v-1a4 4 0 0 0-3-3.9M16.5 4.1a4 4 0 0 1 0 7.8",
  "nav.salaries": "M12 2v20M17 6.5c0-1.9-2.2-3-5-3s-5 1.1-5 3 2.2 2.6 5 3.2 5 1.4 5 3.3-2.2 3-5 3-5-1.1-5-3",
  "nav.masters": "M12 3 3 8l9 5 9-5-9-5ZM3 14l9 5 9-5M12 13v6",
  "nav.attendance": "M8 3v4m8-4v4M3 10h18M5 6h14a2 2 0 0 1 2 2v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2Zm4.5 9 2 2 4-4",
  "nav.workerNeeds": "M12 21s-7-4.6-9-9a5 5 0 0 1 9-3 5 5 0 0 1 9 3c-2 4.4-9 9-9 9Z",
  "nav.mines": "M3 20h18M6 20l4-9m8 9-4-9m-4 0h4m-2-8v8",
  "nav.projects": "M4 6h6l2 2h8v10a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2Z",
  "nav.worksites": "M3 21h18M5 21V9l7-5 7 5v12M10 21v-6h4v6",
  "nav.mining": "M14 3l7 7-4 4-7-7 4-4ZM10 7 3 14v7h7l7-7",
  "nav.machines": "M4 18h16M6 18v-5l3-2 3 3 3-4 3 3v5M8 8a2 2 0 1 0 0-4 2 2 0 0 0 0 4Z",
  "nav.customs": "M6 3h9l5 5v13H6a1 1 0 0 1-1-1V4a1 1 0 0 1 1-1Zm9 0v5h5M9 13h6m-6 4h6",
  "nav.housing": "M3 11 12 3l9 8M5 10v10h14V10M10 20v-6h4v6",
  "nav.travel": "M2 12h20M12 2c2.5 3 3.5 6.5 3.5 10S14.5 19 12 22c-2.5-3-3.5-6.5-3.5-10S9.5 5 12 2ZM12 2a10 10 0 1 0 0 20 10 10 0 0 0 0-20Z",
  "nav.loans": "M12 22a10 10 0 1 0 0-20 10 10 0 0 0 0 20Zm3-13.5c0-1.4-1.3-2-3-2s-3 .8-3 2 1.3 1.7 3 2.1 3 .9 3 2.1-1.3 2-3 2-3-.6-3-2M12 6v12",
  "nav.imports": "M12 15V3m0 12-4-4m4 4 4-4M4 17v2a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-2",
  "nav.reports": "M4 20V10m5 10V4m5 16v-7m5 7V7",
  "nav.assistant": "M12 3a9 9 0 0 0-9 9c0 1.8.5 3.4 1.4 4.8L3 21l4.4-1.3A9 9 0 1 0 12 3Zm-3 9h.01M12 12h.01M15 12h.01",
  "nav.notifications": "M18 9a6 6 0 1 0-12 0c0 6-3 7-3 7h18s-3-1-3-7M13.7 20a2 2 0 0 1-3.4 0",
  "nav.roles": "M12 3 4 6v6c0 4.4 3.4 8.3 8 9 4.6-.7 8-4.6 8-9V6l-8-3Zm0 6a2 2 0 1 0 0 4 2 2 0 0 0 0-4Zm-3.5 8a3.5 3.5 0 0 1 7 0",
  "nav.auditLogs": "M4 5h16M4 10h16M4 15h9m5.5 5.5L21 23m-2.5-8a3.5 3.5 0 1 0 0 7 3.5 3.5 0 0 0 0-7Z",
  "nav.settings": "M12 15a3 3 0 1 0 0-6 3 3 0 0 0 0 6Zm8.4-3a8.4 8.4 0 0 0-.2-1.7l2-1.6-2-3.4-2.4 1a8.4 8.4 0 0 0-2.9-1.7L14.5 2h-4l-.4 2.6a8.4 8.4 0 0 0-2.9 1.7l-2.4-1-2 3.4 2 1.6a8.4 8.4 0 0 0 0 3.4l-2 1.6 2 3.4 2.4-1a8.4 8.4 0 0 0 2.9 1.7l.4 2.6h4l.4-2.6a8.4 8.4 0 0 0 2.9-1.7l2.4 1 2-3.4-2-1.6c.13-.55.2-1.12.2-1.7Z",
};

const fallback = "M5 12h14";

export function NavIcon({ name, className }: { name: string; className?: string }) {
  return (
    <svg
      viewBox="0 0 24 24"
      fill="none"
      stroke="currentColor"
      strokeWidth={1.5}
      strokeLinecap="round"
      strokeLinejoin="round"
      className={className}
      aria-hidden
    >
      <path d={paths[name] ?? fallback} />
    </svg>
  );
}
