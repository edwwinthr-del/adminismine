"use client";

import { useResource } from "@/lib/data/use-resource";
import { SidenavProvider } from "@/lib/sidenav";
import { Sidebar } from "./sidebar";
import { TerminologyLoader } from "./terminology-loader";
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
    /*
     * The rail's collapsed state is shared: the rail collapses itself, and the
     * navbar's menu button expands it again. Scoped to the shell rather than to
     * the app, because the login screen has no navigation to collapse.
     */
    <SidenavProvider>
      <div className="app-ambient flex min-h-screen">
        {/* Renders nothing; layers the company's own wording over the dictionary. */}
        <TerminologyLoader />
        <Sidebar company={company} />
        <div className="flex min-w-0 flex-1 flex-col">
          <Topbar />
          <main className="flex-1 overflow-y-auto px-4 pb-8 md:px-6">{children}</main>
        </div>
      </div>
    </SidenavProvider>
  );
}
