"use client";

import { useResource } from "@/lib/data/use-resource";
import { Sidebar } from "./sidebar";
import { Topbar } from "./topbar";

export function AppShell({ children }: { children: React.ReactNode }) {
  const { data } = useResource<{ data: { company_name: string } }>("/company-settings", {
    keepAlive: true,
  });

  const company = data?.data.company_name ?? "AdminisMine";

  /*
   * `app-ambient` paints the warm wash behind everything (globals.css). The
   * sidebar and main column float on it as frosted panels rather than sitting
   * in framed boxes, so nothing here paints its own background.
   */
  return (
    <div className="app-ambient flex min-h-screen">
      <Sidebar company={company} />
      <div className="flex min-w-0 flex-1 flex-col">
        <Topbar />
        <main className="flex-1 overflow-y-auto px-4 pb-8 md:px-6">{children}</main>
      </div>
    </div>
  );
}
