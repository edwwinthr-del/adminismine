# Folder structure

```text
global_mine_projekat/
│
├── CLAUDE.md                              # guidance for Claude Code
├── AGENTS.md                              # generic (predates the stack)
├── README.md                              # human setup guide
├── PROJECT_LLM_APP_PROMPT.md              # master spec
├── WORKER_PORTAL_FUTURE_FEATURES.md       # later phase
├── GLOBAL MINE DOO KASA-BANKA HAREKETLERİ-ET2.xlsx   # workbook being replaced
├── docker-compose.yml                     # Postgres :5433 + Redis :6379 (project: adminismine)
├── .idea/                                 # PhpStorm project files
│
├── backend/                               # Laravel 13 API · PHP 8.5 · Sanctum + Spatie
│   ├── app/
│   │   ├── Exceptions/                    # MissingExchangeRateException
│   │   ├── Http/
│   │   │   ├── Controllers/Api/           # 48 controllers (one per module)
│   │   │   ├── Requests/                  # 78 form requests, foldered per domain:
│   │   │   │                              #   Attendance Auth Bank Client Customs Employee
│   │   │   │                              #   Housing Import Loans Machine Master Mine
│   │   │   │                              #   Notifications Payable Production Project
│   │   │   │                              #   Receivable Role Salary Settings Supplier
│   │   │   │                              #   Travel WorkerNeed Worksite
│   │   │   └── Resources/                 # 33 API resources (JSON shaping)
│   │   ├── Jobs/                          # ScanNotificationsJob, SyncExchangeRatesJob
│   │   ├── Models/                        # 43 Eloquent models
│   │   │   └── Concerns/                  # HasAuditColumns, HasFileAttachments,
│   │   │                                  #   Searchable, BelongsToWorkStructure
│   │   ├── Providers/                     # AppServiceProvider (auditColumns macro)
│   │   ├── Reports/                       # Report, TableReport, AgingReport,
│   │   │                                  #   StatementReport, MonthlyCashflowReport,
│   │   │                                  #   ReportRegistry (19 reports)
│   │   ├── Services/                      # business logic — never in controllers
│   │   │   ├── Assistant/                 # AssistantService, Intent, SuggestionValidator
│   │   │   ├── Import/                    # WorkbookImporter, ImportCommitter,
│   │   │   │   └── Parsers/               #   TemplateGenerator/SheetReader + 9 sheet parsers
│   │   │   ├── Notifications/             # Scanner, Dispatcher, Candidate
│   │   │   ├── Reports/                   # ReportExporter (Excel + PDF)
│   │   │   └── *.php                      # CurrencyConverter, DailyEarnedPayService,
│   │   │                                  #   DashboardService, ExchangeRateService,
│   │   │                                  #   FileAttachmentService, InvoiceSettlementService,
│   │   │                                  #   MasterAccessService, RentObligationService,
│   │   │                                  #   SalaryObligationService, SocialAssistanceService,
│   │   │                                  #   WorkingDaysService
│   │   └── Support/                       # LookupRegistry, NotificationTypes,
│   │       └── Import/                    #   SearchTerm, MonthPeriod
│   │                                      #   ImportCatalogue/Column/Entity, LabelNormalizer
│   ├── bootstrap/                         # app.php, providers.php
│   ├── config/                            # 13 configs (permission, activitylog, sanctum…)
│   ├── database/
│   │   ├── factories/                     # 29 factories
│   │   ├── migrations/                    # 38 migrations
│   │   └── seeders/                       # DatabaseSeeder, RolesAndPermissions,
│   │                                      #   NotificationRules
│   ├── public/                            # index.php, favicon, robots
│   ├── resources/                         # css/, js/, views/ (Laravel default shell)
│   ├── routes/                            # api.php ← all endpoints · console.php · web.php
│   ├── storage/app/private/attachments/   # uploads, served only via auth download route
│   └── tests/
│       ├── Feature/                       # 28 feature tests (one per module)
│       └── Unit/
│
└── frontend/                              # Next.js 16 · React 19 · Tailwind v4 · TS
    ├── AGENTS.md / CLAUDE.md              # ← read: Next 16 breaking changes
    ├── next.config.ts · tsconfig.json · eslint.config.mjs · postcss.config.mjs
    ├── public/                            # static svgs
    └── src/
        ├── app/
        │   ├── layout.tsx · page.tsx · globals.css
        │   ├── login/                     # unauthenticated
        │   └── (app)/                     # route group → sidebar shell + auth guard
        │       ├── layout.tsx
        │       └── dashboard/ payables/ receivables/ bank/ workers/ salaries/
        │           mines/ projects/ worksites/ masters/ attendance/ worker-needs/
        │           mining/ machines/ customs/ housing/ travel/ loans/
        │           notifications/ reports/ imports/ assistant/ exchange-rates/
        │           settings/ roles/ audit-logs/        (26 pages, each a page.tsx)
        ├── components/
        │   ├── ui/                        # async-select, badge, button, card, input,
        │   │                              #   modal, pagination, select
        │   ├── shell/                     # app-shell, sidebar, topbar, notification-bell
        │   ├── charts/                    # cashflow-chart (dataviz skill reference impl)
        │   ├── attachments-modal.tsx      # shared, all modules
        │   └── providers.tsx
        └── lib/
            ├── api.ts                     # apiFetch + downloadToDisk; markMutated() on writes
            ├── data/                      # cache.ts · use-resource.ts · use-page.ts
            ├── auth/context.tsx           # token auth
            ├── i18n/                      # context.tsx · dictionaries.ts (en/sr/tr)
            ├── nav.ts · types.ts · format.ts · cn.ts · token.ts
            ├── notifications.ts           # renders language-neutral notification rows
            └── use-debounced-value.ts
```

## Notes

- `frontend/` is a **separate nested git repo** — the root tracks it as a gitlink
  (`160000 a198aed`), and that inner repo only has the initial Next.js scaffold
  committed; the ~40 real source files under `src/` are untracked there.
- `backend/storage/app/private/attachments/{segment}/{id}/` is created at runtime,
  not checked in.
- Counts are of git-tracked files as of 2026-07-30.
