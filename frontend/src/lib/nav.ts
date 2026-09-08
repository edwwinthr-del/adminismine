export interface NavItem {
  key: string;
  href: string;
  permission: string | null;
  enabled: boolean;
  /**
   * The module this destination belongs to, matching `App\Support\Modules` on
   * the backend. Absent means the core, which is always present — money in,
   * money out, who did it.
   */
  module?: string;
  /**
   * The level of the work structure it belongs to, for the two levels a company
   * may not use. A construction firm organises work project → site and has
   * nothing above the project.
   */
  level?: string;
}

export interface NavGroup {
  key: string;
  items: NavItem[];
}

/**
 * Full information architecture. `enabled` items link to a built page; the rest
 * render as "soon" placeholders and are flipped on as each module ships.
 */
export const NAV: NavGroup[] = [
  {
    key: "navGroup.overview",
    items: [{ key: "nav.dashboard", href: "/dashboard", permission: null, enabled: true }],
  },
  {
    key: "navGroup.finance",
    items: [
      { key: "nav.payables", href: "/payables", permission: "payables.view", enabled: true },
      { key: "nav.receivables", href: "/receivables", permission: "receivables.manage", enabled: true },
      { key: "nav.bank", href: "/bank", permission: "bank_transactions.manage", enabled: true },
      { key: "nav.bankAccounts", href: "/bank-accounts", permission: "bank_transactions.manage", enabled: true },
      { key: "nav.exchangeRates", href: "/exchange-rates", permission: "exchange_rates.manage", enabled: true },
    ],
  },
  {
    key: "navGroup.people",
    items: [
      { key: "nav.workers", href: "/workers", permission: "employees.manage", enabled: true, module: "workers" },
      { key: "nav.salaries", href: "/salaries", permission: "salary_payments.manage", enabled: true, module: "salaries" },
      { key: "nav.masters", href: "/masters", permission: "masters.manage", enabled: true, module: "masters" },
      { key: "nav.attendance", href: "/attendance", permission: "attendance.submit", enabled: true, module: "attendance" },
      { key: "nav.workerNeeds", href: "/worker-needs", permission: "worker_needs.manage", enabled: true, module: "worker_needs" },
    ],
  },
  {
    key: "navGroup.operations",
    items: [
      { key: "nav.mining", href: "/mining", permission: "mining_production.submit", enabled: true, module: "production" },
      { key: "nav.machines", href: "/machines", permission: "machines.manage", enabled: true, module: "machines" },
      { key: "nav.customs", href: "/customs", permission: "customs_documents.manage", enabled: true, module: "customs" },
      // The work structure, coarsest first: the deposit, the job, the site.
      { key: "nav.mines", href: "/mines", permission: "worksites.manage", enabled: true, module: "worksites", level: "mine" },
      { key: "nav.projects", href: "/projects", permission: "worksites.manage", enabled: true, module: "worksites", level: "project" },
      { key: "nav.worksites", href: "/worksites", permission: "worksites.manage", enabled: true, module: "worksites" },
    ],
  },
  {
    key: "navGroup.housing",
    items: [
      { key: "nav.housing", href: "/housing", permission: "housing.manage", enabled: true, module: "housing" },
      { key: "nav.travel", href: "/travel", permission: "travel.manage", enabled: true, module: "travel" },
      { key: "nav.loans", href: "/loans", permission: "loans.manage", enabled: true, module: "loans" },
    ],
  },
  {
    key: "navGroup.system",
    items: [
      { key: "nav.assistant", href: "/assistant", permission: "assistant.use", enabled: true, module: "assistant" },
      { key: "nav.reports", href: "/reports", permission: "reports.view", enabled: true },
      { key: "nav.notifications", href: "/notifications", permission: null, enabled: true },
      { key: "nav.imports", href: "/imports", permission: "imports.manage", enabled: true },
      { key: "nav.roles", href: "/roles", permission: "roles.manage", enabled: true },
      { key: "nav.auditLogs", href: "/audit-logs", permission: "audit_logs.view", enabled: true },
      { key: "nav.settings", href: "/settings", permission: "company.settings.manage", enabled: true },
      { key: "nav.setup", href: "/setup", permission: "company.profile.manage", enabled: true },
    ],
  },
];
