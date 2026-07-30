"use client";

import { useEffect, useState } from "react";
import { apiFetch } from "@/lib/api";
import { Sidebar } from "./sidebar";
import { Topbar } from "./topbar";

export function AppShell({ children }: { children: React.ReactNode }) {
  const [company, setCompany] = useState("AdminisMine");

  useEffect(() => {
    apiFetch<{ data: { company_name: string } }>("/company-settings")
      .then((res) => setCompany(res.data.company_name))
      .catch(() => {
        /* keep default */
      });
  }, []);

  return (
    <div className="flex min-h-screen">
      <Sidebar company={company} />
      <div className="flex min-w-0 flex-1 flex-col">
        <Topbar />
        <main className="flex-1 overflow-y-auto bg-zinc-50 p-4 md:p-6 dark:bg-zinc-900/40">{children}</main>
      </div>
    </div>
  );
}
